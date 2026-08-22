# OMNEX — Bascule RLS en production (procédure serveur)

> Objet : faire passer le runtime PostgreSQL du rôle superutilisateur `omnex`
> au rôle moindre-privilège `omnex_app`, puis activer `OMNEX_ENFORCE_RLS=true`
> pour rendre le Row-Level Security effectif.
>
> Références (vérifiées dans le repo) : `config/database.php`
> (connexion `pgsql_migrate`), migration `000047_create_app_runtime_role`
> (provisionne `omnex_app`), migrations `000046`/`000048` (policies RLS
> inconditionnelles), `config/omnex.php` (`enforce_rls`),
> `backend/.env.example`.

---

## 0. Pourquoi c'est nécessaire

Aujourd'hui le runtime se connecte en `omnex`, un **superutilisateur**
(`rolsuper`, `rolbypassrls`). PostgreSQL contourne toujours RLS pour les
superusers — même avec `FORCE ROW LEVEL SECURITY`. Tant que la bascule n'est
pas faite, le RLS reste **inopérant** en production.

Principe de sécurité clé qui rend la bascule sûre et **réversible** :

- Les policies créées par `000046`/`000048` utilisent l'échappement
  `nexus_current_tenant() IS NULL`.
- `ResolveTenant` ne pose le GUC `nexus.tenant_id` **que si**
  `OMNEX_ENFORCE_RLS` est vrai.
- Donc tant que le flag est `false`, `omnex_app` voit **toutes** les lignes
  (GUC absent → `IS NULL` → tout visible). On peut donc basculer le rôle
  **avant** d'activer le flag, sans risque de verrouiller des locataires.

On procède donc en **deux phases** : (A) bascule du rôle, (B) activation du flag.

---

## 1. Prérequis

- [ ] Les commits suivants sont déployés en production (ils sont sur `main`) :
  - `000046`/`000048` — policies RLS inconditionnelles ;
  - `000047` — provisionne `omnex_app` ;
  - `config/database.php` — connexion `pgsql_migrate`.
- [ ] Un accès SSH `admin` (ou `deploy` avec droits suffisants) sur le VPS.
- [ ] Un **backup PostgreSQL frais** a été pris et **validé** (le healthcheck
      vérifie âge + checksum du dernier backup).
- [ ] Fenêtre de maintenance annoncée (opération rapide, mais on touche la
      connexion de prod).

---

## 2. Sauvegarde et point de contrôle

```bash
# 1. Backup manuel immédiat (indépendant du timer 03:30)
sudo /usr/local/sbin/omnex-postgres-backup

# 2. Noter l'état courant (superuser attendu)
sudo -u postgres psql -d omnex -c \
  "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname IN ('omnex','omnex_app');"
```

---

## 3. Générer le mot de passe du rôle `omnex_app`

```bash
# Mot de passe fort (à stocker dans le gestionnaire de secrets, PAS dans Git)
openssl rand -base64 32
```

Ce mot de passe sera utilisé **trois fois** : `DB_PASSWORD` (runtime),
`DB_APP_PASSWORD` (migration `000047`), et jamais loggé.

---

## 4. Mettre à jour `/opt/omnex/backend/.env`

Avant la bascule, adapter le script de déploiement (§5) **puis** éditer `.env` :

```dotenv
# Runtime = rôle moindre-privilège (soumis à RLS)
DB_USERNAME=omnex_app
DB_PASSWORD=<mot de passe omnex_app>

# Migrations = rôle owner (superuser), connexion séparée
DB_MIGRATE_USERNAME=omnex
DB_MIGRATE_PASSWORD=<mot de passe actuel du rôle owner omnex>

# Mot de passe assigné au rôle omnex_app par la migration 000047
DB_APP_PASSWORD=<mot de passe omnex_app>   # identique à DB_PASSWORD
```

> **Important** : `DB_APP_PASSWORD` est lu par `env()` dans la migration
> `000047` (pas via la config cachée). Il doit donc être présent dans
> l'**environnement du processus** qui exécute `php artisan migrate`.

---

## 5. Adapter `/usr/local/sbin/omnex-deploy`

Deux changements obligatoires :

1. Lancer les migrations sur la connexion **owner** (sinon `omnex_app` n'a pas
   le droit de faire du DDL) :

   ```bash
   php artisan migrate --force --database=pgsql_migrate
   ```

2. Exporter `.env` avant `migrate` pour que `DB_APP_PASSWORD` soit visible par
   `env()` (même si `config:cache` est utilisé) :

   ```bash
   set -a
   # shellcheck disable=SC1091
   . /opt/omnex/backend/.env
   set +a
   php artisan migrate --force --database=pgsql_migrate
   ```

> ⚠️ Faire ce changement **avant** de changer `DB_USERNAME` : dès que le
> runtime passe en `omnex_app`, un `migrate` sans `--database=pgsql_migrate`
> échouerait (pas de privilège DDL).

---

## 6. Phase A — basculer le rôle runtime (flag toujours OFF)

