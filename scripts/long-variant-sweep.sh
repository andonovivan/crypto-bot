#!/usr/bin/env bash
# Long-strategy variant sweep — Phase 4A of the long-strategy overhaul.
#
# Runs each registered long_* variant in a separate `bot:backtest` invocation,
# captures the stdout summary, parses the per-variant metrics out, and emits
# a single CSV ranking them by composite score.
#
# Usage:
#   scripts/long-variant-sweep.sh                    # default: 2026-03-01 → 2026-04-01, --use-1m
#   scripts/long-variant-sweep.sh --from=2026-04-01 --to=2026-05-01
#   scripts/long-variant-sweep.sh --no-1m            # skip --use-1m (faster, less accurate intra-bar)
#   scripts/long-variant-sweep.sh --variants=long_microdump,long_milddump   # subset
#   scripts/long-variant-sweep.sh --balance=10000    # alternate starting balance
#
# Output dir: storage/perf-snapshots/long-sweep-<UTC-ts>/
#   round-a.csv         — one row per variant with parsed metrics
#   <variant>.log       — full bot:backtest stdout per variant (kept for borderline re-analysis)
#
# IMPORTANT — known limitations of Phase-4A sweep:
#
# 1. Variants share short_scalp's global TP/SL/hold defaults (via Settings
#    aliases). The sweep isolates the ENTRY-signal edge with a common exit
#    profile. Phase 5 promotes the winner with its own per-strategy exits.
#
# 2. HTF filter (1h close > 1h EMA) is OFF for all variants — they fall back
#    to the LongScannerBase default of false because no per-variant KEYS
#    entry exists for `htf_filter_enabled`. Variants whose docstrings claim
#    HTF gating (e.g. long_highpump as long_continuation control) are
#    effectively running WITHOUT HTF in this sweep.
#
# 3. long_btc_aligned and long_btc_inverted need BTCUSDT 4h klines in
#    kline_history. The default download (--intervals=15m,1h,1m) does NOT
#    include 4h. Run before the sweep:
#        ./develop art bot:download-history --symbols=BTCUSDT \
#            --intervals=4h --end-month=2026-03 --months=1 --skip-existing
#    On missing 4h data: long_btc_aligned fails OPEN (emits candidates as
#    if BTC regime were up); long_btc_inverted fails CLOSED (0 candidates).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

DEFAULT_FROM="2026-03-01"
DEFAULT_TO="2026-04-01"
DEFAULT_BALANCE="10000"
USE_1M="--use-1m"
FROM="$DEFAULT_FROM"
TO="$DEFAULT_TO"
BALANCE="$DEFAULT_BALANCE"
VARIANTS=""

for arg in "$@"; do
    case "$arg" in
        --from=*) FROM="${arg#--from=}" ;;
        --to=*) TO="${arg#--to=}" ;;
        --balance=*) BALANCE="${arg#--balance=}" ;;
        --no-1m) USE_1M="" ;;
        --variants=*) VARIANTS="${arg#--variants=}" ;;
        --help|-h)
            grep -E '^# ' "$0" | sed 's/^# //'
            exit 0
            ;;
        *) echo "Unknown arg: $arg" >&2; exit 1 ;;
    esac
done

