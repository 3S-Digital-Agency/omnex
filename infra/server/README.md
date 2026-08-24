# infra/server — Infrastructure de production (OMNEX + ACELIFE)

> ⚠️ **Statut : TEMPLATES / RÉFÉRENCE — NON AUTORITAIRES.**
>
> Ce dossier a été reconstruit à partir de `docs/infrastructure-map.md`
> (reconnaissance du 21/08/2026). Les **fichiers réels vivent sur le VPS** :
>
> - scripts : `/usr/local/sbin/omnex-{deploy,healthcheck,postgres-backup}`
> - systemd : `/etc/systemd/system/omnex-*`
> - nginx : `/etc/nginx/sites-available/{omnex.cloud,acelife-rp.online}`
> - pare-feu : `/etc/ufw/` et `/etc/fail2ban/`
>
> **Ne déployez jamais un de ces fichiers tel quel** : réconciliez chaque bloc
> avec la machine avant de le considérer exact. Une fois les vrais fichiers
> intégrés, retirez le bandeau « TEMPLATE » du fichier concerné.

## Arborescence

```text
infra/server/
├── README.md               ← ce fichier
├── scripts/
│   ├── omnex-deploy.sh               # déploiement bare-metal (push → omnex-deploy)
│   ├── omnex-fix-perms.sh            # correctif d'urgence permissions frontend/dist
│   ├── omnex-create-admin-deploy.sh  # création compte SSH admin-deploy (CI fixes)
│   ├── omnex-healthcheck.sh          # sondes de santé (5 min)
│   └── omnex-postgres-backup.sh      # backup PG quotidien (03:30)
├── systemd/
│   ├── omnex-queue@.service      # workers Laravel (@1, @2)
│   ├── omnex-scheduler.service   # php artisan schedule:run
│   ├── omnex-scheduler.timer     # chaque minute
│   ├── omnex-healthcheck.service
│   ├── omnex-healthcheck.timer   # toutes les 5 min
│   ├── omnex-postgres-backup.service
│   └── omnex-postgres-backup.timer  # 03:30
├── nginx/
│   ├── omnex.cloud.conf          # SPA + /api → PHP-FPM
│   └── acelife-rp.online.conf    # FiveM / site ACELIFE
└── firewall/
    ├── ufw.rules                 # incoming deny, ports 22/80/443/30120
    └── fail2ban-sshd.local       # jail sshd
```

## Principe directeur

```text
same infrastructure, different ecosystems
```

OMNEX et ACELIFE partagent le VPS, Nginx, Docker et le réseau. Ils ne
partagent **jamais** logique métier, migrations, secrets ou tables.

## Checklist de réconciliation (par fichier)

Pour chaque fichier ci-dessous, avant de le considérer « versionné » :

- [ ] Le chemin, l'utilisateur (`www-data`, `deploy`, `admin`) et les secrets
      correspondent à la machine réelle.
- [ ] Les chemins d'installation (`/opt/omnex/backend`, `/opt/omnex/frontend/dist`,
      socket PHP-FPM, `/opt/acelife`) sont corrects.
- [ ] Aucun secret n'a été commité (mots de passe, clés, tokens) — utiliser des
      placeholders `{{...}}` ou des variables d'environnement.
- [ ] Le bandeau « TEMPLATE » est retiré uniquement après validation.

## Conventions de nommage (frontières OMNEX / ACELIFE)

```text
/opt/omnex    vs   /opt/acelife
omnex-*       vs   acelife-*          (containers, volumes, systemd)
/var/log/{nginx,omnex,acelife}/…
/var/backups/{omnex,acelife}/…
```

## Comptes SSH (accès CI depuis GitHub Actions)

Deux comptes distincts :

- **`deploy`** — forcé sur `/usr/local/sbin/omnex-deploy` (`command="sudo ..."`
  dans `authorized_keys`). C'est le déclencheur normal du déploiement.
  Aucune commande arbitraire n'est possible.
- **`admin-deploy`** — créé par `scripts/omnex-create-admin-deploy.sh`, **sans
  forced command**, mais avec un `sudoers` restreint (chown/chmod sur
  `frontend/dist`, reload nginx/php-fpm, healthcheck). Utilisé pour les
  correctifs post-déploiement (ex. permissions).

### Mise en place d'`admin-deploy` (une seule fois)

```bash
# 1. Générer une paire de clés dédiée (sur ta machine)
ssh-keygen -t ed25519 -f ~/.ssh/omnex-admin -N '' -C 'gh-actions-admin'

# 2. Ajouter la clé PRIVÉE dans GitHub Secrets → OMNEX_ADMIN_KEY
#    (cat ~/.ssh/omnex-admin)

# 3. Sur le VPS, copier le script puis l'exécuter en injectant la clé publique
cd /opt/omnex && git pull
OMNEX_ADMIN_PUBKEY="$(cat ~/.ssh/omnex-admin.pub)" \
  sudo bash infra/server/scripts/omnex-create-admin-deploy.sh

# 4. Tester
ssh -i ~/.ssh/omnex-admin admin-deploy@omnex.cloud \
  'sudo systemctl reload nginx && echo OK'
```

## Références

- `docs/infrastructure-map.md` — cartographie complète (vérifié vs décrit).
- `docs/rls-rollout.md` — bascule `omnex_app` + `OMNEX_ENFORCE_RLS`.
- `.github/workflows/deploy-production.yml` — déclencheur du déploiement.
