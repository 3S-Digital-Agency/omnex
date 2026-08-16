#!/usr/bin/env bash
# NEXUS local development environment — portable, per-user, no admin.
#
# Installs (once):  PHP 8.3 + Composer + PostgreSQL 16 into
#   ${LOCALAPPDATA}/nexus-dev   (outside the repo to keep OneDrive clean)
#
# Usage:
#   source infra/dev-env.sh
#   php -v && composer --version && psql --version
#
# PostgreSQL notes:
#   - Runs on 127.0.0.1:5433 (port 5432 is already used by a system install).
#   - Start:  pg_ctl -D "${NEXUS_DEV}/pgdata" -l "${NEXUS_DEV}/pg-server.log" -o "-p 5433 -h 127.0.0.1" start
#   - Stop:   pg_ctl -D "${NEXUS_DEV}/pgdata" stop
#   - DBs:    nexus (app), nexus_test (tests) — owner: nexus / trust auth.
#   - The `-w` wait flag of pg_ctl hangs on this setup; check the log instead.

export NEXUS_DEV="${LOCALAPPDATA}/nexus-dev"
export COMPOSER_HOME="${NEXUS_DEV}/composer-home"
export PATH="${NEXUS_DEV}/php:${NEXUS_DEV}/pg/pgsql/bin:${PATH}"

php() {
  "${NEXUS_DEV}/php/php.exe" "$@"
}

composer() {
  "${NEXUS_DEV}/php/php.exe" "${NEXUS_DEV}/downloads/composer.phar" "$@"
}

nexus_db_start() {
  "${NEXUS_DEV}/pg/pgsql/bin/pg_ctl.exe" -D "${NEXUS_DEV}/pgdata" \
    -l "${NEXUS_DEV}/pg-server.log" -o "-p 5433 -h 127.0.0.1" start
  sleep 2
  tail -3 "${NEXUS_DEV}/pg-server.log"
}

nexus_db_stop() {
  "${NEXUS_DEV}/pg/pgsql/bin/pg_ctl.exe" -D "${NEXUS_DEV}/pgdata" stop
}
