# TODO — Module /crufiture
**Mis à jour : 4 avril 2026**

---

## Fait

- ✅ Corriger double flèche menu "Retour ferme"
- ✅ Layout simulateur responsive (CSS grid natif + inputClass sim-input)
- ✅ **Gestion des saveurs** — CRUD `cruf_saveur` + liste cards avec avatar + recherche live
- ✅ **Gestion des recettes** — liste groupée par saveur (GestionRecettes.vue)
- ✅ **Édition recette** — création + édition complète (EditionRecette.vue)
  - Drag & drop fruits / additifs / étapes via `vuedraggable`
  - Autocomplétion produits peyrounet (`axiosPeyrounet` → `/inter/produits`)
  - Champs de recherche en `<input>` natif (bug InputText PrimeVue dans v-for)
  - Layout 50/50 (colonne gauche : ingrédients, colonne droite : étapes sticky)
  - Après création → retour liste
- ✅ Migration BDD `cruf_recette_etape` (absente du schema v1)
- ✅ Ajout `axiosPeyrounet.js` dans `src/plugins/`
- ✅ Ajout `vuedraggable@^4.1.0` dans `package.json`
- ✅ Workflow lots v2 défini (voir WORKFLOW-LOT-CRUFITURE-v2.md)

---

## À jeter — code généré invalide

Le code suivant a été généré avant la définition complète du workflow et est à ignorer :
- `LotController.php` — logique métier incorrecte (statuts, structure fruits)
- `api.php` — routes incorrectes
- `GestionLots.vue` — à réécrire
- `CreationLot.vue` — à réécrire entièrement
- `FicheLot.vue` — à réécrire entièrement
- `router_index.js` — routes lots à revoir

---

## En cours — Gestion des lots

### Étape 0 — Schéma SQL (avant tout dev)

- [ ] Mettre à jour `cruf_lot_fruit` :
  - Ajouter `poids_brut_kg DECIMAL(8,3) DEFAULT NULL`
  - Ajouter `poids_pulpe_kg DECIMAL(8,3) DEFAULT NULL`
  - Renommer `poids_kg` → `poids_base_kg` (ou ajouter si absent)
  - Ajouter `fournisseur VARCHAR(100) DEFAULT NULL`
  - Ajouter `origine VARCHAR(100) DEFAULT NULL`
- [ ] Mettre à jour `cruf_lot` :
  - Renommer/ajouter `poids_pulpe_kg DECIMAL(8,3) DEFAULT NULL`
  - Renommer/ajouter `poids_base_kg DECIMAL(8,3) DEFAULT NULL` (remplace `base_kg`)
  - Migration statut `ENUM('preparation','en_repos','production','stock','abandonné')`
- [ ] Vérifier qu'aucun lot existant n'est bloqué par la migration

### Étape 1 — Backend LotController.php

- [ ] `GET /lots` — liste avec recherche (numéro), filtre (saveur), tri par statut puis date
- [ ] `GET /lots/suivi` — lots `en_repos` et `production` — menu suivi + PWA
- [ ] `GET /lots/:id` — fiche complète (fruits par type, relevés, contrôles, jarres)
- [ ] `GET /lots/:id/rendements` — rendements historiques saveur (calcul à rebours)
- [ ] `POST /lots` — créer un lot (bloc 1 uniquement — génère numéro, statut `preparation`)
- [ ] `PUT /lots/:id` — sauvegarder la fiche (statuts `preparation` et `en_repos` uniquement)
- [ ] `PUT /lots/:id/mettre-en-repos` — transition `preparation` → `en_repos`
- [ ] `PUT /lots/:id/demarrer` — transition `en_repos` → `production` (verrouille)
- [ ] `PUT /lots/:id/stocker` — transition `production` → `stock` (avec jarres)
- [ ] `PUT /lots/:id/abandonner` — abandon avec note obligatoire
- [ ] `POST /lots/:id/releves` — ajouter un relevé d'évaporation
- [ ] `POST /lots/:id/controles` — ajouter un contrôle qualité

### Étape 2 — Frontend bureau

- [ ] `GestionLots.vue` — liste avec recherche numéro + filtre saveur + tri par statut
- [ ] `FicheLot.vue` — page unique avec 4 blocs progressifs (preparation/en_repos) + suivi (production) + résumé (stock)
  - Bloc 1 : identité (saveur, recette, date, installation) + aperçu ingrédients recette
  - Bloc 2 : pivot (poids_brut, poids_pulpe, poids_base + fournisseur/origine)
  - Bloc 3 : fruits non-pivot (poids_base calculé, poids_pulpe, poids_brut) + additifs grisés puis calculés
  - Bloc 4 : totaux lecture seule + paramètres Krencker + calculs temps réel + bouton "Mettre en repos"
  - Statut `en_repos` : fiche modifiable + bouton "Démarrer la production"
  - Statut `production` : fiche verrouillée + relevés de pesée
  - Statut `stock` : résumé + jarres + contrôles qualité
  - Statut `abandonné` : lecture seule + note
- [ ] Mettre à jour `AppMenu.vue` — ajouter entrées "Lots" et "Suivi de production"
- [ ] Mettre à jour `router/index.js` — routes `/lots`, `/lots/nouveau`, `/lots/:id`, `/suivi`
- [ ] Mettre à jour `FermeWidgetController.php` — KPIs avec nouveaux statuts (`en_repos`, `production`)

### Étape 3 — PWA mobile

- [ ] Créer `production.html` (second point d'entrée)
- [ ] Créer `src-production/main-production.js`
- [ ] `ProductionAccueil.vue` — lots `en_repos` et `production` avec progression
- [ ] `ProductionPesee.vue` — saisie relevé mobile-first (poids brut + tare + météo)
- [ ] Configurer Vite pour builder les deux points d'entrée
- [ ] Ajouter règle `.htaccess` pour `/crufiture/production`

---

## Fonctionnalités futures (après lots v1)

- [ ] **Sorties de stock** — prélèvements par poids sur les jarres (vrac, pot 100g, pot 300g)
- [ ] **Prix de revient** — appel `POST /peyrounet/api/inter/prix-revient` depuis un lot
- [ ] **Conditionnement** — coût par canal (vrac, pot 100g, pot 300g)
- [ ] **Canaux de distribution** — marge et facturation par canal (revendeur, direct, boutique)
- [ ] **Capteur bluetooth** — récupération auto température/humidité pendant la production
