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
│   ├── omnex-deploy.sh           # déploiement bare-metal (push → omnex-deploy)
│   ├── omnex-healthcheck.sh      # sondes de santé (5 min)
│   └── omnex-postgres-backup.sh  # backup PG quotidien (03:30)
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

## Références

- `docs/infrastructure-map.md` — cartographie complète (vérifié vs décrit).
- `docs/rls-rollout.md` — bascule `omnex_app` + `OMNEX_ENFORCE_RLS`.
- `.github/workflows/deploy-production.yml` — déclencheur du déploiement.
