# OMNEX / ACELIFE — Infrastructure Map

> Document vivant. Dernière mise à jour : 2026-08-21.
> Objectif : séparer clairement l'infrastructure VPS **partagée**, l'écosystème
> **OMNEX** et l'écosystème **ACELIFE**, et documenter les frontières qui les
> isolent.
>
> Sources : reconnaissance du repository (21/08/2026) + contexte
> d'infrastructure 3S. Deux niveaux de fiabilité sont distingués :
>
> - ✅ **vérifié dans le repo** (`infra/`, `.github/`, `backend/`, `frontend/`, `docs/`)
> - 🖥️ **décrit par le contexte serveur** — hors repo, à confirmer sur la machine

---

## 0. Principe directeur

```text
same infrastructure
different ecosystems
```

OMNEX et ACELIFE partagent le VPS, Nginx, Docker, le réseau, le stockage
physique et le monitoring système. Ils **ne partagent jamais** leur logique
métier, leurs migrations, leurs secrets ou leurs tables applicatives.

---

## 1. Vue d'ensemble

```text
                         INTERNET
                            │
                            ▼
                      OVH VPS
                  Debian 12 Bookworm
              8 vCPU / 24 GB / 200 GB NVMe
                            │
                UFW (incoming deny) + Fail2ban (sshd)
                            │
                          NGINX
              ┌─────────────┴─────────────┐
              ▼                           ▼
      https://omnex.cloud        https://acelife-rp.online
            OMNEX                      ACELIFE
      React + Laravel             FiveM / QBOX
      PostgreSQL + Redis          txAdmin / Tebex
```

---

## 2. Serveur physique

| Élément | Valeur | Fiabilité |
|---|---|---|
| Provider | OVH | 🖥️ |
| OS | Debian 12 Bookworm | 🖥️ |
| Ressources | 8 vCPU · 24 GB RAM · 200 GB NVMe · 8 GB swap | 🖥️ |
| Bande passante | 3 Gbit/s | 🖥️ |
| Timezone système | America/Toronto | 🖥️ |
| Timezone applicative | UTC (`APP_TIMEZONE=UTC`) | ✅ `backend/.env.example` |

---

## 3. Ports et exposition réseau

| Port | Service | Binding | Source | Note |
|---|---|---|---|---|
| 22/tcp | SSH | 0.0.0.0 | 🖥️ | Ed25519 only, `PermitRootLogin no` |
| 80/tcp | HTTP (Nginx) | 0.0.0.0 | 🖥️ | redirige vers 443 |
| 443/tcp | HTTPS (Nginx) | 0.0.0.0 | 🖥️ | Let's Encrypt |
| 30120/tcp+udp | FiveM | 0.0.0.0 | 🖥️ | **ne jamais fermer** |
| 5432/tcp | PostgreSQL | **127.0.0.1** | ✅ `infra/docker-compose.yml` | jamais exposé publiquement |
| 6379/tcp | Redis | **127.0.0.1** | ✅ `infra/docker-compose.yml` | jamais exposé publiquement |
| 5433/tcp | PostgreSQL (dev portable) | 127.0.0.1 | ✅ `infra/dev-env.sh` | dev Windows uniquement |
| `/run/php/php8.2-fpm.sock` | PHP-FPM | socket unix | 🖥️ | prod : pas de port réseau |

---

## 4. Containers Docker

La couche **données** est conteneurisée ; l'**application** OMNEX tourne en
bare-metal (PHP-FPM + Nginx + systemd), pas en conteneur.

| Container | Image | Volume | Ports | Source |
|---|---|---|---|---|
| `omnex-postgres` | `postgres:16-alpine` | `omnex_pg` | `127.0.0.1:5432` | ✅ compose |
| `omnex-redis` | `redis:7-alpine` | `omnex_redis` | `127.0.0.1:6379` | ✅ compose |

- Redis : `appendonly yes` (persistance) ✅ compose.
- Rotation des logs Docker : `max-size 20m`, `max-file 5`, compression ✅ compose.
- ⚠️ Le daemon Docker est **partagé** : toute commande host-wide
  (`docker image prune -f`, redémarrage du daemon) peut impacter ACELIFE.

