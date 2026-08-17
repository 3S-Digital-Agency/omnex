---
name: Pull request
title: 'feat: '
labels: []
assignees: []
---

## Description

<!-- Quoi, pourquoi, et comment cette PR change-t-elle le projet ? -->

Fixes #<issue>

## Type de changement

- [ ] Bug fix
- [ ] Nouvelle fonctionnalité
- [ ] Refactor
- [ ] Documentation
- [ ] Autre (préciser) :

## Interface publique

<!-- Si la PR touche un contrat d'interface (DomainProviderInterface,
ServerProviderInterface, StorageProviderInterface, API, protocoles), le
processus de décision « important » de GOVERNANCE.md s'applique. -->

- [ ] Aucun changement d'interface publique
- [ ] Changement d'interface publique — décision noyau requise :
  - [ ] Décision enregistrée (issue/ADR) : <lien>
  - [ ] Dépréciation annoncée si nécessaire

## Vérifications

- [ ] `php artisan test` (backend — Pest) ✅
- [ ] `./vendor/bin/pint` (backend — style) ✅
- [ ] `pnpm typecheck` (frontend) ✅
- [ ] `pnpm test` (frontend — Vitest) ✅
- [ ] `pnpm build` (frontend) ✅
- [ ] i18n : nouvelles chaînes ajoutées en **EN et FR** dans `src/lib/i18n.ts`
- [ ] Docs mises à jour si nécessaire (`docs/`, roadmap)

## DCO

- [ ] Chaque commit porte la ligne `Signed-off-by` (vérifié par la CI)

## Tests

<!-- Décrivez les tests ajoutés/modifiés et comment les reproduire. -->

## Captures d'écran (si applicable)

<!-- Optionnel — utile pour les changements d'UI. -->
