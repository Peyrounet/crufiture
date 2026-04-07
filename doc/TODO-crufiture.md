# TODO — Module /crufiture
**Mis à jour : 5 avril 2026 — v6**

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
- ✅ **Migration BDD v3** (4 avril) — `cruf_lot` et `cruf_lot_fruit` alignés workflow v2
- ✅ **Migration BDD v4** (5 avril) — PWA mobile production :
  - `cruf_lot` : + `tare_kg` DECIMAL(6,3) — poids à vide du matériel
  - `cruf_releve_evaporation` : `meteo` supprimé → `temperature`, `humidite`, `vent_kmh`, `ensoleillement`
  - `cruf_jarre` : + `tare_kg`, + `poids_pleine_kg`, `poids_initial_kg` calculé, sans limite de nombre
- ✅ **Backend lots** — `LotController.php` réécrit v4, toutes les routes opérationnelles :
  - `demarrer()` accepte `heure_debut`, `installation`, `tare_kg` depuis la PWA
  - `addReleve()` météo structurée (4 champs) au lieu de `meteo VARCHAR`
  - `stocker()` jarres avec tare+pleine, `poids_reel_kg` calculé backend, sans limite jarres
  - `getSuivi()` et `castLotRow()` incluent `tare_kg`
- ✅ `api.php` mis à jour — route `/lots/suivi` + `/lots/:id/mettre-en-repos`
- ✅ `FermeWidgetController.php` — nouveaux statuts, KPI `en_repos`
- ✅ **`GestionLots.vue`** — liste + recherche numéro (debounce) + filtre saveur + badges statuts
- ✅ **`CreationLot.vue`** — bloc 1 uniquement + calcul à rebours en colonne droite
- ✅ **`FicheLot.vue`** — page unique adaptée au statut, validée en test :
  - Initialisation fruits depuis la recette (`GET /recettes/:id` si `lot.fruits` vide)
  - Blocs 1/2/3 verrouillés en `en_repos`, bloc 4 Krencker seul éditable
  - Calculs temps réel via `totauxTempsReel` computed
  - Alertes rouge/orange + blocage sauvegarde si anomalies
  - Grisage progressif fruits non-pivot et additifs
  - `brix_fruit = 0` accepté (ail, etc.)
- ✅ **PWA mobile** — même SPA, layout `ProductionLayout.vue` dédié :
  - `ProductionAccueil.vue` — liste lots en_repos/production, progression, tap → action
  - `ProductionDemarrage.vue` — heure, installation, tare, poids initial, météo → demarrer + 1er relevé
  - `ProductionPesee.vue` — saisie relevé (tare invisible, météo compacte), résultat post-validation, abandon 4 étapes
  - `ProductionHistorique.vue` — liste chronologique relevés, lecture seule
  - `ProductionStock.vue` — jarres tare+pleine, contenu calculé, contrôle qualité obligatoire
- ✅ `router/index.js` mis à jour — routes `/production/*` avec `ProductionLayout`
- ✅ **Migration BDD v5** — `heure_debut TIME` → `datetime_debut DATETIME` (suivi multi-jours)
- ✅ **Bug api.php** — `$data` manquant dans l'appel `demarrer($m[1])` → corrigé en `demarrer($m[1], $data)`
- ✅ **`LotController.php` `demarrer()`** — `datetime_debut` + validation `tare_kg` obligatoire + `bind_param` fixe
- ✅ **`ProductionDemarrage.vue`** — label "Heure de mise en place"

---

## À valider en prod

- [ ] Relevé de pesée bureau → progression évaporation (météo structurée)
- [ ] Passage en stock bureau → jarres tare+pleine + contrôle qualité
- [ ] Widget /ferme/dashboard → KPIs corrects
- [ ] PWA : démarrage lot depuis mobile → tare + premier relevé
- [ ] PWA : relevés suivants → progression temps réel
- [ ] PWA : mise en stock terrain → jarres + contrôle qualité
- [ ] PWA installable iOS (Safari → Sur l'écran d'accueil)
- [ ] PWA installable Android (Chrome → Installer l'application)
- [ ] Icônes PNG générées (realfavicongenerator.net depuis icon-base.svg) et déployées

---

## Fonctionnalités futures (après lots v1)

- [ ] **Intégration `/stock` — BDD** — migration schema : `stock_article_id` sur `cruf_saveur` + table `cruf_stock_memoire_ingredient`
- [ ] **Intégration `/stock` — fiche saveur** — champ liaison `stock_article` (autocomplétion `GET /stock/api/articles?q=...`)
- [ ] **Intégration `/stock` — fiche recette** — champ optionnel liaison `stock_article` par ingrédient, prérempli depuis `cruf_stock_memoire_ingredient`
- [ ] **Intégration `/stock` — `mettre-en-repos`** — déclarer `sortie_consommation` fruits + fructose + saccharose + additifs (non bloquant, unité `kg`)
- [ ] **Intégration `/stock` — `stocker`** — déclarer `entree_production` de `poids_reel_kg` sur l'article lié à la saveur (non bloquant, unité `kg`)
- [ ] **Bloc 4 Krencker en `en_repos`** — verrouiller complètement (FicheLot.vue + backend `PUT /lots/:id`)
- [ ] **Sorties de stock** — prélèvements par poids sur les jarres (vrac, pot 100g, pot 300g)
- [ ] **Conditionnement** — coût par canal (vrac, pot 100g, pot 300g) + mouvements `/stock` associés
- [ ] **Prix de revient** — appel `POST /peyrounet/api/inter/prix-revient` depuis un lot
- [ ] **Canaux de distribution** — marge et facturation par canal (revendeur, direct, boutique)
- [ ] **Capteur bluetooth** — récupération auto température/humidité/vent pendant la production (champs déjà prêts en BDD)
- [ ] **Calcul à rebours PWA** — afficher la suggestion de poids brut nécessaire sur l'écran de démarrage

---

## Changelog

| Date | Modifications |
|------|---------------|
| 7 avril 2026 | Ajout tâches intégration `/stock` (BDD, fiche saveur, fiche recette, mettre-en-repos, stocker). Ajout tâche verrouillage bloc 4 Krencker en `en_repos`. Conditionnement enrichi (mouvements `/stock`). |
| 5 avril 2026 | v6 — PWA mobile complète |
