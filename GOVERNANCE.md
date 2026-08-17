# OMNEX — Constitution de gouvernance

> Version 1.0 — Statut : **proposée** (entrée en vigueur à la première
> contribution externe).
>
> Ce document est la **constitution publique** de la gouvernance du projet
> OMNEX, conformément à la matrice des licences
> ([`docs/licensing.md`](docs/licensing.md), ligne « Gouvernance »). Il ne
> fait **pas** partie du code sous licence Apache : il est régi par ses
> propres règles de révision (§6) et par l'entité de gouvernance (§2).

---

## 1. Objet & principes

OMNEX est un écosystème open source et ouvert : un plan de contrôle unique
pour l'infrastructure numérique (domaines, DNS, sites, cloud, stockage,
sécurité, facturation), **indépendant du fournisseur** et **souverain par
conception**.

La gouvernance repose sur cinq principes :

1. **Transparence** — toutes les décisions, délibérations et votes sont
   publics et traçables.
2. **Méritocratie ouverte** — l'influence se gagne par des contributions
   réelles (code, revue, documentation, sécurité, communauté), pas par le
   statut.
3. **Neutralité** — OMNEX ne favorise aucun fournisseur ; chaque contrat
   d'interface reste ouvert à tout implémenteur.
4. **Stabilité** — les interfaces publiques (protocoles, API, contrats de
   provider) ne changent pas sans dépréciation annoncée et période de
   transition.
