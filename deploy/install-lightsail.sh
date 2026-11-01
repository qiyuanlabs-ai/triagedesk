#!/bin/sh
# Installs or updates TriageDesk on the Lightsail box that already runs the
# Easy!Appointments MCP demo (/opt/ea-mcp-server), next to it and without touching its data:
#
#   sudo sh /opt/triagedesk/deploy/install-lightsail.sh
#
# The first clone (and, for a private repository, the read-only token) is in docs/deploy-docker.md.
#
# What it does, in order. It stops before changing anything if a check fails.
#  1. Checks memory and disk, and adds a 2 GB swap file if the box has no swap.
#  2. Clones or updates the code in /opt/triagedesk and writes .env on the first run.
#  3. Builds and starts TriageDesk (a web and a queue worker container, no host ports).
#  4. Lets the MCP demo's HTTPS container also serve TRIAGEDESK_HOST, restarts only that
#     container, checks the MCP demo still answers, and undoes the change if it does not.
#  5. Resets the demo data every night at 03:30 (server time).
# Safe to run again: it keeps .env (and the API key in it) and the database.
set -e

TRIAGEDESK_HOST="${TRIAGEDESK_HOST:-triagedesk-demo.qiyuanlabs.ai}"
REPO="${REPO:-https://github.com/qiyuanlabs-ai/triagedesk.git}"
DIR=/opt/triagedesk
EA_DIR="${EA_DIR:-/opt/ea-mcp-server}"
OVERRIDE="$DIR/deploy/ea-mcp-proxy.yml"

say() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || fail "run it with sudo."
command -v docker >/dev/null || fail "Docker is not installed."
[ -f "$EA_DIR/.env" ] || fail "$EA_DIR/.env not found. This script expects the Easy!Appointments MCP demo on this box."
EA_HOST="$(sed -n 's/^DEMO_HOST=//p' "$EA_DIR/.env")"
[ -n "$EA_HOST" ] || fail "DEMO_HOST is missing from $EA_DIR/.env."

ea_status() {
  curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$EA_HOST:443:127.0.0.1" "https://$EA_HOST/" || true
}

# 1. Capacity -----------------------------------------------------------------
say "Checking memory and disk"
free -m
docker stats --no-stream --format 'table {{.Name}}\t{{.MemUsage}}' || true
df -h /

if [ "$(awk '/SwapTotal/ {print $2}' /proc/meminfo)" -eq 0 ]; then
  [ "$(df -Pk / | awk 'NR==2 {print $4}')" -gt 6000000 ] || fail "less than 6 GB free disk, so no room for a swap file."
  say "No swap on this box. Adding a 2 GB swap file (/swapfile) so builds cannot run out of memory"
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

avail_mb=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
swapfree_mb=$(awk '/SwapFree/ {print int($2/1024)}' /proc/meminfo)
disk_free_mb=$(df -Pm / | awk 'NR==2 {print $4}')
echo "Available memory: ${avail_mb} MB, free swap: ${swapfree_mb} MB, free disk: ${disk_free_mb} MB"
if [ ! -f "$DIR/.env" ]; then
  # TriageDesk needs about 300 MB while it runs (web + worker) and more while it builds.
  [ "$avail_mb" -ge 450 ] || fail "only ${avail_mb} MB of memory is free. Move this Lightsail instance up to the 4 GB plan first, then run this again."
  [ $((avail_mb + swapfree_mb)) -ge 1500 ] || fail "memory plus swap is under 1.5 GB. Move this Lightsail instance up to the 4 GB plan first, then run this again."
fi
[ "$disk_free_mb" -ge 4000 ] || fail "under 4 GB of free disk. Run 'docker system prune' or grow the disk first."

# 2. Code and settings ------------------------------------------------------
say "Getting the code into $DIR"
command -v git >/dev/null || { apt-get update -q && apt-get install -yq git; }
[ -d "$DIR/.git" ] || git clone "$REPO" "$DIR" \
  || fail "could not clone $REPO. If the repository is private, set the read-only token first (docs/deploy-docker.md)."
cd "$DIR"
git pull --ff-only || fail "could not pull the latest code. If the repository is private, the token may have expired: run deploy/set-github-token.sh."

if [ ! -f .env ]; then
  cp .env.example .env
  sed -i \
    -e "s|^APP_ENV=.*|APP_ENV=production|" \
    -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
    -e "s|^APP_URL=.*|APP_URL=https://$TRIAGEDESK_HOST|" \
    -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
    -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
    -e "s|^DEMO_MODE=.*|DEMO_MODE=true|" \
    .env
  echo "SESSION_SECURE_COOKIE=true" >> .env
