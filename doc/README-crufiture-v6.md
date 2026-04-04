# Module /crufiture — Document de référence
**v6 — 4 avril 2026**
*À fournir en début de toute nouvelle discussion sur ce module*

---

## 1. Positionnement dans l'architecture

Module métier de la ferme du Peyrounet. Consomme `/monpanier` (auth, BDD) et `/peyrounet` (compta, relevés de prix). Expose son `ferme-widget` au cockpit `/ferme`.

| Composant | Valeur |
|---|---|
| Slug | crufiture |
| URL | https://peyrounet.com/crufiture/ |
| Dashboard | https://peyrounet.com/crufiture/dashboard |
| API | https://peyrounet.com/crufiture/api/ |
| PWA mobile | https://peyrounet.com/crufiture/production |
| Variable .env | `CRUFITURE_FOLDER=/crufiture` |
| Activité comptable | Cruficulture [id=5] |
| Thème | aura-light-amber |
| Statut | En production — v6 (saveurs + recettes + lots backend + frontend bureau) |

### Dépendances

- `/monpanier` — auth JWT, BDD MySQL, emails
- `/peyrounet` — compta (`POST /compta/ecriture`), relevés de prix (`POST /inter/prix-revient`), autocomplétion produits (`GET /inter/produits`)
- `/ferme` — expose `GET /crufiture/api/ferme-widget` (contrat ferme-widget v1)

---

## 2. Stack technique

| Composant | Valeur exacte |
|---|---|
| Backend | PHP 7.4+ (Hostinger) — pattern identique à foretfeerique |
| Frontend | Vue 3.5 + Pinia 2.x + Vue Router 4.x |
| UI | PrimeVue 3.x — `Dropdown`, `InputSwitch`, `AccordionTab` (pas v4) |
| Build | Vite 5.x — `base: '/crufiture/'` obligatoire |
| BDD | MySQL partagé — tables préfixées `cruf_*` |
| Auth | Cookie JWT peyrounet.com via /monpanier |
| Thème | aura-light-amber depuis /monpanier/themes/ |
| CSS | PrimeFlex + layout SCSS copié depuis foretfeerique/src/assets/ |
| Drag & drop | `vuedraggable@^4.1.0` (ajouté dans package.json) |

---

## 3. Schéma BDD — tables cruf_*

9 tables dans la base `u191509486_dbboutique`. Toutes préfixées `cruf_*`.
**Référence : `schema_crufiture_v3.sql`**

| Table | Rôle |
|---|---|
| `cruf_saveur` | Référentiel des saveurs avec paramètres de formulation par défaut |
| `cruf_recette` | Fiche technique liée à une saveur — plusieurs versions coexistent |
| `cruf_recette_ingredient` | Ingrédients d'une recette — type pivot/fruit/additif, pct_base, FK rp_produit |
| `cruf_recette_etape` | Étapes de préparation ordonnées et réordonnables |
| `cruf_lot` | Cœur du module — un lot = une session de production complète |
| `cruf_lot_fruit` | Ingrédients utilisés dans un lot (pivot, fruits, additifs) avec poids et traçabilité |
| `cruf_jarre` | Stockage en jarres (1 à 3 par lot) |
| `cruf_releve_evaporation` | Relevés de pesée pendant la production |
| `cruf_controle` | Contrôles qualité — au moins 1 obligatoire avant stock |

### Colonnes clés cruf_lot (après migration v3)

- `poids_brut_kg` — somme poids_brut_kg pivot + fruits (sans additifs)
- `poids_pulpe_kg` — somme poids_pulpe_kg pivot + fruits (sans additifs)
- `poids_base_kg` — somme poids_base_kg pivot + fruits = base_kg Krencker
- `statut` — ENUM `preparation | en_repos | production | stock | abandonné`

### Colonnes clés cruf_lot_fruit (après migration v3)

- `produit_id` — FK rp_produit (peyrounet), libellé par jointure, jamais copié
- `type` — ENUM `pivot | fruit | additif`
- `pct_base` — NULL pour le pivot ; % du pivot pour fruits ; % base totale fruits pour additifs
- `poids_brut_kg` — pivot et fruits uniquement, NULL pour additifs
- `poids_pulpe_kg` — pivot et fruits uniquement, NULL pour additifs
- `poids_base_kg` — poids net dans la formule Krencker