5. **Sécurité d'abord** — aucune décision ne peut affaiblir la sécurité des
   utilisateurs (isolation multi-locataire, MFA, journal d'audit).

## 2. Entités & rôles

### 2.1 Entité de gouvernance

L'entité légale actuelle est **3S Digital Agency** (détentrice de la marque
OMNEX™ et de l'infrastructure opérée). Elle agit comme **mainteneur fondateur**
et dépositaire de la gouvernance tant qu'une fondation ou association
indépendante n'est pas constituée. La transition vers une entité neutre est
un objectif (voir §7).

### 2.2 Rôles

| Rôle | Attribution | Droits | Retrait |
| --- | --- | --- | --- |
| **Contributeur** | Quiconque soumet une PR, un issue, une traduction ou une doc acceptée | Participer aux discussions, soumettre du code | — |
| **Contributeur actif** | 5+ contributions acceptées sur 12 mois | Candidat aux rôles supérieurs, droit de vote consultatif | Inactivité 18 mois |
| **Membre du noyau (Core)** | Élu par les mainteneurs, revue régulière des contributions | Push direct, revue de PR, droit de vote décisionnel | Inactivité 12 mois ou vote de retrait |
| **Mainteneur (Maintainer)** | Élu par le noyau, 6+ mois de contributions significatives | Merge des PR, gestion des releases, administration CI | Vote du noyau (⅔) |
| **Mainteneur fondateur** | 3S Digital Agency (transitoire) | Veto de sécurité et de marque (limité, §4) | Constitution d'une entité neutre |

### 2.3 Responsabilités transverses

- **Responsable sécurité** : au moins un mainteneur est désigné pour la
  réception et la coordination des divulgations de vulnérabilités
  (`security@omnex.cloud` — voir [`docs/security.md`](docs/security.md)).
- **Responsable des releases** : gère le processus de publication, les
  branches de support et la politique de versionnage.

## 3. Processus de décision

### 3.1 Hiérarchie des décisions

| Niveau | Exemples | Mécanisme |
| --- | --- | --- |
| **Ordinaire** | Bug fix, petite feature, docs, refactor | PR + revue par un mainteneur |
| **Importante** | Nouvelle feature majeure, API publique, contrat de provider | PR + revue + **vote du noyau** |
| **Constitutionnelle** | Modification de ce document, changement de licence, transfert de gouvernance | **Vote public** (voir §3.4) |

### 3.2 Votes

- **Noyau** : un vote = un membre du noyau ou mainteneur. Quorum : 50 % des
  membres votants. Majorité simple, sauf mention contraire.
- **Communauté** : vote consultatif ouvert aux contributeurs actifs ; le
  résultat est publié et pris en compte par le noyau.
- **Constitutionnel** : quorum ⅔ des mainteneurs, approbation à la majorité
  des ⅔, période de discussion de **14 jours** minimum.

### 3.3 Délibération

- Toute décision importante est annoncée sur le dépôt (issue ou discussion
  dédiée) avec une période de commentaires de **7 jours** minimum.
- Les décisions et leurs motivations sont consignées dans le dossier
  `docs/decisions/` au format **ADR** (Architecture Decision Record).

### 3.4 Veto limité

Le mainteneur fondateur dispose de deux vetos **temporaires** et motivés,
pour une durée maximale de 60 jours renouvelable une fois :

1. **Veto de sécurité** — si une décision expose les utilisateurs à un risque
   de sécurité majeur.
2. **Veto de marque** — si une décision engage la marque OMNEX™ contre la
   politique de marque ([`BRAND_POLICY.md`](BRAND_POLICY.md)).

Un veto doit être écrit, public et contraignable par le vote constitutionnel.

## 4. Désignation & retrait des mainteneurs

1. **Candidature** : un contributeur actif peut se proposer, ou être proposé
   par un membre du noyau.
2. **Parrainage** : un mainteneur existant parraine la candidature avec un
   résumé des contributions.
3. **Vote** : le noyau vote à la majorité des ⅔, sur une période de 7 jours.
4. **Intronisation** : accès push, inscription dans le fichier de
   reconnaissance (`CREDITS.md` ou section équivalente du README).
5. **Retrait volontaire** : simple notification.
6. **Retrait pour inactivité** : automatique après la période définie au §2.2.
7. **Retrait pour faute** : vote du noyau à la majorité des ⅔, après
   discussion publique de 7 jours (abus de privilèges, comportement toxique,
   violation de la politique de sécurité).

## 5. Processus de contribution

### 5.1 Parcours d'une contribution

1. Ouvrir une **issue** (feature, bug, question) ou reprendre une issue
   existante.
2. Soumettre une **pull request** avec tests, documentation et message de
   commit explicite.
3. **Revue** : au moins un mainteneur approuve ; les changements
   d'interface publique exigent une revue du noyau.
4. **CI obligatoire** : typecheck, tests (Pest côté backend, Vitest côté
   frontend) et build doivent passer.
5. **Merge** par un mainteneur (squash recommandé, message normalisé).

### 5.2 Originalité & signatures

- **DCO (Developer Certificate of Origin)** : mécanisme par défaut — chaque
  commit doit porter « Signed-off-by » attestant que l'auteur a le droit de
  soumettre la contribution (voir `CONTRIBUTING.md`).
- **CLA (Contributor License Agreement)** : peut être exigé pour les
  contributions substantielles de partenaires commerciaux ou d'entités
  juridiques (voir §7 — décision à trancher avec la première contribution
  externe significative).

### 5.3 Code de conduite

Toute interaction est régie par un **Code de Conduite** (type Contributor
Covenant) appliqué par les mainteneurs ; les violations sont traitées
confidentiellement puis sanctionnées publiquement si nécessaire.

## 6. Révision de la constitution

- Ce document se révise selon le processus **constitutionnel** du §3.1.
- Toute révision est proposée par PR ; la discussion dure 14 jours, le vote
  exige ⅔ des mainteneurs.
- Les amendements adoptés sont consignés dans un journal de versions en fin
  de document.

## 7. Évolution & transition vers une entité neutre

Objectif à moyen terme : transférer la gouvernance vers une **fondation ou
association à but non lucratif** (type Software in the Public Interest,
Eclipse, ou structure nationale) afin de :

- garantir l'indépendance vis-à-vis de 3S Digital Agency et de tout
  fournisseur ;
- héberger les actifs de la communauté (dépôts, marque, domaine) ;
- gérer les **CLA** et les accords de contribution de manière formelle.

La transition est décidée par le vote constitutionnel et ne peut pas être
bloquée par le veto de marque (seul le transfert effectif de la marque est
soumis à la politique de marque).

## 8. Journal des versions

| Version | Date | Changement |
| --- | --- | --- |
| 1.0 | 2026-08 | Adoption de la constitution initiale (proposée par le mainteneur fondateur). |
