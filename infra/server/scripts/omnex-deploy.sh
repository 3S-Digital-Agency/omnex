#!/usr/bin/env bash
# ⚠️ TEMPLATE — NON AUTORITAIRE (reconstruit depuis docs/infrastructure-map.md).
# Fichier réel : /usr/local/sbin/omnex-deploy. À RÉCONCILIER avec la machine.
#
# Déploiement bare-metal OMNEX. Invoqué par .github/workflows/deploy-production.yml
# via la commande forcée SSH de l'utilisateur `deploy` :
#   command="sudo /usr/local/sbin/omnex-deploy" ssh-ed25519 AAA... deploy
#
# Séquence attendue (voir docs/rls-rollout.md §5) :
#   1. backup PostgreSQL
#   2. git pull (release main)
#   3. composer install --no-dev --optimize-autoloader
#   4. pnpm build (frontend)
#   5. migrations SUR LA CONNEXION OWNER (--database=pgsql_migrate)
#   6. config:cache / route:cache / view:cache
#   7. reload PHP-FPM + restart workers
#   8. health check final (échec => sortie non nulle)

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/omnex}"
BACKEND_DIR="${APP_DIR}/backend"
FRONTEND_DIR="${APP_DIR}/frontend"
BACKUP_SCRIPT="${BACKUP_SCRIPT:-/usr/local/sbin/omnex-postgres-backup}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.2-fpm}"
HEALTH_URL="${HEALTH_URL:-https://omnex.cloud/api/v1/health}"

log() { printf '[omnex-deploy] %s\n' "$*"; }

# 1. Point de restauration avant toute mutation.
if [[ -x "$BACKUP_SCRIPT" ]]; then
  log 'backup PostgreSQL…'
  "$BACKUP_SCRIPT"
fi

# 2. Code.
cd "$BACKEND_DIR"
log 'git pull…'
git fetch --all --prune
git checkout --force main
git reset --hard origin/main

# 3. Dépendances backend.
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 4. Frontend (build de production).
cd "$FRONTEND_DIR"
pnpm install --frozen-lockfile
pnpm build

# 5. Migrations — rôle OWNER uniquement (omnex_app n'a pas de droit DDL).
#    DB_APP_PASSWORD est lu par env() dans la migration 000047 : l'exporter.
cd "$BACKEND_DIR"
set -a
# shellcheck disable=SC1091
. "$BACKEND_DIR/.env"
set +a
php artisan migrate --force --database=pgsql_migrate

# 6. Cache de production.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Reload / restart.
sudo systemctl reload "$PHP_FPM_SERVICE"
sudo systemctl restart 'omnex-queue@*.service'

# 8. Vérification finale.
log 'health check…'
curl -fsS --max-time 30 "$HEALTH_URL" >/dev/null

log 'déploiement terminé'
