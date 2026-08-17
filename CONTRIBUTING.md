# Contribuer à OMNEX

> Merci de vouloir contribuer à OMNEX ! Ce guide décrit **comment** contribuer
> (parcours, conventions, CI) et **à quelles conditions** (DCO, licence). Il
> accompagne la constitution publique de la gouvernance
> ([`GOVERNANCE.md`](GOVERNANCE.md)) et la matrice des licences
> ([`docs/licensing.md`](docs/licensing.md)).

---

## 1. Avant de commencer

### 1.1 Code de conduite

Toute interaction (issues, PR, revues, discussions) est régie par un Code de
Conduite de type Contributor Covenant, appliqué par les mainteneurs. En
participant, vous vous engagez à le respecter : bienveillance, critique
constructive, zéro harcèlement.

### 1.2 Choix d'une issue

- **Bug** : ouvrez une issue avec la version concernée, les étapes de
  reproduction, le comportement attendu vs observé, et les logs si possible.
- **Feature** : ouvrez d'abord une issue pour discuter du périmètre avant de
  coder. Les décisions importantes passent par le processus de la
  [gouvernance](GOVERNANCE.md#3-processus-de-décision).
- **Sécurité** : **ne publiez jamais** une vulnérabilité dans une issue
  publique. Écrivez à `security@omnex.cloud` (voir
  [`docs/security.md`](docs/security.md)).

Reprenez une issue existante en la commentant (« je m'en occupe ») pour éviter
les doublons.

---

## 2. Mécanisme DCO (Developer Certificate of Origin)

OMNEX utilise le **DCO** comme mécanisme de contribution par défaut
(GOVERNANCE.md §5.2). Chaque contribution doit attester, via une ligne
`Signed-off-by` sur **chaque commit**, que vous avez le droit de la soumettre.

### 2.1 Le certificat

En signant, vous attestez :

> Developer Certificate of Origin, Version 1.1
>
> En contribuant à ce projet, je certifie que :
> (a) La contribution a été créée en totalité ou en partie par moi et j'ai le
>     droit de la soumettre sous la licence open source indiquée dans le
>     projet ; ou
> (b) La contribution est fondée sur un travail antérieur qui, au mieux de ma
>     connaissance, est couvert par une licence open source appropriée et j'ai
>     le droit de soumettre ce travail avec des modifications, qu'elles soient
>     en totalité ou en partie créées par moi, sous la même licence open
>     source (sauf si j'ai l'autorisation d'utiliser une autre licence) ; ou
> (c) La contribution m'a été fournie directement par une personne qui a fait
>     la certification (a), (b) ou (c), et je ne l'ai pas modifiée ; ou
> (d) J'ai compris et j'accepte que ce projet et la contribution sont publics
>     et qu'un enregistrement de la contribution (y compris toutes les
>     informations personnelles que j'y ai incluses et que je signe) est
>     conservé indéfiniment et peut être redistribué conformément à ce projet
>     ou à la licence open source concernée.

### 2.2 Signer ses commits

```bash
git commit -s        # signe le commit en cours
git commit -s -m "feat: ajoute le provider X"
```

La ligne ajoutée automatiquement a la forme :

```
Signed-off-by: Prénom Nom <email@exemple.com>
```

Elle doit correspondre à l'identité Git configurée (`user.name` /
`user.email`). Vérifiez votre configuration :

```bash
git config user.name && git config user.email
```

### 2.3 Corriger un commit non signé

```bash
git rebase --signoff HEAD~N   # signe les N derniers commits de la branche
# puis force-push sur VOTRE branche de PR uniquement
git push --force-with-lease origin ma-branche
```

> ⚠️ Le force-push est réservé à votre propre branche de pull request, jamais
> sur `main` ni sur une branche partagée.

### 2.4 DCO sur une PR

La CI vérifie que **chaque commit** de la PR porte la ligne `Signed-off-by`
(vérification automatisée, voir §6). Une PR dont un commit n'est pas signé
est bloquée jusqu'à correction. Si vous ne pouvez pas signer (contribution
pour compte d'une entité), contactez les mainteneurs : un **CLA** pourra être
exigé à la place (GOVERNANCE.md §5.2, §7).

---

## 3. Parcours d'une contribution

1. **Issue** : ouvrez ou reprenez une issue.
2. **Branche** : travaillez sur une branche dédiée, jamais directement sur
   `main`.
   ```bash
   git checkout -b feat/ma-contribution
   ```
3. **Commits** : commits atomiques, signés (`-s`), messages normalisés (§4).
4. **Tests** : ajoutez/mettez à jour les tests ; la CI doit passer (§6).
5. **Pull request** : ouvrez la PR avec le [template](.github/pull_request_template.md)
   rempli.
6. **Revue** : répondez aux retours, force-push propre sur votre branche.
7. **Merge** : un mainteneur fusionne (squash recommandé). Les changements
   d'interface publique (contrats de provider, API, protocoles) exigent une
   revue du noyau (GOVERNANCE.md §3.1).

---

## 4. Conventions

### 4.1 Messages de commit

Type + portée + description impérative :

```
feat(cloud): ajoute le provider Hetzner derrière ServerProviderInterface

Description détaillée du pourquoi et du comment.

Signed-off-by: Prénom Nom <email@exemple.com>
```

Types utilisés : `feat`, `fix`, `refactor`, `docs`, `test`, `chore`,
`perf`, `security`. Portées fréquentes : `auth`, `cloud`, `domains`,
`dns`, `storage`, `sites`, `billing`, `security`, `marketing`, `ui`,
`backend`, `frontend`, `ci`, `docs`.

### 4.2 Style

- **Backend** : Laravel + Pest ; respectez le style (exécutez `./vendor/bin/pint`).
- **Frontend** : React + TypeScript + Vite ; `pnpm typecheck` sans erreur,
  conventions du projet (composants, i18n via `src/lib/i18n.ts`, API via
  `src/lib/api`).
- **i18n** : toute nouvelle chaîne d'interface doit exister en **EN et FR**
  dans `src/lib/i18n.ts`.
- **Tests** : couvrez le comportement modifié (Pest côté backend, Vitest
  côté frontend). Une feature sans test n'est pas fusionnée.
- **Docs** : les changements d'interface publique doivent mettre à jour
  `docs/` et le cas échéant la roadmap (`docs/roadmap.md`).

---

## 5. Architecture & interfaces

- Les intégrations de fournisseurs passent par les **contrats d'interface**
  (`DomainProviderInterface`, `ServerProviderInterface`,
  `StorageProviderInterface`, …) — jamais par du code fournisseur dispersé.
- Les contrats sont des **protocoles publics** sous Apache 2.0
  (docs/licensing.md §3) : toute modification est un changement d'interface
  publique et suit le processus de décision important.
- Pas de secret en dur : les clés/tokens passent par les fichiers
  d'environnement (`backend/.env`, `frontend/.env.local`), jamais dans le
  code ni les fixtures.

---

## 6. Vérifications obligatoires (CI)

La CI GitHub Actions (`.github/workflows/ci.yml`) exécute sur chaque PR :

| Vérification | Commande locale équivalente |
| --- | --- |
| Backend — Pest (PHP 8.3 / PostgreSQL 16) | `php artisan test` |
| Backend — style | `./vendor/bin/pint` |
| Frontend — typecheck | `pnpm typecheck` |
| Frontend — tests | `pnpm test` |
| Frontend — build | `pnpm build` |
| **DCO — commits signés** | `git log --format="%(trailers:key=Signed-off-by)"` |

Une PR n'est fusionnable que si toutes ces vérifications passent.

---

## 7. Licence des contributions

En contribuant sous DCO, vous acceptez que votre contribution soit
distribuée sous la licence du composant concerné (matrice dans
[`docs/licensing.md`](docs/licensing.md)) :

- code du Core → **Apache 2.0** ;
- documentation (`docs/`, README) → **CC BY-SA 4.0** ;
- la marque OMNEX™ reste régie par
  [`BRAND_POLICY.md`](BRAND_POLICY.md) — un fork doit être renommé.

Pour les contributions substantielles de partenaires commerciaux, un
**CLA** pourra être requis par les mainteneurs (GOVERNANCE.md §5.2).

---

## 8. Reconnaissance

Les contributeurs sont reconnus selon les rôles de la gouvernance
(GOVERNANCE.md §2.2) et listés dans `CREDITS.md` (à créer à la première
contribution externe).
