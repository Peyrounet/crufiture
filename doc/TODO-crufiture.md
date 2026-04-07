# TODO — Module /crufiture
**Mis à jour : 7 avril 2026**

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
- ✅ Workflow lots v2 défini (voir WORKFLOW-LOT-CRUFITURE.md)
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
- ✅ **Migration BDD v6** — intégration `/stock` :
  - `cruf_saveur` : + `stock_article_id INT UNSIGNED DEFAULT NULL` (FK logique vers `stock_article`)
  - `cruf_lot_fruit` + `cruf_recette_ingredient` : ENUM `type` étendu → `pivot|fruit|additif|fructose|saccharose`
  - Nouvelle table `cruf_stock_memoire_ingredient` (`produit_id` → `stock_article_id`, UNIQUE sur `produit_id`)
- ✅ **Intégration `/stock` — fiche saveur** :
  - `SaveurController.php` — `stock_article_id` lu (JOIN `stock_article` pour libellé) + écrit (GET/POST/PUT)
  - `GestionSaveurs.vue` — champ liaison article stock dans le dialog édition, autocomplétion `GET /stock/api/articles?q=...`, badge article lié
- ✅ **Intégration `/stock` — fiche recette** :
  - `RecetteController.php` — `getOne()` retourne `stock_article_id` par ingrédient (JOIN `cruf_stock_memoire_ingredient`) ; `_saveIngredients()` fait l'UPSERT dans `cruf_stock_memoire_ingredient`
  - `EditionRecette.vue` — champ liaison article stock par ingrédient (fruits + additifs + sucres), autocomplétion `/stock/api/articles?q=...`, pré-rempli cross-recettes depuis la mémoire
- ✅ **Intégration `/stock` — `mettre-en-repos`** :
  - `LotController.php` `mettreEnRepos()` → `declarerConsommationIntrants()` : `sortie_consommation` pour chaque ingrédient lié dans `cruf_stock_memoire_ingredient`. Switch sur `type` : `fructose` → `cruf_lot.fructose_kg`, `saccharose` → `cruf_lot.saccharose_kg`, autres → `poids_base_kg`. Non bloquant.
- ✅ **Intégration `/stock` — `stocker`** :
  - `LotController.php` `stocker()` → `declarerEntreeProduitFini()` : `entree_production` de `poids_reel_kg` sur `cruf_saveur.stock_article_id`. Ignoré si non renseigné. Non bloquant.
- ✅ **`axiosStock.js`** — nouveau plugin `baseURL /stock/api`, ajouté dans `src/plugins/`

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
- [ ] Intégration `/stock` — lier les articles dans les fiches saveurs et recettes existantes
- [ ] Intégration `/stock` — vérifier les mouvements déclarés sur un premier lot réel

---

## Fonctionnalités futures (après lots v1)

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
| 7 avril 2026 | Intégration `/stock` complète : migration BDD v6, SaveurController, GestionSaveurs, RecetteController, EditionRecette, LotController (mettreEnRepos + stocker), axiosStock.js. |
| 7 avril 2026 | Ajout tâches intégration `/stock`. Verrouillage bloc 4 Krencker en `en_repos`. |
| 5 avril 2026 | v6 — PWA mobile complète |