---

## 5. Services systemd (hors repo)

| Unité | Rôle | Fiabilité |
|---|---|---|
| `omnex-queue@1.service` / `@2.service` | Laravel queue workers (sous `www-data`) | 🖥️ |
| `omnex-scheduler.timer` | `php artisan schedule:run` chaque minute | 🖥️ |
| `omnex-healthcheck.timer` | healthcheck toutes les 5 min | 🖥️ |
| `omnex-postgres-backup.timer` | backup PostgreSQL à 03:30 | 🖥️ |

Tâches Laravel planifiées (✅ `backend/routes/console.php`) :

```text
omnex:check-domain-expirations   quotidien
omnex:billing-renewals           quotidien
omnex:server-snapshots           quotidien
```

---

## 6. Nginx / vhosts / domaines

| Domaine | Canonical | Vhost | TLS | Fiabilité |
|---|---|---|---|---|
| `omnex.cloud` | `https://omnex.cloud` (www → apex) | `sites-available/omnex.cloud` | Let's Encrypt | 🖥️ |
| `acelife-rp.online` | — | `sites-available/acelife-rp.online` | Let's Encrypt (séparé) | 🖥️ |

Routage OMNEX (🖥️) :

```text
/            → React build   (/opt/omnex/frontend/dist)
/api/*       → Laravel       (/opt/omnex/backend/public → PHP-FPM)
/storage/*   → Laravel public storage
```

> `frontend/nginx.conf` (supprimé) était la conf de l'**image** Docker
> frontend, pas le vhost de production.

---

## 7. Bases de données & volumes

| Ressource | Nom | Rôle | Frontière |
|---|---|---|---|
| Base OMNEX | `omnex` | système de vérité OMNEX | **jamais** utilisé par FiveM |
| Volume PG | `omnex_pg` | données PostgreSQL | — |
| Volume Redis | `omnex_redis` | cache / queue / session | — |

En production, Redis porte `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`
et `OMNEX_STREAM_DRIVER` (🖥️ ; les clés sont documentées dans
`backend/.env.example`). Le dev local utilise `database` + port 5433.

---

## 8. Backups & monitoring (hors repo)

| Script | Chemin | Fréquence | Contenu |
|---|---|---|---|
| `omnex-deploy` | `/usr/local/sbin/` | sur push (workflow) | backup PG, `git pull`, composer, pnpm build, migrations, cache, reload PHP-FPM, restart workers, health check |
| `omnex-healthcheck` | `/usr/local/sbin/` | 5 min | nginx, PHP-FPM, Docker, workers, scheduler, PG, Redis, `https://omnex.cloud`, API, TLS, disk, RAM, âge + checksum backup |
| `omnex-postgres-backup` | `/usr/local/sbin/` | 03:30 | `pg_dump` custom + SHA-256 + rétention + validation + test de restauration → `/var/backups/omnex/postgres` (`root:root 700`) |

---

## 9. CI/CD (repo)

| Workflow | Déclencheur | Rôle | État |
|---|---|---|---|
| `ci.yml` | push `main` + PR | DCO, Pest (PostgreSQL 16), audits `composer`/`pnpm`, typecheck + vitest + build | ✅ |
| `deploy-production.yml` | push `main` + dispatch | SSH `deploy@host` → commande forcée `omnex-deploy` → vérif `https://omnex.cloud/` | ✅ |
| `monitoring.yml` | cron `*/15` + dispatch | probe `PRODUCTION_HEALTH_URL`, ouvre/ferme une issue `incident` | ✅ |

> `deploy.yml` (voie Docker « app », Phase 10) est **supprimé** : la production
> est bare-metal, pas conteneurisée. Voir `docs/roadmap.md` Phase 10.

---

## 10. Frontières de sécurité & isolation

- **SSH** : `admin` (clé Ed25519), `deploy` (clé dédiée, commande forcée
  `sudo /usr/local/sbin/omnex-deploy`). `PermitRootLogin no`,
  `PasswordAuthentication no`, `MaxAuthTries 3`.