### Cycle de vie d'un lot (colonne `statut`)

```
preparation → en_repos → production → stock
     ↕            ↕           ↕
  abandonné    abandonné  abandonné
```

- `preparation` : saisie en cours (4 blocs progressifs), fiche modifiable
- `en_repos` : bloc 4 complet, lot en chambre froide, fiche toujours modifiable
- `production` : pesées en cours, **fiche verrouillée définitivement**
- `stock` : mis en jarres, contrôle qualité effectué
- `abandonné` : lot perdu, jamais supprimé

### Numéro de lot

Format `YY` + séquentiel 4 chiffres (ex: `260001`). Généré à la **première sauvegarde** (POST /lots). Remis à `0001` au 1er janvier. Jamais réutilisé.

### Migrations SQL exécutées (v3 — 4 avril 2026)

```sql
-- cruf_lot_fruit
ALTER TABLE cruf_lot_fruit CHANGE COLUMN poids_kg poids_base_kg DECIMAL(8,3) DEFAULT NULL ...;
ALTER TABLE cruf_lot_fruit DROP COLUMN fruit;
ALTER TABLE cruf_lot_fruit ADD COLUMN produit_id INT UNSIGNED NOT NULL AFTER lot_id ...;
ALTER TABLE cruf_lot_fruit ADD COLUMN type ENUM('pivot','fruit','additif') ...;
ALTER TABLE cruf_lot_fruit ADD COLUMN pct_base DECIMAL(6,3) DEFAULT NULL ...;
ALTER TABLE cruf_lot_fruit ADD COLUMN poids_brut_kg DECIMAL(8,3) DEFAULT NULL ...;
ALTER TABLE cruf_lot_fruit ADD COLUMN poids_pulpe_kg DECIMAL(8,3) DEFAULT NULL ...;

-- cruf_lot
ALTER TABLE cruf_lot CHANGE COLUMN pulpe_kg poids_pulpe_kg DECIMAL(8,3) NOT NULL ...;
ALTER TABLE cruf_lot CHANGE COLUMN base_kg poids_base_kg DECIMAL(8,3) NOT NULL ...;
ALTER TABLE cruf_lot MODIFY COLUMN statut ENUM('preparation','en_repos','production','stock','abandonné') ...;
```

---

## 4. Formules de formulation Krencker

Vérifiées sur le lot betterave 250099. Toutes les valeurs matchent à 4 décimales.

### Entrées utilisateur

| Paramètre | Description |
|---|---|
| `poids_brut_kg` | Poids brut avant nettoyage/transformation (kg) |
| `poids_pulpe_kg` | Poids de pulpe obtenue après préparation (kg) |
| `poids_base_kg` | Part de pulpe utilisée (= somme pivot + fruits, ≤ poids_pulpe_kg) |
| `brix_fruit` | Brix mesuré au réfractomètre sur le mélange global (°Bx) |
| `brix_cible` | Objectif de concentration finale (°Bx) — pré-rempli depuis la saveur |
| `pct_fructose` | % de fructose dans le sucre ajouté — pré-rempli depuis la saveur |
| `pa_cible` | Paramètre de formulation g pulpe / 100g crufiture — pré-rempli depuis la saveur |

### Ordre de calcul

| Variable | Formule |
|---|---|
| `cible_kg` | `poids_base_kg × 100 / pa_cible` |
| `total_sucre_kg` | `cible_kg × brix_cible / 100` |
| `sucre_fruit_kg` | `brix_fruit × poids_base_kg / 100` |
| `sa_kg` | `total_sucre_kg − sucre_fruit_kg` |
| `fructose_kg` | `sa_kg × (pct_fructose / 100)` |
| `saccharose_kg` | `sa_kg × (1 − pct_fructose / 100)` |
| `masse_totale_kg` | `poids_base_kg + sa_kg` |
| `evaporation_kg` | `masse_totale_kg − cible_kg` ← **valeur clé de production** |
| `pa_etiquette` | `poids_pulpe_kg × 100 / cible_kg` ← peut dépasser pa_cible (légal) |

---

## 5. Routes API

### Existantes et opérationnelles

