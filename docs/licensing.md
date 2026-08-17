# OMNEX — Politique de licences & gouvernance

Ce document définit la stratégie de licence de l'écosystème OMNEX. Il remplace
toute licence unique implicite : chaque composant relève de la ligne qui lui
correspond dans la matrice ci-dessous. En cas de conflit, ce document prime
sur les fichiers `LICENSE` des dépôts individuels, qui restent néanmoins la
référence juridique pour chaque artefact.

> Statut : **en cours de définition** — les lignes « À définir » sont des
> décisions volontairement reportées, pas des oublis.

---

## 1. Matrice des licences

| Composant | Licence | Statut |
| --- | --- | --- |
| **OMNEX Core** (backend Laravel, frontend, moteur cloud, domaines, DNS, storage, sécurité, facturation) | 🟢 **Apache License 2.0** | Validé |
| **Protocoles** (contrats d'interface, schémas OpenAPI, formats d'événements, spécifications d'interopérabilité) | 🟢 **Apache License 2.0** | Validé |
| **SDK** (client SDK, kits d'intégration, bindings) | 🟢 **MIT** ou **Apache 2.0** (choix par SDK) | Validé |
| **Libraries** (bibliothèques partagées réutilisables hors du Core) | 🟢 **MIT / Apache 2.0** (choix par bibliothèque) | Validé |
| **Documentation** (docs/, README, guides, wiki) | 🟢 **Creative Commons — CC BY-SA 4.0** | Validé |
| **Gouvernance** (charte, constitution, processus de décision) | 🏛️ **Document constitutionnel public** | Validé |
| **Contributions** (code, docs, traductions) | 📜 **DCO** ou **CLA** selon la stratégie | À trancher |
| **Marque OMNEX™** (nom, logos, éléments visuels) | 🔐 **Politique de marque distincte** (voir §5) | Validé |
| **Infrastructure opérée** (services OMNEX Cloud, plateforme hébergée) | À définir | Reporté |
| **Enterprise services** (fonctionnalités commerciales, support, SLA) | À définir — doit **autoriser le commerce** | Reporté |

---

## 2. OMNEX Core — Apache 2.0

Le code source principal du projet (ce dépôt) est publié sous
**Apache License, Version 2.0** (voir `LICENSE` à la racine).

Raison du choix par rapport à MIT/GPL :

- **Permissive** : réutilisation libre, y compris dans des produits
  propriétaires — indispensable pour l'adoption par les fournisseurs
  d'infrastructure et les intégrateurs.
- **Clause brevets explicite** : chaque contributeur accorde une licence
  de brevets sur ses contributions — protection essentielle pour un projet
  d'infrastructure.
- **NOTICE / attribution** : conserve les mentions d'origine sans imposer
  de copyleft sur les œuvres dérivées.
- Compatible avec l'écosystème Laravel (MIT) et les librairies du projet.

## 3. Protocoles — Apache 2.0

Les contrats d'interface (`DomainProviderInterface`, `ServerProviderInterface`,
`StorageProviderInterface`, schémas OpenAPI dans `docs/openapi.yaml`, formats
d'événements SSE/WebSocket) sont des **standards d'interopérabilité**. Ils sont
publiés sous Apache 2.0 afin que tout fournisseur (y compris concurrent)
puisse implémenter un adaptateur sans ambiguïté juridique.

## 4. SDK & Libraries — MIT / Apache 2.0

Chaque SDK ou bibliothèque publiée séparément porte sa propre licence, choisie
parmi **MIT** ou **Apache 2.0** selon sa surface :

- SDKs clients : **MIT** par défaut (maximise l'adoption).
- Bibliothèques de sécurité ou critiques : **Apache 2.0** (clause brevets).

La licence de chaque artefact est déclarée dans son propre `LICENSE` et dans
les métadonnées du paquet (composer / npm).

## 5. Documentation — CC BY-SA 4.0

La documentation technique et utilisateur (dossier `docs/`, README, guides)
est publiée sous **Creative Commons Attribution-ShareAlike 4.0 International**
(`CC BY-SA 4.0`) : réutilisation libre avec attribution et partage à
l'identique des dérivés.

## 6. Gouvernance — document constitutionnel public

La gouvernance du projet (rôles, processus de décision, règles de vote,
désignation des mainteneurs) est un **document constitutionnel public**
séparé (`GOVERNANCE.md`), versionné et révisable par la communauté selon ses
propres règles. Il ne fait pas partie du code sous licence Apache.

## 7. Contributions — DCO ou CLA

Deux mécanismes sont envisagés, à trancher selon la stratégie d'adoption :

- **DCO (Developer Certificate of Origin)** : le contributeur signe chaque
  commit (« Signed-off-by ») attestant qu'il a le droit de contribuer —
  léger, adapté à une adoption communautaire ouverte.
- **CLA (Contributor License Agreement)** : contrat plus formel qui transfère
  ou licencie les droits de contribution à l'entité OMNEX — adapté si des
  contributions substantielles de partenaires commerciaux sont attendues.

Décision reportée : la stratégie DCO vs CLA sera tranchée avec la première
contribution externe significative.

## 8. Marque OMNEX™ — politique distincte

Le **nom, le logo et l'identité visuelle OMNEX™** ne sont **pas** couverts par
les licences de code. L'utilisation de la marque est régie par une
**politique de marque distincte** :

- Autorisation d'utiliser le nom OMNEX pour désigner le logiciel sous licence
  Apache (attribution normale).
- Interdiction d'utiliser la marque pour des produits/services dérivés sans
  autorisation écrite.
- Le code peut être forké et modifié, mais un fork ne peut pas se présenter
  comme « OMNEX » officiel.

Cette politique est détaillée dans `BRAND_POLICY.md` (à créer).

## 9. Infrastructure opérée & Enterprise services — à définir

Deux domaines restent volontairement **ouverts** :

- **Infrastructure opérée** : l'hébergement OMNEX Cloud (le SaaS fourni par
  3S Digital Agency) est un service distinct du code — ses conditions sont
  régies par les CGU/contrats de service, pas par les licences de code.
- **Enterprise services** : les fonctionnalités commerciales (support dédié,
  SLA, modules enterprise, multi-ténant avancé) doivent être définies de
  manière à **autoriser le commerce** : le code du Core reste Apache 2.0,
  tandis que les services et fonctionnalités commerciales peuvent être
  fournis sous des conditions commerciales, sans violer la licence du Core.

---

## 10. Mise en œuvre

- `LICENSE` (racine) : Apache 2.0 pour le Core.
- `backend/composer.json` → `"license": "Apache-2.0"`.
- `frontend/package.json` → `"license": "Apache-2.0"`.
- `docs/licensing.md` : ce document, référence de la matrice.
- À créer quand les décisions seront prises : `GOVERNANCE.md`,
  `BRAND_POLICY.md`, fichiers `LICENSE` par SDK/bibliothèque.
