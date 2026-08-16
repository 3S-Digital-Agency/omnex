# OMNEX

**Cloud Infrastructure, Simplified.**

---

## Le système d'exploitation de votre infrastructure digitale

Un seul plan de contrôle pour vos domaines, votre DNS, vos sites, votre cloud, votre stockage, votre e-mail, votre sécurité et votre facturation.

---

## Le problème : une infrastructure éclatée

Aujourd'hui, l'infrastructure digitale d'une entreprise est dispersée dans des dizaines d'outils : domaines ici, DNS là, hébergement, cloud, stockage, e-mail, sécurité, facturation… Chaque brique a son propre outil, son propre compte, sa propre facturation.

Conséquences :

- **Fragmentation** — aucune vue unique, impossible de répondre à « où est ma donnée, qui y accède, qu'est-ce qui expire, combien ça coûte » sans recoller des dizaines de tableaux de bord.
- **Risque & dépendance** — sécurité éparpillée, secrets dispersés, accès non révoqués, et un lock-in chez chaque fournisseur.
- **Temps perdu** — une équipe qui gère des outils au lieu de construire et d'exploiter.

---

## La solution : OMNEX, le Cloud OS

Une plateforme unique où chaque ressource — un domaine, un enregistrement DNS, un site, un serveur, un fichier, une facture — devient un **objet du système**, dans un seul modèle de données, derrière une seule interface.

- Une identité, une organisation, une sécurité : le multi-tenant est structurel, pas optionnel.
- Des fournisseurs interchangeables derrière des abstractions : vous n'êtes jamais prisonnier d'un seul.
- Une feuille de route incrémentale : chaque brique est livrée réelle, testée, une à la fois.

---

## Architecture : conçu pour durer, pas pour la démo

- **Monolithe modulaire** — un seul déploiement Laravel avec des frontières de modules dures, découpable en services plus tard sans réécriture.
- **Ports & adaptateurs** — registrar, DNS, stockage, cloud, e-mail, paiement : chaque système externe est derrière une interface. Ajouter un provider = une classe, pas un chantier.
- **Événements, pas de couplage** — les modules communiquent via un bus d'événements typés. L'audit, les notifications et l'automatisation sont des effets de bord, jamais des appels directs.
- **API-first** — toute capacité est exposée en API REST versionnée. L'interface React n'est qu'un consommateur parmi d'autres.

---

## Sécurité & multi-tenant : isolé par conception

- **Isolation structurelle** : scope global applicatif + Row-Level Security PostgreSQL, dès le jour un.
- **Default-deny** : aucun endpoint sans autorisation ; RBAC fin (Owner / Admin / Developer / Viewer).
- **Audit immuable** : chaque mutation critique est tracée avant/après.
- **MFA native** : TOTP RFC 6238 implémenté en interne, codes de récupération, connexion GAFAM (Google, Microsoft, Apple, Facebook, Amazon).
- **IA par permission, jamais par défaut** : l'IA n'accède qu'à ce que le rôle autorise.

---

## État actuel : déjà livré et testé

| Module | Contenu | Statut |
|---|---|---|
| IAM & Organisations | Comptes, organisations, invitations, rôles/permissions, MFA TOTP + codes de récupération, connexion GAFAM | ✅ Livré |
| Command Center | Tableau de bord temps réel, navigation, Ctrl+K, sécurité, i18n français/anglais | ✅ Livré |
| Domaines & DNS | Recherche/enregistrement/renouvellement/transfert, DNS validé avec historique et rollback, DNSSEC, suivi de propagation | ✅ Livré |
| OMNEX Drive | Stockage S3-compatible derrière une abstraction, dossiers, versions, corbeille, quotas, URL signées | ✅ Livré |

Le tout derrière des **providers sandbox déterministes** d'abord, puis de **vrais providers** (Namecheap/OVH, S3/R2/MinIO…) sans toucher au code métier.

---

## Le moteur de domaines (Phase 3)

- Recherche, disponibilité, enregistrement, renouvellement, transfert derrière `DomainProviderInterface`.
- Zone DNS complète : A, AAAA, CNAME, MX, TXT, NS, SRV, CAA — validés, avec modèles, import/export BIND.
- Historique immuable + **rollback réversible** de chaque changement.
- **DNSSEC** (DS records) et **suivi de propagation par serveur de noms**.

---

## OMNEX Drive (Phase 4)

Votre propre cloud, sur un stockage S3-compatible que **vous** choisissez.

- Abstraction `StorageProviderInterface` : sandbox + S3 (AWS, Cloudflare R2, MinIO, OVH) — signature AWS SigV4 native, zéro SDK imposé.
- Dossiers, upload/download par URL signée, versions, corbeille avec restauration, quotas.
- Règle fondatrice : **pas de Nextcloud/ownCloud/Seafile par défaut** — OMNEX possède son abstraction et son interface.

---

## Feuille de route

| Phase | Module | Description |
|---|---|---|
| 0–4 | Fondations → Drive | ✅ Livré : IAM, Command Center, Domaines/DNS, Drive |
| 5 | Sites | Déploiement Git, staging/preview/production, SSL, rollback |
| 6 | Facturation | Plans, abonnements, factures, Stripe (sandbox d'abord) |
| 7 | Sécurité | Security Center, score, constats, remédiation |
| 8 | Cloud | VPS, SSH, pare-feu, snapshots, métriques |
| 9 | CI/CD | Build → test → scan → prod, rollback automatique |
| 10–15 | Mail, IA, Automatisation, Marketplace, Scale, Lancement | La plateforme complète jusqu'au lancement commercial |

---

## La vision : ce que OMNEX devient

- **Un copilote IA** — diagnostiquer, recommander, réviser, appliquer ; jamais d'action destructive silencieuse.
- **Un moteur d'automatisation** — déclencheur → condition → action : tout le parc s'orchestre seul.
- **Une marketplace ouverte** — apps, plugins, thèmes, intégrations, agents IA : la distribution, pas une réécriture.

La cible : un **système d'exploitation** où l'infrastructure devient programmable, observable et automatisable — **souveraineté des données**, **aucun lock-in fournisseur**.

---

## Pourquoi OMNEX

- **Provider-agnostique** : le fournisseur est une donnée, pas du code. Changez de registrar/DNS/cloud sans réécrire.
- **Nous possédons l'abstraction** : le stockage et l'interface cloud sont à nous, par principe.
- **Sécurité structurelle** : multi-tenant et audit dès la première ligne de code.
- **Construit une brique à la fois** : rien de factice — chaque module est réel et testé avant le suivant.

---

## Prochaines étapes

Brancher les vrais providers (registrar, DNS, stockage), livrer les Sites (Phase 5), puis la facturation, la sécurité et le cloud.

**Stack technique** : Laravel + PostgreSQL + Redis + stockage S3-compatible · React + TypeScript + PWA · interface français/anglais.