| Route | Description |
|---|---|
| `GET /crufiture/api/ping` | Healthcheck |
| `GET /crufiture/api/ferme-widget` | KPIs cockpit /ferme |
| `GET /crufiture/api/dashboard` | KPIs tableau de bord admin |
| `GET /crufiture/api/saveurs` | Liste toutes les saveurs |
| `POST /crufiture/api/saveurs` | Créer une saveur |
| `PUT /crufiture/api/saveurs/:id` | Modifier une saveur |
| `DELETE /crufiture/api/saveurs/:id` | Supprimer ou désactiver |
| `GET /crufiture/api/recettes` | Liste toutes les recettes |
| `GET /crufiture/api/recettes/:id` | Recette complète |
| `POST /crufiture/api/recettes` | Créer une recette |
| `POST /crufiture/api/recettes/:id/dupliquer` | Nouvelle version |
| `PUT /crufiture/api/recettes/:id` | Modifier titre et note |
| `PUT /crufiture/api/recettes/:id/complet` | Sauvegarde complète |
| `DELETE /crufiture/api/recettes/:id` | Supprimer ou désactiver |
| `GET /crufiture/api/lots` | Liste lots — `?numero=&saveur_id=` — tri par statut puis date |
| `GET /crufiture/api/lots/suivi` | Lots `en_repos` et `production` — menu suivi + PWA |
| `GET /crufiture/api/lots/:id` | Fiche complète (fruits + relevés + contrôles + jarres) |
| `GET /crufiture/api/lots/:id/rendements` | Rendements historiques saveur (calcul à rebours) |
| `POST /crufiture/api/lots` | Créer un lot (bloc 1 — génère numéro, statut `preparation`) |
| `PUT /crufiture/api/lots/:id` | Sauvegarder fiche (`preparation` et `en_repos` uniquement) |
| `PUT /crufiture/api/lots/:id/mettre-en-repos` | Transition `preparation` → `en_repos` |
| `PUT /crufiture/api/lots/:id/demarrer` | Transition `en_repos` → `production` (verrouille) |
| `PUT /crufiture/api/lots/:id/stocker` | Transition `production` → `stock` |
| `PUT /crufiture/api/lots/:id/abandonner` | Abandon avec note obligatoire |
| `POST /crufiture/api/lots/:id/releves` | Ajouter un relevé d'évaporation |
| `POST /crufiture/api/lots/:id/controles` | Ajouter un contrôle qualité |

### Règles métier des routes lots

- `PUT /lots/:id` — autorisé uniquement en `preparation` et `en_repos`
- `PUT /lots/:id/mettre-en-repos` — requiert brix_fruit > 0, poids_base_kg ≤ poids_pulpe_kg, brix_fruit < brix_cible
- `PUT /lots/:id/demarrer` — requiert statut `en_repos` uniquement (plus `preparation`)
- `PUT /lots/:id/stocker` — requiert ≥1 relevé avec poids_net ≤ cible_kg + ≥1 contrôle qualité
- `PUT /lots/:id/abandonner` — interdit en `stock` et `abandonné`
- `POST /lots/:id/releves` — statut `production` uniquement ; `poids_brut_kg` reçu = poids net (tare déjà déduite côté frontend)
- `POST /lots/:id/controles` — statuts `production` et `stock`

### Ferme-widget — KPIs remontés

- Lots année courante (hors abandonné)
- Lots en production
- Lots en repos (affiché seulement si > 0)
- Stock total en jarres (kg)
- Action urgente si lot en production sans relevé depuis hier

---

## 6. Structure des fichiers

### Backend (PHP)

```
crufiture/api/
├── bootstrap.php
├── index.php
├── routes/api.php                  ← mis à jour v6
└── controllers/
    ├── PingController.php
    ├── FermeWidgetController.php   ← mis à jour v6 (nouveaux statuts)
    ├── DashboardController.php
    ├── SaveurController.php
    ├── RecetteController.php
    └── LotController.php           ← réécrit v6 (workflow lots v2)
```

### Frontend bureau (Vue 3)

