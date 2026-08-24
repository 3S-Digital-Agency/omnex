#!/usr/bin/env bash
# Exécuter sur le VPS OVH en tant que ROOT ou admin avec sudo :
#   sudo bash infra/server/scripts/omnex-create-admin-deploy.sh
#
# Crée un compte admin-deploy dédié aux correctifs automatisés depuis
# GitHub Actions, sans forced command (contrairement au compte deploy
# qui est verrouillé sur /usr/local/sbin/omnex-deploy).
#
# Le compte admin-deploy peut exécuter les commandes sudo nécessaires
# (chown, chmod, systemctl reload) mais pas de shell interactif.
#
# La clé publique est lue depuis OMNEX_ADMIN_KEY (secret GitHub).

set -euo pipefail

ADMIN_DEPLOY_USER="admin-deploy"
ADMIN_DEPLOY_HOME="/home/${ADMIN_DEPLOY_USER}"
AUTHORIZED_KEYS="${ADMIN_DEPLOY_HOME}/.ssh/authorized_keys"

echo "=== Création du compte ${ADMIN_DEPLOY_USER} ==="

# 1. Créer l'utilisateur (sans shell interactif, juste SSH command)
if id "${ADMIN_DEPLOY_USER}" >/dev/null 2>&1; then
    echo "  ✓ ${ADMIN_DEPLOY_USER} existe déjà"
else
    useradd --create-home --shell /bin/bash "${ADMIN_DEPLOY_USER}"
    echo "  ✓ ${ADMIN_DEPLOY_USER} créé"
fi

# 2. Configurer SSH
install -d -m 700 -o "${ADMIN_DEPLOY_USER}" -g "${ADMIN_DEPLOY_USER}" "${ADMIN_DEPLOY_HOME}/.ssh"

# 3. Injecter la clé publique (à fournir manuellement ou via OMNEX_ADMIN_KEY)
if [[ -n "${OMNEX_ADMIN_PUBKEY:-}" ]]; then
    echo "${OMNEX_ADMIN_PUBKEY}" > "${AUTHORIZED_KEYS}"
    chown "${ADMIN_DEPLOY_USER}:${ADMIN_DEPLOY_USER}" "${AUTHORIZED_KEYS}"
    chmod 600 "${AUTHORIZED_KEYS}"
    echo "  ✓ clé publique injectée depuis OMNEX_ADMIN_PUBKEY"
else
    echo "  ⚠ OMNEX_ADMIN_PUBKEY non définie — ajoute la clé manuellement :"
    echo "    sudo -u ${ADMIN_DEPLOY_USER} nano ${AUTHORIZED_KEYS}"
    echo "    sudo chmod 600 ${AUTHORIZED_KEYS}"
fi

# 4. Sudo restreint : seules les commandes de maintenance
SUDOERS_FILE="/etc/sudoers.d/${ADMIN_DEPLOY_USER}"
cat > "${SUDOERS_FILE}" <<'SUDOERS'
# admin-deploy — correctifs CI automatisés
admin-deploy ALL=(root) NOPASSWD: /usr/bin/chown -R admin:www-data /opt/omnex/frontend/dist
admin-deploy ALL=(root) NOPASSWD: /usr/bin/find /opt/omnex/frontend/dist -type d -exec chmod 755 *
admin-deploy ALL=(root) NOPASSWD: /usr/bin/find /opt/omnex/frontend/dist -type f -exec chmod 644 *
admin-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx
admin-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.2-fpm
admin-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
admin-deploy ALL=(root) NOPASSWD: /usr/local/sbin/omnex-healthcheck
SUDOERS

chmod 440 "${SUDOERS_FILE}"
echo "  ✓ sudoers configuré (restreint)"

echo ""
echo "=== COMPTE ${ADMIN_DEPLOY_USER} PRÊT ==="
echo "Test depuis GitHub Actions :"
echo "  ssh -i omnex-admin-key admin-deploy@omnex.cloud 'sudo systemctl reload nginx'"
echo ""
echo "⚠️  Ajoute la clé privée dans GitHub Secrets → OMNEX_ADMIN_KEY"
echo "   et la clé publique dans ${AUTHORIZED_KEYS} si pas déjà fait."