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

# The portable PHP's OpenSSL needs a config file (it ships without one). Without
# it, openssl_pkey_new(OPENSSL_KEYTYPE_EC) fails — required by WebAuthn tests.
_omnex_openssl_conf="${OMNEX_DEV}/php/openssl.cnf"
if [ ! -f "${_omnex_openssl_conf}" ]; then
  printf 'openssl_conf = openssl_init\n\n[openssl_init]\nproviders = provider_sect\n\n[provider_sect]\ndefault = default_sect\n\n[default_sect]\nactivate = 1\n' > "${_omnex_openssl_conf}"
fi
export OPENSSL_CONF="${_omnex_openssl_conf}"

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
