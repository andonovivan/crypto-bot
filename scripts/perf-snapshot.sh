#!/usr/bin/env bash
# perf-snapshot.sh — bundle a complete snapshot of the production server's state
# for Claude (or a human) to analyze.
#
# Usage:
#   ./scripts/perf-snapshot.sh                 # default: full log + JSON + market context, truncates server log after capture
#   ./scripts/perf-snapshot.sh --lines=200     # last 200 log lines only; implies --no-clear
#   ./scripts/perf-snapshot.sh --no-clear      # full log but don't truncate server-side
#   ./scripts/perf-snapshot.sh --quick         # skip log fetch entirely; just JSON + market + process state
#   ./scripts/perf-snapshot.sh --keep=10       # rotate local snapshots, keep last 10 (default 30)
#
# Reads creds from .deploy.env (same source as deploy.sh):
#   DEPLOY_HOST   SSH target, e.g. root@1.2.3.4
#   DEPLOY_KEY    Path to SSH private key
#   DEPLOY_PATH   Remote project path (default: /home/ubuntu/crypto-bot)
#
# Writes to storage/perf-snapshots/<UTC-ts>/. On success the dir path is the
# last line on stdout — so callers can capture it with `SNAP=$(./scripts/perf-snapshot.sh | tail -1)`.

set -euo pipefail

# ── Locate repo root ─────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT"

# ── Load deploy env (same as deploy.sh) ──────────────────────────────────────
if [ -f .deploy.env ]; then
  set -a; . .deploy.env; set +a
fi

: "${DEPLOY_HOST:?Set DEPLOY_HOST in .deploy.env or shell}"
: "${DEPLOY_KEY:?Set DEPLOY_KEY in .deploy.env or shell}"
DEPLOY_PATH="${DEPLOY_PATH:-/home/ubuntu/crypto-bot}"
DEPLOY_KEY="${DEPLOY_KEY/#\~/$HOME}"

# ── Parse flags ──────────────────────────────────────────────────────────────
MODE_LOG="full"     # full | lines | quick
LINES=0
DO_CLEAR=1
KEEP=30
FLAGS_RAW="$*"

is_uint() { [[ "$1" =~ ^[0-9]+$ ]]; }

for arg in "$@"; do
  case "$arg" in
    --quick)        MODE_LOG="quick"; DO_CLEAR=0 ;;
    --no-clear)     DO_CLEAR=0 ;;
    --lines=*)
      MODE_LOG="lines"
      LINES="${arg#--lines=}"
      DO_CLEAR=0
      is_uint "$LINES" || { echo "--lines must be a non-negative integer (got: $LINES)" >&2; exit 1; }
      ;;
    --keep=*)
      KEEP="${arg#--keep=}"
      is_uint "$KEEP" || { echo "--keep must be a non-negative integer (got: $KEEP)" >&2; exit 1; }
      ;;
    -h|--help)      sed -n '2,18p' "$0"; exit 0 ;;
    *)              echo "unknown flag: $arg" >&2; exit 1 ;;
  esac
done

# ── Helpers ──────────────────────────────────────────────────────────────────
red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
blue()  { printf '\033[34m%s\033[0m\n' "$*"; }
step()  { blue "→ $*"; }
ok()    { green "✓ $*"; }
fail()  { red "✗ $*" >&2; exit 1; }

[ -f "$DEPLOY_KEY" ] || fail "SSH key not found: $DEPLOY_KEY"

SSH_OPTS=(-i "$DEPLOY_KEY" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10)

ssh_exec() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }

# Curl an /api/* endpoint via the server's dashboard (server-local http)
api() {
  local path="$1"; local out="$2"
  if ! ssh_exec "curl -sS --max-time 15 'http://127.0.0.1:8090$path'" > "$out" 2>/dev/null; then
    red "  ! $path failed (continuing)" >&2
  fi
}

# Curl a Binance public endpoint via the server's network (cloud DNS resolves cleanly)
binance_get() {
  local url="$1"; local out="$2"
  if ! ssh_exec "curl -sS --max-time 15 '$url'" > "$out" 2>/dev/null; then
    red "  ! binance $url failed (continuing)" >&2
  fi
}

COMPOSE_PREFIX="cd $DEPLOY_PATH && docker compose -f docker-compose.yml -f docker-compose.prod.yml"

# ── Pre-flight ───────────────────────────────────────────────────────────────
step "Snapshotting ${DEPLOY_HOST}:${DEPLOY_PATH}"
ssh_exec 'echo ok' >/dev/null 2>&1 || fail "Cannot SSH to $DEPLOY_HOST"

# ── Snapshot dir ─────────────────────────────────────────────────────────────
TS="$(date -u +%Y%m%dT%H%M%SZ)"
SNAP_ROOT="$REPO_ROOT/storage/perf-snapshots"
SNAP_DIR="$SNAP_ROOT/$TS"
mkdir -p "$SNAP_DIR"
step "Snapshot dir: $SNAP_DIR"

