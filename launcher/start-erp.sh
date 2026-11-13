#!/usr/bin/env bash
# Linux/macOS: start the ERP and open it. On Linux, copy launcher/hub-alreeh-erp.desktop to ~/.local/share/applications for an icon.
cd "$(dirname "$0")/.."
docker compose up -d
host=$(grep -m1 '^ERP_HOST=' .env | cut -d= -f2-); host=${host:-erp.local}
(xdg-open "https://$host" || open "https://$host") >/dev/null 2>&1
