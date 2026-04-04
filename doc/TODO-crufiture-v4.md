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
- ✅ Migration BDD `cruf_recette_etape`
- ✅ Ajout `axiosPeyrounet.js` dans `src/plugins/`
- ✅ Ajout `vuedraggable@^4.1.0` dans `package.json`
- ✅ Workflow lots v2 défini (voir WORKFLOW-LOT-CRUFITURE-v2.md)
- ✅ **Migration BDD lots v3** — `cruf_lot_fruit` et `cruf_lot` mis à jour :
  - `cruf_lot_fruit` : `fruit` supprimé → `produit_id` FK rp_produit, `poids_kg` → `poids_base_kg`, ajout `type`, `pct_base`, `poids_brut_kg`, `poids_pulpe_kg`
  - `cruf_lot` : `pulpe_kg` → `poids_pulpe_kg`, `base_kg` → `poids_base_kg`, statut ENUM avec `en_repos`
  - `schema_crufiture_v3.sql` généré
- ✅ **Backend lots** — `LotController.php` réécrit pour workflow v2 :
  - `GET /lots` — recherche numéro + filtre saveur + tri FIELD() par statut
  - `GET /lots/suivi` — lots `en_repos` et `production`
  - `GET /lots/:id` — fiche complète avec jointure rp_produit pour libellés
  - `GET /lots/:id/rendements` — rendements historiques saveur
  - `POST /lots` — création bloc 1, génère numéro, statut `preparation`
  - `PUT /lots/:id` — sauvegarde (`preparation` et `en_repos`)
  - `PUT /lots/:id/mettre-en-repos` — nouvelle transition
  - `PUT /lots/:id/demarrer` — `en_repos` → `production` uniquement
  - `PUT /lots/:id/stocker` — avec jarres + contrôle qualité
  - `PUT /lots/:id/abandonner` — interdit depuis `stock`
  - `POST /lots/:id/releves` — poids reçu = net (tare déduite frontend)
  - `POST /lots/:id/controles` — production et stock
- ✅ **`api.php`** mis à jour — route `/lots/suivi` + `/lots/:id/mettre-en-repos`
- ✅ **`FermeWidgetController.php`** mis à jour — nouveaux statuts, KPI `en_repos`
- ✅ **Frontend lots bureau** :
  - `GestionLots.vue` — liste avec recherche numéro + filtre saveur + badges statuts
  - `CreationLot.vue` — bloc 1 uniquement + calcul à rebours en colonne droite
  - `FicheLot.vue` — page unique adaptée au statut (preparation/en_repos/production/stock/abandonné)

---

## En attente de test

Les fichiers suivants ont été générés et déployés — à valider en prod :

- `LotController.php` — toute la logique métier lots
- `FermeWidgetController.php` — KPIs mis à jour
- `api.php` — routes lots complètes
- `GestionLots.vue` — liste lots
- `CreationLot.vue` — création lot
- `FicheLot.vue` — fiche lot

**Points à surveiller en test :**
- `InputNumber` dans `FicheLot.vue` bloc 3 (v-for) — risque de bug PrimeVue saisie souris → remplacer par `<input type="number">` natif si nécessaire
- Calcul des totaux poids dans `sauvegarder()` — vérifier que `lot.fruits` contient bien les données à jour avant envoi
- Transition `mettre-en-repos` → vérifier les validations backend (brix_fruit, poids)
- `PUT /lots/:id/demarrer` — vérifier que seul `en_repos` est accepté (plus `preparation`)

---

## À faire — PWA mobile

### Étape 3 — PWA `/crufiture/production`

- [ ] Créer `production.html` (second point d'entrée HTML hors layout admin)
- [ ] Créer `src-production/main-production.js`
- [ ] `ProductionAccueil.vue` — liste lots `en_repos` et `production`, progression, tap → pesée
- [ ] `ProductionPesee.vue` — saisie relevé mobile-first (poids brut + tare + météo + résultat immédiat)
- [ ] Configurer Vite pour builder les deux points d'entrée
- [ ] Ajouter règle `.htaccess` pour `/crufiture/production`

---

## Fonctionnalités futures (après lots v1)

- [ ] **Sorties de stock** — prélèvements par poids sur les jarres
- [ ] **Prix de revient** — appel `POST /peyrounet/api/inter/prix-revient` depuis un lot
- [ ] **Conditionnement** — coût par canal (vrac, pot 100g, pot 300g)
- [ ] **Canaux de distribution** — marge et facturation par canal
- [ ] **Capteur bluetooth** — récupération auto température/humidité pendant la production
