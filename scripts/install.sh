#!/usr/bin/env bash
# One-command setup on the computer that will be the server (Linux, macOS, or Windows via WSL2/Git Bash with Docker Desktop).
#   ./scripts/install.sh            interactive
#   DRY_RUN=1 ./scripts/install.sh  prints the docker commands instead of running them (to check what it would do)
set -euo pipefail
cd "$(dirname "$0")/.."

d() { if [ -n "${DRY_RUN:-}" ]; then echo "+ docker $*"; else docker "$@"; fi; }
say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
ask() { local prompt=$1 default=${2:-} reply; if [ -n "${ANSWERS_YES:-}" ]; then echo "$default"; return; fi; read -r -p "$prompt " reply || true; echo "${reply:-$default}"; }
get_env() { grep -m1 "^$1=" .env 2>/dev/null | cut -d= -f2- || true; }
set_env() { if grep -q "^$1=" .env; then sed -i.bak "s|^$1=.*|$1=$2|" .env && rm -f .env.bak; else printf '%s=%s\n' "$1" "$2" >> .env; fi; }
rand() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 28; }

command -v docker >/dev/null 2>&1 || [ -n "${DRY_RUN:-}" ] || { echo "Docker is not installed. Install Docker Desktop (Windows/macOS) or Docker Engine (Linux) first."; exit 1; }
[ -n "${DRY_RUN:-}" ] || docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 is required (included with Docker Desktop)."; exit 1; }

say "1/6  Settings"
[ -f .env ] || cp .env.production.example .env
current_pw=$(get_env DB_PASSWORD)
if [ -z "$current_pw" ] || [[ "$current_pw" == CHANGE-ME* ]]; then set_env DB_PASSWORD "$(rand)"; echo "  generated a random database password"; fi
host=$(ask "  Name used to open the system on the network [erp.local]:" "erp.local")
set_env ERP_HOST "$host"; set_env APP_URL "https://$host"
set_env APP_ENV production; set_env APP_DEBUG false

say "2/6  Building the application (first time: a few minutes)"
d compose build

say "3/6  Application key"
if [ -z "$(get_env APP_KEY)" ]; then
  if [ -n "${DRY_RUN:-}" ]; then key="base64:DRYRUN"; else key=$(docker compose run --rm --no-deps -T app php artisan key:generate --show | tail -1 | tr -d '\r'); fi
  set_env APP_KEY "$key"; echo "  generated"
fi

say "4/6  Starting the services and creating the database"
d compose up -d db redis
d compose run --rm app php artisan migrate --force
d compose up -d

say "5/6  First administrator"
email=$(ask "  Administrator e-mail [admin@hubalreeh.example]:" "admin@hubalreeh.example")
d compose run --rm app php artisan erp:create-admin "$email"

say "6/6  Sample data (only for trying the system)"
echo "  For REAL work answer N: the system starts empty and ready."
echo "  Y loads 14 months of invented history and demo users to try every screen; remove it later with ./scripts/go-live.sh"
load=$(ask "  Load sample data for a trial? [y/N]:" "N")
if [[ "$load" =~ ^[Yy] ]]; then d compose run --rm app php artisan erp:demo-data --with-users; touch .demo-installed; fi

# the office-network certificate: devices must trust it once
[ -n "${DRY_RUN:-}" ] || { sleep 3; docker compose cp web:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt >/dev/null 2>&1 && echo "  saved ./caddy-root.crt (install it on each device that opens the system)"; }

say "Ready"
echo "  Open:  https://$host   (this computer: add  127.0.0.1 $host  to the hosts file; other devices: the server's address)"
echo "  Over the internet privately: see docs/QUICKSTART.md (Tailscale, two commands)."
echo "  Stop: docker compose down      Start: docker compose up -d"