```
crufiture/src/
├── main.js
├── App.vue
├── assets/
├── layout/
│   ├── AppLayout.vue
│   ├── AppTopbar.vue
│   ├── AppMenu.vue                 ← Lots et Suivi déjà présents
│   ├── AppSidebar.vue
│   └── AppMenuItem.vue
├── plugins/
│   ├── axios.js
│   ├── axiosCrufiture.js
│   └── axiosPeyrounet.js
├── router/index.js                 ← routes lots déjà présentes
├── stores/
├── components/PageCard.vue
└── views/admin/
    ├── DashboardCrufiture.vue
    ├── SimulateurFormulation.vue
    ├── GestionSaveurs.vue
    ├── GestionRecettes.vue
    ├── EditionRecette.vue
    ├── GestionLots.vue             ← réécrit v6
    ├── CreationLot.vue             ← réécrit v6 (bloc 1 uniquement)
    └── FicheLot.vue                ← réécrit v6 (page unique adaptée au statut)
```

### Frontend PWA mobile (à créer)

```
crufiture/
├── production.html                ← second index HTML
└── src-production/
    ├── main-production.js
    └── views/
        ├── ProductionAccueil.vue  ← lots en_repos et production
        └── ProductionPesee.vue   ← saisie relevé rapide
```

---

## 7. Vues bureau — comportement détaillé

### GestionLots.vue — `/dashboard/lots`

- Recherche par numéro de lot (debounce 300ms, `?numero=`)
- Filtre par saveur (`?saveur_id=`)
- Tri côté API : production > en_repos > preparation > stock > abandonné via `FIELD()`
- Badge statuts : preparation (gris), en_repos (bleu/info), production (orange), stock (vert), abandonné (rouge)
- Colonne poids : affiche poids_reel_kg si stock, sinon cible_kg

### CreationLot.vue — `/dashboard/lots/nouveau`

- Bloc 1 uniquement : saveur + recette (triée version décroissante) + date + installation
- Colonne droite : calcul à rebours si lots précédents en stock pour la saveur
- `POST /lots` → redirect immédiat vers `FicheLot` avec le numéro généré
- Recette obligatoire (contrainte métier)

### FicheLot.vue — `/dashboard/lots/:id`

Page unique adaptée au statut :

