#!/usr/bin/env bash
# Morning-after summary for the Phase-4A long-variant sweep.
#
# Reads the most-recent sweep CSV (or one passed as arg), prints a ranked
# table, splits variants by signal type, and proposes the top 7 for Round B.
#
# Usage:
#   scripts/morning-sweep-summary.sh                      # latest sweep
#   scripts/morning-sweep-summary.sh storage/perf-snapshots/long-sweep-<ts>
#   scripts/morning-sweep-summary.sh --csv path/to/round-a.csv

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

if [[ $# -ge 1 && "$1" != --* ]]; then
    if [[ -d "$1" ]]; then
        CSV="$1/round-a.csv"
        SWEEP_DIR="$1"
    elif [[ -f "$1" ]]; then
        CSV="$1"
        SWEEP_DIR="$(dirname "$1")"
    else
        echo "Not a dir or file: $1" >&2; exit 1
    fi
elif [[ "${1:-}" == "--csv" ]]; then
    CSV="$2"
    SWEEP_DIR="$(dirname "$2")"
else
    # Latest sweep dir
    SWEEP_DIR=$(ls -td storage/perf-snapshots/long-sweep-* 2>/dev/null | head -1 || true)
    if [[ -z "$SWEEP_DIR" ]]; then
        echo "No sweep dir found under storage/perf-snapshots/long-sweep-*" >&2
        exit 1
    fi
    CSV="$SWEEP_DIR/round-a.csv"
fi

if [[ ! -f "$CSV" ]]; then
    echo "CSV not found: $CSV" >&2
    exit 1
fi

ROW_COUNT=$(awk 'NR>1' "$CSV" | wc -l | tr -d ' ')
echo "════════════════════════════════════════════════════════════════════════"
echo " Sweep dir:   $SWEEP_DIR"
echo " CSV:         $CSV"
echo " Variants:    $ROW_COUNT rows"
echo "════════════════════════════════════════════════════════════════════════"
echo

# Full ranked table by net P&L (descending). Negative P&L = "in the red".
echo "─── Ranked by Net P&L (USDT) ───"
printf "  %-30s %6s %6s %6s %5s %5s %12s %8s %8s %5s %5s %5s\n" \
    "variant" "trades" "wins" "losses" "wr%" "rr" "pnl_usdt" "pnl_%" "fees_usdt" "tp" "sl" "exp"
echo "  ──────────────────────────────────────────────────────────────────────────────────────────────────────────"
awk -F',' 'NR>1 {print}' "$CSV" \
    | sort -t',' -k7 -g -r \
    | awk -F',' '{ printf "  %-30s %6s %6s %6s %5s %5s %12s %8s %8s %5s %5s %5s\n", $1, $2, $3, $4, $5"%", $6, $7, $8"%", $9, $10, $11, $12 }'
echo

# Hard-filter cohort: trades >= 20 AND wr >= 45%. These are the variants
# with enough sample size + minimum-quality entries to seed Round B.
echo "─── Hard-filter cohort (trades ≥ 20, wr ≥ 45%) ───"
HARD_FILTER=$(awk -F',' 'NR>1 && $2>=20 && $5+0>=45 {print}' "$CSV")
if [[ -z "$HARD_FILTER" ]]; then
    echo "  (none — relaxing to trades ≥ 10, wr ≥ 40%)"
    HARD_FILTER=$(awk -F',' 'NR>1 && $2>=10 && $5+0>=40 {print}' "$CSV")
fi
echo "$HARD_FILTER" | sort -t',' -k7 -g -r \
    | awk -F',' '{ printf "  %-30s trades=%-5s wr=%-7s rr=%-5s pnl=$%-12s (%s)\n", $1, $2, $5"%", $6, $7, $8"%" }'
echo

# Top 7 for Round B promotion.
echo "─── Top 7 by Net P&L (Round B candidates) ───"
echo "$HARD_FILTER" | sort -t',' -k7 -g -r | head -7 \
    | awk -F',' '{ printf "  %d. %-30s pnl=$%-10s wr=%-6s rr=%-5s trades=%s\n", NR, $1, $7, $5"%", $6, $2 }'
echo

# Group variants by signal-type taxonomy from the plan.
# (Plain-positional pairs instead of associative arrays so macOS bash 3.2 works.)
echo "─── Signal-type breakdown ───"
CATEGORIES="
mean_reversion|long_microdump long_milddump long_bigdump long_extremedump long_oversold_strict
pullback|long_shallowpull long_deeppull long_uptrend_continuation long_consolidation_break
breakout|long_breakout_new_high long_range_reclaim
pump_momentum|long_lowpump long_midpump long_highpump long_extremepump
volume_extreme|long_thinvol_pump long_thickvol_pump
regime_funding|long_negative_funding long_btc_aligned long_btc_inverted
"

echo "$CATEGORIES" | grep -v '^$' | while IFS='|' read -r cat members; do
    grep_pattern=$(echo "$members" | tr ' ' '|')
    rows=$(awk -F',' 'NR>1' "$CSV" | grep -E "^($grep_pattern)," || true)
    if [[ -z "$rows" ]]; then continue; fi
    total_trades=$(echo "$rows" | awk -F',' '{s+=$2} END{print s+0}')
    total_pnl=$(echo "$rows" | awk -F',' '{s+=$7} END{print s+0}')
    avg_wr=$(echo "$rows" | awk -F',' '{s+=$5; n++} END{if(n>0) printf "%.1f", s/n; else print "—"}')
    avg_rr=$(echo "$rows" | awk -F',' '{s+=$6; n++} END{if(n>0) printf "%.2f", s/n; else print "—"}')
    n_members=$(echo "$rows" | wc -l | tr -d ' ')
    printf "  %-18s  variants=%d  total_trades=%-6s  total_pnl=\$%-10s  avg_wr=%s%%  avg_rr=%s\n" \
        "$cat" "$n_members" "$total_trades" "$total_pnl" "$avg_wr" "$avg_rr"
done
echo

# Per-variant log shortcut.
echo "Logs: $SWEEP_DIR/<variant>.log"
echo "Inspect one:    less $SWEEP_DIR/long_microdump.log"
echo "Re-rank by RR:  sort -t',' -k6 -g -r $CSV | column -t -s,"