fi
chmod 600 .env

# 3. TriageDesk -------------------------------------------------------------
say "Building and starting TriageDesk (the first build takes a few minutes)"
docker network inspect qiyuan-edge >/dev/null 2>&1 || docker network create qiyuan-edge >/dev/null
docker compose build web
docker compose up -d
i=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q web)")" = healthy ]; do
  i=$((i + 1))
  [ "$i" -gt 40 ] && { docker compose logs --tail 50 web; fail "TriageDesk did not become healthy. The MCP demo was not touched."; }
  sleep 3
done
echo "TriageDesk is running."

# 4. HTTPS through the MCP demo's Caddy -----------------------------------------
say "Routing https://$TRIAGEDESK_HOST through the MCP demo's HTTPS container"
sed "s|__TRIAGEDESK_HOST__|$TRIAGEDESK_HOST|" deploy/caddy/Caddyfile.template > deploy/caddy/Caddyfile.new
caddy_changed=0
cmp -s deploy/caddy/Caddyfile.new deploy/caddy/Caddyfile 2>/dev/null || caddy_changed=1

EA_IMAGE="$(cd "$EA_DIR" && docker compose images -q mcp | head -1)"
[ -n "$EA_IMAGE" ] || fail "could not find the MCP demo's image. TriageDesk runs, but is not reachable from outside yet."
# Check the new config with the MCP demo's own image before the running container sees it.
mkdir -p /tmp/triagedesk-caddy-check
cp deploy/caddy/Caddyfile.new /tmp/triagedesk-caddy-check/Caddyfile
docker run --rm --network none --entrypoint frankenphp \
  -e SERVER_NAME="$EA_HOST" -v /tmp/triagedesk-caddy-check:/etc/frankenphp/sites:ro \
  "$EA_IMAGE" validate --config /etc/frankenphp/sites/Caddyfile >/dev/null 2>&1 \
  || { rm -rf /tmp/triagedesk-caddy-check deploy/caddy/Caddyfile.new; fail "the combined HTTPS config did not validate. The MCP demo was not touched."; }
rm -rf /tmp/triagedesk-caddy-check
mv deploy/caddy/Caddyfile.new deploy/caddy/Caddyfile

before="$(ea_status)"
echo "MCP demo answers $before before the change."

cd "$EA_DIR"
compose_files="$(sed -n 's/^COMPOSE_FILE=//p' .env)"
case ":$compose_files:" in
  *":$OVERRIDE:"*) ;;
  *)
    cp .env .env.before-triagedesk
    sed -i "s|^COMPOSE_FILE=.*|COMPOSE_FILE=$compose_files:$OVERRIDE|" .env
    ;;
esac
already_routed=0
case ":$compose_files:" in *":$OVERRIDE:"*) already_routed=1 ;; esac
docker compose up -d --no-deps mcp
# Caddy reads its config only at start, so restart it when only the config file changed.
[ "$already_routed" = 1 ] && [ "$caddy_changed" = 1 ] && docker compose restart mcp

i=0
after=""
while [ "$i" -lt 20 ]; do
  sleep 3
  after="$(ea_status)"
  [ "$after" = "$before" ] && break
  i=$((i + 1))
done
if [ "$after" != "$before" ]; then
  echo "MCP demo answered $after after the change (was $before). Undoing it." >&2
  sed -i "s|^COMPOSE_FILE=.*|COMPOSE_FILE=$compose_files|" .env
  sed -i "s|:$OVERRIDE||" .env
  docker compose up -d --no-deps mcp
  fail "the MCP demo did not come back the same way, so the change was undone. TriageDesk runs, but is not reachable from outside yet."
fi
echo "MCP demo still answers $after."
cd "$DIR"

# 5. Nightly reset ------------------------------------------------------------
cat > /etc/cron.d/triagedesk-demo-reset <<CRON
30 3 * * * root cd $DIR && docker compose exec -T web php artisan migrate:fresh --seed --force >> /var/log/triagedesk-demo-reset.log 2>&1
CRON

say "Done"
if grep -q '^ANTHROPIC_API_KEY=$' .env; then
  echo "Next: add your Anthropic API key (it is typed into this window only, nothing is shown):"
  echo "  sudo bash $DIR/deploy/set-api-key.sh"
fi
echo "Open https://$TRIAGEDESK_HOST once its DNS record points to this server."
echo "The first visit may take a minute while the certificate is issued."