```bash
cd /opt/omnex/backend

# 1. Re-lire .env (comportement actuel de omnex-deploy, sinon : config:cache)
#    et appliquer les migrations en owner — provisionne omnex_app + policies
sudo /usr/local/sbin/omnex-deploy
#   (équivalent manuel si pas de déploiement à faire : voir encadré ci-dessous)

# 2. Vérifier le rôle créé : non-superuser, NOBYPASSRLS
sudo -u postgres psql -d omnex -c \
  "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname='omnex_app';"
#   omnex_app | f | f   ← attendu

# 3. Vérifier que la connexion runtime se fait bien en moindre privilège
sudo -u www-data php artisan db:show --json | head -20
#   (doit afficher la base sans erreur d'authentification)

# 4. Santé
curl -fsS https://omnex.cloud/api/v1/health
sudo systemctl status omnex-queue@1.service omnex-queue@2.service --no-pager
```

**Équivalent manuel des migrations (sans déploiement) :**

```bash
cd /opt/omnex/backend
set -a; . ./.env; set +a
sudo -u www-data php artisan migrate --force --database=pgsql_migrate
sudo -u www-data php artisan config:cache
sudo systemctl restart omnex-queue@1.service omnex-queue@2.service
sudo systemctl reload php8.2-fpm   # ou le service PHP-FPM de prod
```

---

## 7. Phase A — vérifications fonctionnelles (flag OFF)

Sans le flag, `omnex_app` voit tout via `IS NULL`. Vérifier que **rien ne
casse** avant d'activer RLS :

- [ ] Connexion/déconnexion OK (login, passkey si activé).
- [ ] Dashboard `/overview` se charge (KPIs, activité, notifications).
- [ ] Chaque module : Domaines, Sites, Cloud, Stockage, Facturation, Sécurité.
- [ ] Une écriture : créer/modifier/supprimer un enregistrement (ex. un dossier
      Drive ou un enregistrement DNS).
- [ ] Jobs cron passent : `php artisan schedule:run` (ou attendre le timer) sans
      erreur — les jobs cross-tenant utilisent l'échappement `IS NULL`.
- [ ] Logs `laravel.log` et journaux systemd sans erreur d'authentification PG.

---

## 8. Phase B — activer RLS

```bash
# 1. Activer le flag dans .env
#    OMNEX_ENFORCE_RLS=true

cd /opt/omnex/backend
sudo -u www-data php artisan config:cache

# 2. Redémarrer les workers (rechargent la config + le GUC par requête)
sudo systemctl restart omnex-queue@1.service omnex-queue@2.service
sudo systemctl reload php8.2-fpm
```

---

## 9. Phase B — vérifications RLS

- [ ] Connexion avec **deux organisations différentes** (deux comptes) : chaque
      utilisateur ne voit que ses propres données.
- [ ] Le dashboard et chaque module restent fonctionnels pour les deux comptes.
- [ ] Un job cron cross-tenant tourne toujours (ex. `omnex:billing-renewals
      --dry-run`, `omnex:check-domain-expirations`) — l'échappement système doit
      leur laisser tout voir.
- [ ] Vérification SQL directe du comportement de la policy :

```bash
sudo -u postgres psql -d omnex <<'SQL'
-- Les policies sont en place et FORCE est actif sur les tables tenant-scoped
SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity
FROM pg_class c
WHERE c.relname IN ('domains','sites','servers','drive_files','subscriptions')
ORDER BY c.relname;
SQL
```

- [ ] `/api/v1/health` vert, monitoring `monitoring.yml` vert.
- [ ] Aucun 403/500 massif dans les logs pendant ~30 min.

---

## 10. Checklist récapitulative

- [ ] Backup frais validé (âge + checksum).
- [ ] `omnex-deploy` migre via `--database=pgsql_migrate` **et** exporte `.env`.
- [ ] `.env` : `DB_USERNAME=omnex_app`, `DB_MIGRATE_USERNAME=omnex`,
      `DB_APP_PASSWORD` renseigné.
- [ ] `omnex_app` vérifié `rolsuper=f`, `rolbypassrls=f`.
- [ ] Phase A validée (flag OFF, toutes les fonctionnalités OK).
- [ ] `OMNEX_ENFORCE_RLS=true` + `config:cache` + restart workers/PHP-FPM.
- [ ] Phase B validée (isolation 2 locataires + jobs cron cross-tenant OK).

---

## 11. Rollback

Le rollback le plus rapide est le **flag** (reste le plus sûr) :

```bash
# 1. Désactiver RLS : les GUC ne sont plus posés, omnex_app voit tout (IS NULL)
cd /opt/omnex/backend
#    .env → OMNEX_ENFORCE_RLS=false
sudo -u www-data php artisan config:cache
sudo systemctl restart omnex-queue@1.service omnex-queue@2.service
sudo systemctl reload php8.2-fpm
```

Si le problème vient du rôle lui-même (ex. mot de passe), revenir au owner :

```bash
# .env → DB_USERNAME=omnex (et DB_PASSWORD = mot de passe owner)
sudo -u www-data php artisan config:cache
sudo systemctl restart omnex-queue@1.service omnex-queue@2.service
sudo systemctl reload php8.2-fpm
```

> Ne pas supprimer le rôle `omnex_app` : il est idempotent et ré-utilisé ; la
> migration `000047` `down()` est volontairement conservatrice (ne drop pas le
> rôle). Un rollback est donc toujours un simple retour de variable `.env`.

---

## 12. Surveillance post-bascule

- Suivre `monitoring.yml` (probe toutes les 15 min) pendant 24 h.
- Surveiller les logs applicatifs pour toute erreur
  `permission denied for table` / `policy` anormale.
- Garder le flag activable/désactivable par variable uniquement — aucun code à
  déployer pour revenir en arrière.