- **UFW** : `incoming → deny`, `outgoing → allow`. Ports ouverts : 22, 80, 443,
  30120 (tcp+udp). PostgreSQL/Redis restent sur 127.0.0.1.
- **Fail2ban** : jail `sshd`, `maxretry 3`, `findtime 10m`, `bantime 1h`.
- **Secrets** : séparés par écosystème (OMNEX / ACELIFE / GitHub / OAuth /
  providers / Tebex / FiveM / SSH). **Jamais** dans Git, jamais loggés.
- **Multi-tenant OMNEX** : global scope applicatif (`TenantScope`) + RLS
  PostgreSQL (voir §11).
- **Namespaces cibles** :

```text
/opt/omnex   vs   /opt/acelife
omnex-*      vs   acelife-*          (containers, volumes, services)
/var/log/{nginx,omnex,acelife}/…
/var/backups/{omnex,acelife}/…
```

---

## 11. RLS — état réel (reconnaissance 21/08)

- `OMNEX_ENFORCE_RLS` = **false** par défaut ✅ `backend/config/omnex.php`.
- Corrigé : `ResolveTenant` posait `is_local=true` (GUC jamais visible) et
  `clearRlsContext()` effaçait `omnex.*` au lieu de `nexus.*`.
- Migration `000046` : étend RLS aux 26 tables tenant-scoped (Drive, Sites,
  Billing, Cloud, Security, SSL, DNS) avec **échappement système**
  (`nexus_current_tenant() IS NULL`) + **`FORCE ROW LEVEL SECURITY`**.
- Séparation de rôles : migration `000047` provisionne `omnex_app` (LOGIN,
  `NOSUPERUSER`, `NOBYPASSRLS`) avec les grants DML + `ALTER DEFAULT
  PRIVILEGES` ; `config/database.php` ajoute la connexion `pgsql_migrate`
  (owner) pour `php artisan migrate --database=pgsql_migrate`.
- ⚠️ **Reste à faire côté serveur** : basculer `DB_USERNAME=omnex_app` dans le
  `.env` de prod et faire tourner les migrations du script `omnex-deploy` avec
  `--database=pgsql_migrate`. Tant que le runtime tourne en `omnex` (superuser),
  RLS est contourné même avec FORCE.
- Tests : `tests/Unit/RlsMiddlewareTest.php`,
  `tests/Feature/RlsTenantIsolationTest.php` (isolation via rôle limité
  `SET LOCAL ROLE`), `tests/Feature/DatabaseRoleSeparationTest.php`
  (moindre privilège). Voir aussi `docs/security.md`.

---

## 12. Risques de cohabitation OMNEX ↔ ACELIFE

1. **Daemon Docker partagé** — `docker image prune -f` ou un restart global
   impactent ACELIFE.
2. **Ports** — ne jamais fermer 30120 (FiveM) ; garder 5432/6379 sur 127.0.0.1.
3. **Ressources** — builds / batchs / backups OMNEX peuvent saturer le CPU dont
   FiveM est sensible. Aucune limite Docker n'est posée (à observer avant).
4. **Indépendance de panne** — une panne OMNEX ne doit pas tuer FiveM, et une
   maintenance FiveM ne doit pas arrêter PostgreSQL/Redis OMNEX.
5. **Scripts serveur hors repo** — supprimer un fichier du repo ne supprime pas
   un service sur le VPS.

---

## 13. Divergences connues (repo vs serveur)

| Sujet | Repo | Serveur réel |
|---|---|---|
| App prod | Dockerfile backend/frontend (supprimés) | bare-metal PHP-FPM 8.2 + Nginx + systemd |
| Déploiement | `deploy.yml` (supprimé) | `deploy-production.yml` → `omnex-deploy` |
| PHP | `^8.2` (composer) / 8.3 (CI) | 8.2 (prod) |
| RLS | « défense en profondeur » (docs) | off + superuser → inopérant sans séparation de rôle |

---

## 14. Références

- `docs/architecture.md`, `docs/database.md`, `docs/security.md`, `docs/roadmap.md`
- `infra/docker-compose.yml`, `infra/dev-env.sh`
- `.github/workflows/{ci,deploy-production,monitoring}.yml`