# ── Dashboard API ────────────────────────────────────────────────────────────
step "Fetching dashboard API..."
api /api/health                                                "$SNAP_DIR/health.json"
api /api/stats                                                 "$SNAP_DIR/stats.json"
api /api/data                                                  "$SNAP_DIR/data.json"
api /api/trades/aggregates                                     "$SNAP_DIR/aggregates.json"
api /api/settings                                              "$SNAP_DIR/settings.json"
api "/api/trades?per_page=200&sort_by=created_at&sort_dir=desc" "$SNAP_DIR/trades.json"
api "/api/balance-history?range=24h"                           "$SNAP_DIR/balance-24h.json"
api "/api/balance-history?range=7d"                            "$SNAP_DIR/balance-7d.json"
api /api/scanner                                               "$SNAP_DIR/scanner.json"
ok "Dashboard API captured"

# ── Broader market context (Binance public, server-routed) ───────────────────
step "Fetching market context (Binance public)..."
binance_get "https://fapi.binance.com/fapi/v1/ticker/24hr"                                "$SNAP_DIR/market-tickers.json"
binance_get "https://fapi.binance.com/fapi/v1/klines?symbol=BTCUSDT&interval=1h&limit=72" "$SNAP_DIR/market-btc-1h.json"
binance_get "https://fapi.binance.com/fapi/v1/klines?symbol=BTCUSDT&interval=4h&limit=42" "$SNAP_DIR/market-btc-4h.json"
binance_get "https://fapi.binance.com/fapi/v1/premiumIndex"                                "$SNAP_DIR/market-funding.json"
ok "Market context captured"

# ── Process state ────────────────────────────────────────────────────────────
step "Fetching process state..."
ssh_exec "$COMPOSE_PREFIX ps" > "$SNAP_DIR/docker-ps.txt" 2>&1 || true
ssh_exec "$COMPOSE_PREFIX exec -T app php artisan bot:status" > "$SNAP_DIR/bot-status.txt" 2>&1 || true

# Container stdout — Cycle summaries + entry-print + ws health live here, NOT
# in laravel.log. Docker's JSON log driver retains these on its own rotation.
# --tail applies per service, so we get up to ~2000 lines × 5 services.
ssh_exec "$COMPOSE_PREFIX logs --tail=2000 --timestamps --no-color 2>/dev/null" \
  > "$SNAP_DIR/containers-stdout.log" 2>&1 || true

ok "Process state captured"

# ── Log file ─────────────────────────────────────────────────────────────────
LOG_PATH="$DEPLOY_PATH/storage/logs/laravel.log"
LOG_BYTES=0

case "$MODE_LOG" in
  full)
    step "Fetching full server log..."
    ssh_exec "cat $LOG_PATH" > "$SNAP_DIR/laravel.log" 2>/dev/null || true
    LOG_BYTES=$(wc -c < "$SNAP_DIR/laravel.log" | tr -d ' ')
    ok "Captured $LOG_BYTES bytes of laravel.log"
    ;;
  lines)
    step "Fetching last $LINES lines of server log..."
    ssh_exec "tail -n $LINES $LOG_PATH" > "$SNAP_DIR/laravel.log" 2>/dev/null || true
    LOG_BYTES=$(wc -c < "$SNAP_DIR/laravel.log" | tr -d ' ')
    ok "Captured $LOG_BYTES bytes (last $LINES lines)"
    ;;
  quick)
    step "Skipping log fetch (--quick)"
    ;;
esac

# ── Truncate server-side log (safety-gated; full mode + default --clear only) ─
TRUNCATED=0
if [ "$DO_CLEAR" -eq 1 ] && [ "$MODE_LOG" = "full" ]; then
  if [ -s "$SNAP_DIR/laravel.log" ]; then
    step "Truncating server log (zero-truncate preserves container file handles)..."
    ssh_exec ": > $LOG_PATH" && { ok "Server log truncated ($LOG_BYTES bytes archived locally)"; TRUNCATED=1; }
  else
    red "  ! local laravel.log capture is empty — refusing to truncate server log" >&2
  fi
fi

# ── meta.json ────────────────────────────────────────────────────────────────
GIT_SHA=$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)
GIT_BRANCH=$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)
TRUNC_LIT=$([ "$TRUNCATED" -eq 1 ] && echo true || echo false)
CLEAR_LIT=$([ "$DO_CLEAR" -eq 1 ] && echo true || echo false)

cat > "$SNAP_DIR/meta.json" <<META
{
  "captured_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "deploy_host": "$DEPLOY_HOST",
  "deploy_path": "$DEPLOY_PATH",
  "git_sha": "$GIT_SHA",
  "git_branch": "$GIT_BRANCH",
  "flags": "$FLAGS_RAW",
  "log_mode": "$MODE_LOG",
  "log_lines_flag": $LINES,
  "log_bytes": $LOG_BYTES,
  "server_log_truncated": $TRUNC_LIT,
  "do_clear_intent": $CLEAR_LIT
}
META

# ── Rotation ─────────────────────────────────────────────────────────────────
PRUNED=0
while IFS= read -r d; do
  [ -z "$d" ] && continue
  rm -rf "$d"
  PRUNED=$((PRUNED + 1))
done < <(find "$SNAP_ROOT" -mindepth 1 -maxdepth 1 -type d | sort -r | tail -n +$((KEEP + 1)))

if [ "$PRUNED" -gt 0 ]; then
  step "Pruned $PRUNED old snapshot dir(s) (keep=$KEEP)"
fi

# ── Done ─────────────────────────────────────────────────────────────────────
echo
green "✓ Snapshot complete"
echo "$SNAP_DIR"
