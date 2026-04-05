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
- ✅ **Migration BDD v3** — `cruf_lot` et `cruf_lot_fruit` alignés workflow v2 :
  - `cruf_lot_fruit` : `fruit` supprimé → `produit_id` FK rp_produit, `poids_kg` → `poids_base_kg`, ajout `type`, `pct_base`, `poids_brut_kg`, `poids_pulpe_kg`
  - `cruf_lot` : `pulpe_kg` → `poids_pulpe_kg`, `base_kg` → `poids_base_kg`, statut ENUM avec `en_repos`
- ✅ **Backend lots** — `LotController.php` réécrit, 12 routes opérationnelles
- ✅ `api.php` mis à jour — route `/lots/suivi` + `/lots/:id/mettre-en-repos`
- ✅ `FermeWidgetController.php` — nouveaux statuts, KPI `en_repos`
- ✅ **`GestionLots.vue`** — liste + recherche numéro (debounce) + filtre saveur + badges statuts
- ✅ **`CreationLot.vue`** — bloc 1 uniquement + calcul à rebours en colonne droite
- ✅ **`FicheLot.vue`** — page unique adaptée au statut, validée en test :
  - Initialisation fruits depuis la recette (`GET /recettes/:id` si `lot.fruits` vide)
  - Blocs 1/2/3 verrouillés en `en_repos`, bloc 4 Krencker seul éditable
  - Calculs temps réel via `totauxTempsReel` computed (brut, pulpe, base + flags `brutComplet`, `pulpeComplet`)
  - `watch(pivot.poids_base_kg)` → recalcule fruits non-pivot
  - `watch(baseTotaleFruits)` → recalcule additifs
  - Brut/pulpe totaux affichés uniquement si tous les poids saisis
  - Alertes rouge/orange sur anomalies de saisie (brut < pulpe, base > pulpe, etc.)
  - `erreursSaisie` computed → blocage sauvegarde si anomalies
  - Grisage progressif fruits non-pivot (pivot.poids_base_kg requis) et additifs (base totale requise)
  - `brix_fruit = 0` accepté (ail, etc.)
  - `PUT /lots/:id` en `en_repos` : seuls brix_fruit, brix_cible, pct_fructose, pa_cible, note acceptés

---

## À valider en prod

- [ ] Relevé de pesée → progression évaporation
- [ ] Passage en stock → jarres + contrôle qualité obligatoire
- [ ] Widget /ferme/dashboard → KPIs corrects

---

## À faire — PWA mobile

- [ ] Créer `production.html` (second point d'entrée HTML hors layout admin)
- [ ] Créer `src-production/main-production.js`
- [ ] `ProductionAccueil.vue` — lots `en_repos` et `production` avec progression, tap → pesée
- [ ] `ProductionPesee.vue` — saisie relevé mobile-first (poids brut + tare + météo + résultat immédiat)
- [ ] Configurer Vite pour builder les deux points d'entrée
- [ ] Ajouter règle `.htaccess` pour `/crufiture/production`

---

## Fonctionnalités futures (après lots v1)

- [ ] **Sorties de stock** — prélèvements par poids sur les jarres (vrac, pot 100g, pot 300g)
- [ ] **Prix de revient** — appel `POST /peyrounet/api/inter/prix-revient` depuis un lot
- [ ] **Conditionnement** — coût par canal (vrac, pot 100g, pot 300g)
- [ ] **Canaux de distribution** — marge et facturation par canal (revendeur, direct, boutique)
- [ ] **Capteur bluetooth** — récupération auto température/humidité pendant la production
