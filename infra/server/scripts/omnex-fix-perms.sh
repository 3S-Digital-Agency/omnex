#!/usr/bin/env bash
# Exécuter sur le VPS OVH (SSH admin@vps-edc2f396.vps.ovh.ca) :
#   sudo bash /opt/omnex/infra/server/scripts/omnex-fix-perms.sh
#
# Corrige le 500 nginx causé par des permissions manquantes sur
# frontend/dist/ après un pnpm build (user admin, nginx = www-data).

set -euo pipefail

DIST="/opt/omnex/frontend/dist"
BACKEND="/opt/omnex/backend"

echo "=== OMNEX PERMISSION FIX ==="

# 0. Diagnostic rapide
echo "[0/4] Diagnostic..."
echo "  root configuré : $(grep -E '^\s*root' /etc/nginx/sites-enabled/omnex.cloud.conf 2>/dev/null || grep -E '^\s*root' /etc/nginx/sites-available/omnex.cloud.conf 2>/dev/null || echo 'introuvable')"
echo "  dist/index.html : $(ls -la ${DIST}/index.html 2>&1 || echo 'ABSENT')"
echo "[1/3] Correction permissions frontend/dist..."
chown -R admin:www-data "$DIST"
find "$DIST" -type d -exec chmod 755 {} \;
find "$DIST" -type f -exec chmod 644 {} \;
ls -la "$DIST/index.html" && echo "  ✓ index.html accessible"

# 2. Route cache Laravel
echo "[2/3] Route cache..."
cd "$BACKEND"
php artisan route:cache 2>/dev/null && echo "  ✓ routes cached" || echo "  ⚠ route:cache failed (non-bloquant)"

# 3. Recharger services
echo "[3/3] Rechargement nginx + PHP-FPM..."
systemctl reload nginx php8.2-fpm
echo "  ✓ services rechargés"

echo "=== CORRECTIF APPLIQUÉ ==="
echo "Test : curl -s -o /dev/null -w '%{http_code}' https://omnex.cloud/"