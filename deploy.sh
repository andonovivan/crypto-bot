#!/usr/bin/env bash
# deploy.sh — push code from laptop to production server and restart the stack.
#
# Usage:
#   ./deploy.sh                                  # uses .deploy.env in repo root
#   DEPLOY_HOST=ubuntu@1.2.3.4 ./deploy.sh       # override host inline
#
# Required env (set in shell or in a .deploy.env file at repo root):
#   DEPLOY_HOST       SSH target, e.g. ubuntu@<static-ip>
#   DEPLOY_KEY        Path to SSH private key (e.g. ~/.ssh/crypto-bot-tokyo.pem)
#   DEPLOY_PATH       Remote project path (default: /home/ubuntu/crypto-bot)
#
# What it does:
#   1. Sanity-checks SSH connectivity
#   2. rsyncs the working tree to the server (excluding secrets, vendor/, logs)
#   3. Rebuilds Docker images from the new source
#   4. Runs migrations against the live DB
#   5. Restarts services with both docker-compose.yml + docker-compose.prod.yml
#   6. Hits /api/health to confirm the stack is alive
#
# What it does NOT touch on the server:
#   - .env (you manage this on the server directly)
#   - storage/logs/, storage/backups/, .deploy.env
#   - The dbdata volume

set -euo pipefail

# ── Config ───────────────────────────────────────────────────────────────────
if [ -f .deploy.env ]; then
  set -a; . .deploy.env; set +a
fi

: "${DEPLOY_HOST:?Set DEPLOY_HOST (e.g. ubuntu@1.2.3.4) in .deploy.env or shell}"
: "${DEPLOY_KEY:?Set DEPLOY_KEY (path to SSH private key) in .deploy.env or shell}"
DEPLOY_PATH="${DEPLOY_PATH:-/home/ubuntu/crypto-bot}"

# Expand ~ in the key path so users can write DEPLOY_KEY=~/.ssh/key.pem
DEPLOY_KEY="${DEPLOY_KEY/#\~/$HOME}"

# ── Helpers ──────────────────────────────────────────────────────────────────
red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
blue()  { printf '\033[34m%s\033[0m\n' "$*"; }
step()  { blue "→ $*"; }
ok()    { green "✓ $*"; }
fail()  { red "✗ $*" >&2; exit 1; }

# ── Pre-flight ───────────────────────────────────────────────────────────────
step "Deploying to ${DEPLOY_HOST}:${DEPLOY_PATH}"

[ -f "$DEPLOY_KEY" ] || fail "SSH key not found: $DEPLOY_KEY"

SSH_OPTS=(-i "$DEPLOY_KEY" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10)

step "Checking SSH connectivity..."
ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" 'echo ok' >/dev/null 2>&1 \
  || fail "Cannot SSH to $DEPLOY_HOST — check DEPLOY_HOST / DEPLOY_KEY / firewall"
ok "SSH works"

# Verify .env exists on the server before touching anything
ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "test -f $DEPLOY_PATH/.env" 2>/dev/null || {
  red "✗ .env not found at $DEPLOY_PATH/.env on the server."
  echo "  First-time setup: SSH in, mkdir -p $DEPLOY_PATH, copy .env.example to .env, fill it in." >&2
  exit 1
}
ok ".env present on server"

# ── Sync code ────────────────────────────────────────────────────────────────
step "Syncing source (excluding vendor/, node_modules/, .env, logs, backups)"
rsync -avz --delete \
  --exclude='.git/' \
  --exclude='node_modules/' \
  --exclude='vendor/' \
  --exclude='.env' \
  --exclude='.env.local' \
  --exclude='.deploy.env' \
  --exclude='storage/logs/' \
  --exclude='storage/backups/' \
  --exclude='storage/framework/cache/' \
  --exclude='storage/framework/sessions/' \
  --exclude='storage/framework/views/' \
  --exclude='*.sql' \
  --exclude='*.log' \
  -e "ssh ${SSH_OPTS[*]}" \
  ./ "$DEPLOY_HOST:$DEPLOY_PATH/"
ok "Source synced"

# ── Build + restart on server ────────────────────────────────────────────────
step "Running build + restart on server..."

ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "DEPLOY_PATH='$DEPLOY_PATH' bash -s" <<'REMOTE'
set -euo pipefail
cd "$DEPLOY_PATH"

# Pin the compose file pair so every subcommand picks both up
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

echo "  • rebuilding images"
"${COMPOSE[@]}" build --quiet

echo "  • migrating database"
"${COMPOSE[@]}" run --rm app php artisan migrate --force

echo "  • restarting services"
"${COMPOSE[@]}" up -d

echo "  • pruning dangling images"
docker image prune -f >/dev/null 2>&1 || true

echo "  • container status:"
"${COMPOSE[@]}" ps
REMOTE
ok "Stack restarted"

# ── Health check (poll briefly to let the app come up) ───────────────────────
step "Waiting for /api/health to respond..."

health_check() {
  ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" \
    "curl -fsS --max-time 5 http://127.0.0.1:8090/api/health" 2>/dev/null
}

attempt=0
max_attempts=12   # 12 × 5s = up to 60s
until health=$(health_check); do
  attempt=$((attempt + 1))
  if [ $attempt -ge $max_attempts ]; then
    red "✗ Health endpoint didn't return 200 within 60s"
    echo "  Inspect logs: ssh $DEPLOY_HOST 'cd $DEPLOY_PATH && docker compose logs --tail=50 app bot'" >&2
    exit 1
  fi
  sleep 5
done

ok "Health check passed:"
echo "$health" | sed 's/^/    /'

echo ""
green "✓ Deploy complete"
