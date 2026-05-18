#!/usr/bin/env bash
# Top-3 long-variant backtest harness — promotion gate for the Phase 4A
# sweep winners.
#
# Runs each of the top-3 variants through `bot:backtest-rolling` across a
# multi-month window (default Nov 2025 → May 2026, the window the
# 2026-05-18 walk-forward was validated against) and writes a per-month
# CSV per variant plus a combined summary CSV. Default is --use-1m for
# intra-bar SL/TP precision; flip with --no-1m for a quicker (less
# accurate) sanity pass.
#
# Usage:
#   scripts/long-top3-backtest.sh                                  # default Nov 2025 → May 2026 (6 months)
#   scripts/long-top3-backtest.sh --from=2026-02 --to=2026-05      # shorter 3-month window
#   scripts/long-top3-backtest.sh --no-1m                          # skip 1m mode (faster, less accurate)
#   scripts/long-top3-backtest.sh --variants=long_microdump        # subset
#   scripts/long-top3-backtest.sh --balance=10000                  # alternate starting balance
#   scripts/long-top3-backtest.sh --include-short                  # also run short_scalp as a baseline
#
# Output dir: storage/perf-snapshots/long-top3-<UTC-ts>/
#   round-c-<variant>.csv     — per-month rows from bot:backtest-rolling
#   <variant>.log             — full stdout per variant (for debugging)
#   combined.csv              — one row per (variant, month) for easy diffing
#   summary.txt               — human-readable side-by-side comparison
#
# Top-3 selection (locked in 2026-05-17 from Round A sweep):
#   1. long_microdump      — best raw P&L; -2 to -5% mean-reversion
#   2. long_thinvol_pump   — 1M-5M USDT pump continuation (meme-tier)
#   3. long_lowpump        — +10 to +25% mid-volume pump continuation
#
# IMPORTANT — these variants inherit short_scalp's TP/SL/hold via Settings
# aliases (production-tuned trailing TP 1.5/1.0, fixed SL 2.5%, leverage
# 10). Only the entry signal differs between variants; Phase 5 promotes the
# winner with its own per-strategy exits. See docs/long-strategy-variants.md.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# ── Defaults ─────────────────────────────────────────────────────────────────
# Defaults match what was actually validated in the 2026-05-18 walk-forward
# (Nov 2025 → Apr 2026, 6 months). 2025-10 lacks 15m kline coverage in the
# laptop DB; bump --from back to 2025-10 only after running
# `bot:download-history --intervals=15m --end-month=2025-10 --months=1`.
DEFAULT_FROM="2025-11"
DEFAULT_TO="2026-05"
DEFAULT_BALANCE="10000"
TOP_3="long_microdump,long_thinvol_pump,long_lowpump"

FROM="$DEFAULT_FROM"
TO="$DEFAULT_TO"
BALANCE="$DEFAULT_BALANCE"
USE_1M="--use-1m"
VARIANTS="$TOP_3"
INCLUDE_SHORT=0

for arg in "$@"; do
    case "$arg" in
        --from=*) FROM="${arg#--from=}" ;;
        --to=*) TO="${arg#--to=}" ;;
        --balance=*) BALANCE="${arg#--balance=}" ;;
        --no-1m) USE_1M="" ;;
        --variants=*) VARIANTS="${arg#--variants=}" ;;
        --include-short) INCLUDE_SHORT=1 ;;
        --help|-h)
            grep -E '^# ' "$0" | sed 's/^# //'
            exit 0
            ;;
        *) echo "Unknown arg: $arg" >&2; exit 1 ;;
    esac
done

if [[ "$INCLUDE_SHORT" -eq 1 ]]; then
    VARIANTS="short_scalp,$VARIANTS"
fi