# Pull the registered long_* variants from the StrategyRegistry. Avoids
# duplicating the list here — if a variant is added or removed from
# config/strategies.php, the sweep picks it up automatically.
if [[ -z "$VARIANTS" ]]; then
    VARIANTS=$(./develop art tinker --execute='
$reg = app(\App\Services\Strategy\StrategyRegistry::class);
$keys = array_filter(
    array_map(fn($s) => $s->key(), $reg->all()),
    fn($k) => str_starts_with($k, "long_")
);
echo implode(",", $keys);
' 2>/dev/null | tail -1 | tr -d '[:space:]')
fi

IFS=',' read -ra VARIANT_LIST <<< "$VARIANTS"
if [[ ${#VARIANT_LIST[@]} -eq 0 ]]; then
    echo "No variants found." >&2
    exit 1
fi

TS=$(date -u +%Y%m%dT%H%M%SZ)
OUT_DIR="storage/perf-snapshots/long-sweep-$TS"
mkdir -p "$OUT_DIR"
CSV="$OUT_DIR/round-a.csv"

echo "variant,trades,wins,losses,wr_pct,rr,net_pnl_usdt,net_pnl_pct,total_fees_usdt,close_take_profit,close_stop_loss,close_expired,runtime_sec,exit_code" > "$CSV"

echo "Sweep window: $FROM → $TO  (balance \$$BALANCE, ${USE_1M:-no 1m})"
echo "Variants (${#VARIANT_LIST[@]}): ${VARIANT_LIST[*]}"
echo "Output: $OUT_DIR"
echo

for variant in "${VARIANT_LIST[@]}"; do
    LOG="$OUT_DIR/$variant.log"
    START_TS=$(date +%s)
    echo "── $variant ────────────────────────────────────────────"

    set +e
    ./develop art bot:backtest --strategies="$variant" \
        --from="$FROM" --to="$TO" \
        $USE_1M --fixed-sizing --truncate \
        --starting-balance="$BALANCE" \
        --override="strategy.$variant.enabled=true" \
        > "$LOG" 2>&1
    EXIT=$?
    set -e

    END_TS=$(date +%s)
    RUNTIME=$((END_TS - START_TS))

    # Parse summary block.
    TRADES=$(grep -oE 'Entries opened:[[:space:]]+[0-9]+' "$LOG" | head -1 | grep -oE '[0-9]+' || echo 0)
    WR_LINE=$(grep -oE 'Win rate:[[:space:]]+[0-9.]+% \([0-9]+/[0-9]+\)' "$LOG" | head -1 || echo "Win rate: 0% (0/0)")
    WR_PCT=$(echo "$WR_LINE" | grep -oE '[0-9.]+%' | head -1 | tr -d '%')
    WINS_OVER=$(echo "$WR_LINE" | grep -oE '\([0-9]+/[0-9]+\)' | tr -d '()')
    WINS="${WINS_OVER%/*}"
    TOTAL="${WINS_OVER#*/}"
    LOSSES=$((TOTAL - WINS))
    NET_PNL=$(grep -oE 'Net P&L:[[:space:]]+\$-?[0-9,.]+' "$LOG" | head -1 | grep -oE '\-?[0-9,.]+' | tr -d ',' || echo 0)
    NET_PNL_PCT=$(grep -oE '\([+-]?[0-9.]+%\)' "$LOG" | head -1 | tr -d '()%')
    RR=$(grep -oE 'R:R realized:[[:space:]]+[0-9.]+' "$LOG" | head -1 | grep -oE '[0-9.]+$' || echo 0)
    FEES=$(grep -oE 'Total fees:[[:space:]]+\$[0-9,.]+' "$LOG" | head -1 | grep -oE '[0-9,.]+' | tr -d ',' || echo 0)
    TP_CT=$(grep -E '^[[:space:]]+take_profit' "$LOG" | head -1 | awk '{print $2}' || echo 0)
    SL_CT=$(grep -E '^[[:space:]]+stop_loss' "$LOG" | head -1 | awk '{print $2}' || echo 0)
    EXP_CT=$(grep -E '^[[:space:]]+expired' "$LOG" | head -1 | awk '{print $2}' || echo 0)

    printf "  trades=%s wr=%s%% rr=%s pnl=\$%s (%s%%) fees=\$%s tp/sl/exp=%s/%s/%s [%ss]\n" \
        "$TRADES" "$WR_PCT" "$RR" "$NET_PNL" "$NET_PNL_PCT" "$FEES" "$TP_CT" "$SL_CT" "$EXP_CT" "$RUNTIME"

    echo "$variant,$TRADES,$WINS,$LOSSES,$WR_PCT,$RR,$NET_PNL,$NET_PNL_PCT,$FEES,$TP_CT,$SL_CT,$EXP_CT,$RUNTIME,$EXIT" >> "$CSV"
done

echo
echo "─── Sweep complete ───"
echo "CSV: $CSV"
echo

# Rank: hard filter trades >= 20 AND wr >= 45%, score = net_pnl × (1 / (1 + abs(min(0, net_pnl_pct)/100)))
# i.e. favor positive return, penalize bigger losses.
echo "Top 7 by net_pnl (within hard filter trades>=20, wr>=45):"
awk -F',' 'NR>1 && $2>=20 && $5>=45 {print}' "$CSV" \
    | sort -t',' -k7 -n -r \
    | head -7 \
    | awk -F',' '{printf "  %-30s trades=%-4s wr=%-6s rr=%-5s pnl=$%-12s (%s%%)\n", $1, $2, $5"%", $6, $7, $8}'

echo
echo "Open log for any variant: less $OUT_DIR/<variant>.log"
