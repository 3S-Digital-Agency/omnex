#!/usr/bin/env bash
# ⚠️ TEMPLATE — NON AUTORITAIRE (reconstruit depuis docs/infrastructure-map.md).
# Fichier réel : /usr/local/sbin/omnex-healthcheck. À RÉCILIER avec la machine.
#
# Sondes de santé OMNEX. Lancé par omnex-healthcheck.timer (toutes les 5 min).
# Sortie non nulle => alerte (à relier au monitoring).

set -uo pipefail

HEALTH_URL="${HEALTH_URL:-https://omnex.cloud/api/v1/health}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/omnex/postgres}"
FAIL=0

ok()   { printf '[ok]   %s\n' "$*"; }
fail() { printf '[FAIL] %s\n' "$*"; FAIL=1; }

check() { # check <label> <command...>
  local label="$1"; shift
  if "$@" >/dev/null 2>&1; then ok "$label"; else fail "$label"; fi
}

# Processus & services.
check 'nginx'            systemctl is-active --quiet nginx
check 'php-fpm'          systemctl is-active --quiet php8.2-fpm
check 'docker daemon'    docker info
check 'queue @1'         systemctl is-active --quiet 'omnex-queue@1.service'
check 'queue @2'         systemctl is-active --quiet 'omnex-queue@2.service'
check 'scheduler timer'  systemctl is-active --quiet 'omnex-scheduler.timer'

# Données.
check 'postgres'         docker exec omnex-postgres pg_isready -U omnex
check 'redis'            docker exec omnex-redis redis-cli ping

# Endpoint public + API.
curl -fsS --max-time 20 "$HEALTH_URL" >/dev/null 2>&1 && ok 'https omnex.cloud' || fail 'https omnex.cloud'

# TLS : certificat pas expiré dans les 7 prochains jours.
echo | openssl s_client -connect omnex.cloud:443 -servername omnex.cloud 2>/dev/null \
  | openssl x509 -noout -checkend 604800 >/dev/null 2>&1 \
  && ok 'TLS cert' || fail 'TLS cert'

# Disque / RAM (seuils indicatifs — réconcilier avec la machine).
DISK_PCT=$(df --output=pcent / | tail -1 | tr -dc '0-9')
[[ "${DISK_PCT:-100}" -lt 85 ]] && ok "disk ${DISK_PCT}%" || fail "disk ${DISK_PCT}%"

RAM_AVAIL=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
[[ "${RAM_AVAIL:-0}" -gt 1048576 ]] && ok 'ram available >1GB' || fail 'ram available'

# Backup : présence + âge (< 26 h) + checksum.
LATEST=$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -1 || true)
if [[ -z "$LATEST" ]]; then
  fail 'backup: aucun fichier'
else
  AGE_H=$(( ($(date +%s) - $(stat -c %Y "$LATEST")) / 3600 ))
  [[ "$AGE_H" -lt 26 ]] && ok "backup age ${AGE_H}h" || fail "backup age ${AGE_H}h"
  # Le checksum est vérifié par le script de backup (SHA-256) ; re-vérifier ici
  # si le fichier .sha256 est adjacent.
  if [[ -f "${LATEST}.sha256" ]]; then
    (cd "$BACKUP_DIR" && sha256sum -c "$(basename "${LATEST}.sha256")" >/dev/null 2>&1) \
      && ok 'backup checksum' || fail 'backup checksum'
  fi
fi

exit "$FAIL"
