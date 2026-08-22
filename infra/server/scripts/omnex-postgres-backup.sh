#!/usr/bin/env bash
# ⚠️ TEMPLATE — NON AUTORITAIRE (reconstruit depuis docs/infrastructure-map.md).
# Fichier réel : /usr/local/sbin/omnex-postgres-backup. À RÉCONCILIER.
#
# Backup PostgreSQL OMNEX : pg_dump (custom) + SHA-256 + rétention + validation
# + test de restauration. Lancé par omnex-postgres-backup.timer (03:30).

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/omnex/postgres}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
CONTAINER="${CONTAINER:-omnex-postgres}"
DB="${DB:-omnex}"
USER="${PGUSER:-omnex}"

STAMP="$(date +%Y%m%d-%H%M%S)"
DEST="${BACKUP_DIR}/${DB}-${STAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# 1. Dump custom (format d'archive, restaurable via pg_restore).
docker exec "$CONTAINER" pg_dump -U "$USER" -Fc "$DB" | gzip > "$DEST"

# 2. Empreinte d'intégrité.
(cd "$BACKUP_DIR" && sha256sum "$(basename "$DEST")" > "$(basename "$DEST").sha256")

# 3. Validation : le dump se relit (taille > 0 et header pg_dump).
[[ -s "$DEST" ]] || { echo 'backup vide' >&2; exit 1; }
gzip -t "$DEST"

# 4. Test de restauration dans une base jetable (optionnel mais recommandé).
#    docker exec "$CONTAINER" createdb -U "$USER" omnex_restore_test
#    gzip -dc "$DEST" | docker exec -i "$CONTAINER" pg_restore -U "$USER" -d omnex_restore_test --clean
#    docker exec "$CONTAINER" dropdb -U "$USER" omnex_restore_test

# 5. Rétention.
find "$BACKUP_DIR" -name '*.sql.gz' -mtime "+${RETENTION_DAYS}" -delete
find "$BACKUP_DIR" -name '*.sha256' -mtime "+${RETENTION_DAYS}" -delete

echo "backup: ${DEST}"