**preparation / en_repos (modifiable) :**
- Bloc 1 : saveur, recette, date, installation
- Bloc 2 : fruit pivot — poids_brut, poids_pulpe, poids_base (pré-rempli = poids_pulpe, modifiable), fournisseur, origine
- Bloc 3 : fruits non-pivot (poids_base calculé depuis pivot, poids_pulpe, poids_brut) + additifs (calculés depuis base totale fruits, grisés jusqu'à bloc 3 complet)
- Bloc 4 : totaux lecture seule + brix_fruit + Krencker temps réel + note
- Bouton "Mettre en repos" (preparation → en_repos) : requiert calc valide
- Bouton "Démarrer la production" (en_repos → production) : dialog de confirmation, verrouille la fiche

**production (verrouillée) :**
- Valeurs clés : cible_kg, evaporation_kg, masse_totale_kg, fructose_kg, saccharose_kg
- Barre de progression évaporation
- Formulaire relevé : heure + poids brut + tare (non stockée, soustraite en frontend) + météo → poids net calculé immédiatement
- Alerte si cible atteinte → bouton "Passer en stock"
- Historique des relevés en tableau

**stock :**
- Résumé poids réel vs cible
- Jarres avec poids initial
- Tableau contrôles qualité + bouton "Ajouter un contrôle"

**abandonné :** lecture seule + note

---

## 8. Simulateur de formulation

Page `/dashboard/simulateur` — aucun appel BDD, calculs 100% frontend en temps réel.
**La logique de calcul est identique dans `FicheLot.vue` et `SimulateurFormulation.vue`.**

---

## 9. Points d'attention pour les futures discussions

| Point | Règle |
|---|---|
| `bootstrap.php` / `index.php` | Copies de foretfeerique. Ne jamais modifier. |
| `$mysqli` | Variable BDD dans tous les controllers. Jamais `$conn`, `$db`. |
| `ResponseHelper` | `echo ResponseHelper::jsonResponse($message, $status, $details, $statusCode)`. Toujours `echo` devant, toujours `use helpers\ResponseHelper`. |
| `bind_param` types | `s`, `i`, `d`, `b` uniquement. Type invalide échoue silencieusement. |
| Cast TINYINT/INT | `(int) $row['actif']` — PHP retourne string, Vue évalue `"1" === 1` à `false`. |
| Cast DECIMAL | `(float) $row['brix_cible']` — même raison. |
| `v-for` + `v-model` | Toujours `v-model="monRef[idx].prop"` jamais `v-model="item.prop"`. |
| `window` dans template Vue | Inaccessible. Toujours passer par une fonction `<script setup>`. |
| `InputText` dans `v-for` | Bug PrimeVue confirmé — bloque saisie souris. Utiliser `<input type="text" class="p-inputtext p-component">`. |
| `InputNumber` dans `v-for` | Même risque que InputText — surveiller en test, remplacer si nécessaire. |
| Vite base | `base: '/crufiture/'`. Déployer `dist/` dans `/crufiture/`. |
| Assets SCSS | Copie de foretfeerique/src/assets/. Ne pas recréer, ne pas modifier. |
| Barre de recherche | `IconField` + `InputIcon`. Pas `p-input-icon-left`. |
| `actif` flag | Backend uniquement — jamais exposé en UI sauf contexte pertinent. |
| Tare plaque | Saisie à chaque relevé, non stockée. `cruf_releve_evaporation.poids_brut_kg` = poids **net**. |
| Fiche verrouillée | Dès passage en `production`. Aucune modification des paramètres possible. |
| Abandon | Depuis `preparation`, `en_repos`, `production`. Interdit depuis `stock`. Note obligatoire. |
| `cruf_lot_fruit.produit_id` | NOT NULL — jointure sur `rp_produit` obligatoire pour le libellé. |
| Totaux du lot | `poids_brut_kg`, `poids_pulpe_kg`, `poids_base_kg` = pivot + fruits uniquement. Additifs exclus. |
| Calcul à rebours | Optionnel. Requiert ≥1 lot en `stock` avec `poids_reel_kg` non NULL pour la même saveur. |

---

## 10. Déploiement

### Fichiers modifiés en v6 (à redéployer)

**Backend :**
- `crufiture/api/controllers/LotController.php` — réécrit
- `crufiture/api/controllers/FermeWidgetController.php` — mis à jour
- `crufiture/api/routes/api.php` — mis à jour

**Frontend :**
- `src/views/admin/GestionLots.vue` — réécrit
- `src/views/admin/CreationLot.vue` — réécrit
- `src/views/admin/FicheLot.vue` — réécrit

### Règles .htaccess existantes

```apache
# API crufiture
RewriteCond %{REQUEST_URI} ^/crufiture/api
RewriteRule ^crufiture/api/(.*)$ /crufiture/api/index.php [L,QSA]

# SPA crufiture dashboard
RewriteCond %{REQUEST_URI} ^/crufiture/.*$
RewriteCond %{REQUEST_URI} !^/crufiture/favicon\.ico$
RewriteCond %{REQUEST_URI} !^/crufiture/assets/.*$
RewriteCond %{REQUEST_URI} !^/crufiture/images(/.*)?$
RewriteCond %{REQUEST_URI} !^/crufiture/production.*$
RewriteRule ^crufiture/ /crufiture/index.html [L]

# PWA mobile production (à ajouter)
RewriteCond %{REQUEST_URI} ^/crufiture/production
RewriteRule ^crufiture/production(.*)$ /crufiture/production.html [L]
```

### Checklist de vérification (existant — fonctionnel)

- `GET /crufiture/api/ping` → `{message:'pong',status:'success'}`
- `GET /crufiture/api/ferme-widget` → JSON avec `module='crufiture'`
- Login → dashboard → KPIs
- `/dashboard/simulateur` → exemple betterave → résultats corrects
- `/dashboard/saveurs` → CRUD complet
- `/dashboard/recettes` → liste groupée + édition + duplication

### Checklist de vérification (lots — à valider)

- `POST /crufiture/api/lots` → numéro généré format `YY0001`
- `/dashboard/lots` → liste avec filtres et tri par statut
- `/dashboard/lots/nouveau` → création → redirect fiche
- Fiche `preparation` → blocs progressifs → mettre en repos
- Fiche `en_repos` → démarrer → statut `production`, fiche verrouillée
- Relevé de pesée → poids net calculé (brut − tare)
- Cible atteinte → passage en stock avec jarres + contrôle
- Widget `/ferme/dashboard` → KPIs mis à jour

---

*Ferme du Peyrounet — Module /crufiture v6 — 4 avril 2026*
