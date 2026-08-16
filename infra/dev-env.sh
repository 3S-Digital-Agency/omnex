#!/usr/bin/env bash
# OMNEX local development environment — portable, per-user, no admin.
#
# Installs (once):  PHP 8.3 + Composer + PostgreSQL 16 into
#   ${LOCALAPPDATA}/omnex-dev   (outside the repo to keep OneDrive clean)
#
# Usage:
#   source infra/dev-env.sh
#   php -v && composer --version && psql --version
#
# PostgreSQL notes:
#   - Runs on 127.0.0.1:5433 (port 5432 is already used by a system install).
#   - Start:  pg_ctl -D "${OMNEX_DEV}/pgdata" -l "${OMNEX_DEV}/pg-server.log" -o "-p 5433 -h 127.0.0.1" start
#   - Stop:   pg_ctl -D "${OMNEX_DEV}/pgdata" stop
#   - DBs:    omnex (app), omnex_test (tests) — owner: omnex / trust auth.
#   - The `-w` wait flag of pg_ctl hangs on this setup; check the log instead.

export OMNEX_DEV="${LOCALAPPDATA}/omnex-dev"
export COMPOSER_HOME="${OMNEX_DEV}/composer-home"
export PATH="${OMNEX_DEV}/php:${OMNEX_DEV}/pg/pgsql/bin:${PATH}"

php() {
  "${OMNEX_DEV}/php/php.exe" "$@"
}

composer() {
  "${OMNEX_DEV}/php/php.exe" "${OMNEX_DEV}/downloads/composer.phar" "$@"
}

omnex_db_start() {
  "${OMNEX_DEV}/pg/pgsql/bin/pg_ctl.exe" -D "${OMNEX_DEV}/pgdata" \
    -l "${OMNEX_DEV}/pg-server.log" -o "-p 5433 -h 127.0.0.1" start
  sleep 2
  tail -3 "${OMNEX_DEV}/pg-server.log"
}

omnex_db_stop() {
  "${OMNEX_DEV}/pg/pgsql/bin/pg_ctl.exe" -D "${OMNEX_DEV}/pgdata" stop
}