IFS=',' read -ra VARIANT_LIST <<< "$VARIANTS"
if [[ ${#VARIANT_LIST[@]} -eq 0 ]]; then
    echo "No variants to run." >&2
    exit 1
fi

TS=$(date -u +%Y%m%dT%H%M%SZ)
OUT_DIR="storage/perf-snapshots/long-top3-$TS"
mkdir -p "$OUT_DIR"
COMBINED_CSV="$OUT_DIR/combined.csv"
SUMMARY_TXT="$OUT_DIR/summary.txt"

# bot:backtest-rolling's per-month CSV has these columns:
#   month, chunk, trades_closed, wins, losses, win_rate_pct, pnl, fees,
#   wallet_at_end, open_positions_at_end, duration_sec, subprocess_exit
# combined.csv prepends `variant` so cross-variant joins are trivial.
echo "variant,month,chunk,trades_closed,wins,losses,win_rate_pct,pnl,fees,wallet_at_end,open_positions_at_end,duration_sec,subprocess_exit" > "$COMBINED_CSV"

echo "── Top-3 long-variant rolling backtest ──"
echo "Window:    $FROM → $TO  (exclusive on --to)"
echo "Balance:   \$$BALANCE  (fixed-sizing)"
echo "1m mode:   ${USE_1M:-OFF}"
echo "Variants:  ${VARIANT_LIST[*]}"
echo "Output:    $OUT_DIR"
echo

# ── Per-variant run ──────────────────────────────────────────────────────────
for variant in "${VARIANT_LIST[@]}"; do
    LOG="$OUT_DIR/$variant.log"
    PER_MONTH_CSV="$OUT_DIR/round-c-$variant.csv"
    START_TS=$(date +%s)
    echo "═══ $variant ═══"

    # bot:backtest-rolling chunks by month with per-chunk download / cleanup
    # so the kline_history table doesn't have to fit the entire window at once.
    # Each chunk runs bot:backtest as a subprocess for hard memory isolation.
    set +e
    ./develop art bot:backtest-rolling \
        --from="$FROM" --to="$TO" \
        --strategies="$variant" \
        --override="strategy.$variant.enabled=true" \
        $USE_1M --fixed-sizing \
        --download-on-demand --cleanup-prior \
        --starting-balance="$BALANCE" \
        --summary-log="$PER_MONTH_CSV" \
        > "$LOG" 2>&1
    EXIT=$?
    set -e

    END_TS=$(date +%s)
    RUNTIME=$((END_TS - START_TS))

    # The summary-log path resolves inside the container, and
    # storage/perf-snapshots isn't bind-mounted by default (only storage/logs
    # is). Pull the CSV out so the host-side aggregation that follows can
    # actually read it. The bind mount is the recommended fix (see
    # docker-compose.yml app service); this fallback works even on a fresh
    # laptop where containers haven't been recreated to pick up the mount.
    if [[ ! -f "$PER_MONTH_CSV" ]]; then
        docker compose cp "app:/app/$PER_MONTH_CSV" "$PER_MONTH_CSV" 2>/dev/null || true
    fi

    if [[ ! -f "$PER_MONTH_CSV" ]]; then
        echo "  ✗ no per-month CSV produced (exit=$EXIT, runtime=${RUNTIME}s) — see $LOG"
        continue
    fi

    # Re-emit the per-month rows into the combined CSV, prefixing the variant.
    # The format of bot:backtest-rolling's summary CSV is:
    #   month,chunk,trades_closed,wins,losses,win_rate_pct,pnl,fees,
    #   wallet_at_end,open_positions_at_end,duration_sec,subprocess_exit
    # We add `variant` as the leading column.
    tail -n +2 "$PER_MONTH_CSV" \
        | awk -F',' -v v="$variant" '{print v","$0}' \
        >> "$COMBINED_CSV"

    # Per-variant on-screen summary: aggregate the per-month CSV.
    # Columns (1-indexed): month=$1 chunk=$2 trades_closed=$3 wins=$4 losses=$5
    # wr_pct=$6 pnl=$7 fees=$8 wallet_at_end=$9 open_positions=$10 duration=$11 exit=$12
    awk -F',' -v variant="$variant" -v runtime="$RUNTIME" '
        NR > 1 {
            trades += $3
            wins   += $4
            pnl    += $7
            fees   += $8
            if (pnl_min == "" || $7 < pnl_min) { pnl_min = $7; pnl_min_month = $1 }
            if (pnl_max == "" || $7 > pnl_max) { pnl_max = $7; pnl_max_month = $1 }
            if ($7 > 0) positive_months++
            months++
        }
        END {
            wr = trades > 0 ? (100 * wins / trades) : 0
            printf "  months=%d (positive=%d) trades=%d wr=%.1f%% pnl=$%.2f worst=$%.2f@%s best=$%.2f@%s fees=$%.2f [%ds]\n",
                months, positive_months, trades, wr, pnl, pnl_min, pnl_min_month, pnl_max, pnl_max_month, fees, runtime
        }
    ' "$PER_MONTH_CSV"

    echo
done

# ── Side-by-side summary ─────────────────────────────────────────────────────
{
    echo "Top-3 long-variant rolling backtest summary"
    echo "Window: $FROM → $TO   Balance: \$$BALANCE   1m: ${USE_1M:-OFF}"
    echo "Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo
    printf "%-22s  %8s  %7s  %7s  %5s  %14s  %14s  %14s\n" \
        "variant" "trades" "wr%" "months" "pos" "total_pnl" "worst_month" "best_month"
    printf "%-22s  %8s  %7s  %7s  %5s  %14s  %14s  %14s\n" \
        "──────────────────────" "────────" "───────" "───────" "─────" "──────────────" "──────────────" "──────────────"

    # combined.csv columns (1-indexed):
    # variant=$1 month=$2 chunk=$3 trades_closed=$4 wins=$5 losses=$6
    # wr_pct=$7 pnl=$8 fees=$9 wallet_at_end=$10 open=$11 duration=$12 exit=$13
    awk -F',' '
        NR > 1 {
            v = $1
            trades[v]   += $4
            wins[v]     += $5
            pnl[v]      += $8
            months[v]   += 1
            if ($8 > 0) positive[v] += 1
            if (!(v in worst) || $8 < worst[v]) worst[v] = $8
            if (!(v in best)  || $8 > best[v])  best[v]  = $8
        }
        END {
            for (v in trades) {
                wr = trades[v] > 0 ? (100 * wins[v] / trades[v]) : 0
                pos = positive[v] + 0
                printf "%-22s  %8d  %6.1f%%  %7d  %5d  $%13.2f  $%13.2f  $%13.2f\n",
                    v, trades[v], wr, months[v], pos, pnl[v], worst[v], best[v]
            }
        }
    ' "$COMBINED_CSV" | sort -k6 -n -r
} | tee "$SUMMARY_TXT"

echo
echo "── Done ──"
echo "Combined CSV: $COMBINED_CSV"
echo "Summary:      $SUMMARY_TXT"
echo
echo "Promotion gate (from the original plan):"
echo "  1. Positive net P&L in ≥ 5 of N months (where N is months in window)"
echo "  2. Worst-month P&L ≥ -15% of starting balance (≥ -\$$(echo "$BALANCE * 0.15" | bc 2>/dev/null || echo '1500') in this run)"
echo "  3. Average R:R across months ≥ 0.7"
echo "  4. Average WR across months ≥ 50%"
echo "  5. Total trade count ≥ 200"
echo
echo "Per-variant logs are in $OUT_DIR/<variant>.log."
