# TODO — Module /crufiture
**Mis à jour : 31 mars 2026**

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
- ✅ Stratégie métier lots définie (voir WORKFLOW-LOT-CRUFITURE.md)

---

## En cours — Gestion des lots

### Étape 0 — Migration BDD (avant tout dev)
- [ ] Migration statut `cruf_lot` : `ENUM('preparation','production','stock','abandonné')`
- [ ] Vérifier qu'aucun lot existant n'est bloqué par la migration

### Étape 1 — Backend LotController.php
- [ ] `GET /lots` — liste avec filtres (statut, saveur, année)
- [ ] `GET /lots/en-production` — pour la PWA mobile
- [ ] `GET /lots/:id` — fiche complète (fruits, relevés, contrôles, jarres)
- [ ] `GET /lots/:id/rendements` — calcul à rebours (AVG rendements historiques)
- [ ] `POST /lots` — création avec génération numéro de lot
- [ ] `PUT /lots/:id` — modification en statut `preparation`
- [ ] `PUT /lots/:id/demarrer` — transition preparation → production
- [ ] `PUT /lots/:id/stocker` — transition production → stock + jarres
- [ ] `PUT /lots/:id/abandonner` — abandon avec note obligatoire
- [ ] `POST /lots/:id/fruits` — ajouter/remplacer fruits
- [ ] `POST /lots/:id/releves` — ajouter un relevé d'évaporation
- [ ] `POST /lots/:id/controles` — ajouter un contrôle qualité

### Étape 2 — Frontend bureau
- [ ] `GestionLots.vue` — liste avec filtres + badge statuts
- [ ] `CreationLot.vue` — formulaire création (bloc identité + bloc Krencker temps réel)
- [ ] `FicheLot.vue` — vue adaptée au statut (preparation / production / stock / abandonné)
- [ ] Mettre à jour `AppMenu.vue` — ajouter entrée "Lots"
- [ ] Mettre à jour `router/index.js` — ajouter routes `/lots`, `/lots/nouveau`, `/lots/:id`
- [ ] Mettre à jour `FermeWidgetController.php` — KPIs avec nouveaux statuts

### Étape 3 — PWA mobile
- [ ] Créer `production.html` (second point d'entrée)
- [ ] Créer `src-production/main-production.js`
- [ ] `ProductionAccueil.vue` — liste lots en production avec progression
- [ ] `ProductionPesee.vue` — saisie relevé mobile-first
- [ ] Configurer Vite pour builder les deux points d'entrée
- [ ] Ajouter règle `.htaccess` pour `/crufiture/production`

---

## Fonctionnalités futures (après lots v1)

- [ ] **Sorties de stock** — prélèvements par poids sur les jarres (vrac, pot 100g, pot 300g)
- [ ] **Prix de revient** — appel `POST /peyrounet/api/inter/prix-revient` depuis un lot
- [ ] **Conditionnement** — coût par canal (vrac, pot 100g, pot 300g)
- [ ] **Canaux de distribution** — marge et facturation par canal (revendeur, direct, boutique)
- [ ] **Capteur bluetooth** — récupération auto température/humidité pendant la production
