# Module /crufiture — Document de référence
**v5 — 31 mars 2026**
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
| Statut | En production — v5 (saveurs + recettes + lots en cours) |

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

| Table | Rôle |
|---|---|
| `cruf_saveur` | Référentiel des saveurs avec paramètres de formulation par défaut (brix_cible, pa_cible, pct_fructose) |
| `cruf_recette` | Fiche technique de préparation liée à une saveur — plusieurs versions peuvent coexister |
| `cruf_recette_ingredient` | Ingrédients d'une recette — `type` pivot/fruit/additif, `pct_base`, lien obligatoire vers `rp_produit` |
| `cruf_recette_etape` | Étapes de préparation ordonnées et réordonnables — créée en migration |
| `cruf_lot` | Cœur du module — un lot = une session de production complète |
| `cruf_lot_fruit` | Traçabilité des fruits/légumes utilisés dans un lot (fournisseur, origine, poids) |
| `cruf_jarre` | Stockage en jarres (1 à 3 par lot) avec poids initial et poids actuel |
| `cruf_releve_evaporation` | Relevés de pesée pendant la production (heure, poids net, météo) |
| `cruf_controle` | Contrôles qualité (Brix, Aw, pH) — plusieurs par lot, 1 obligatoire avant stock |

### Cycle de vie d'un lot (colonne `statut`)

```
préparation → production → stock
                 ↕              ↕
              abandonné      abandonné
```

**Migration requise** avant tout dev lots (les anciens statuts `formule`, `en_production`, `mis_en_pot`, `controle`, `archive` sont remplacés) :

```sql
ALTER TABLE cruf_lot 
MODIFY COLUMN statut ENUM('preparation','production','stock','abandonné') 
NOT NULL DEFAULT 'preparation';

-- Si des lots existent déjà en prod :
UPDATE cruf_lot SET statut = 'preparation' WHERE statut = 'formule';
UPDATE cruf_lot SET statut = 'production'  WHERE statut = 'en_production';
UPDATE cruf_lot SET statut = 'stock'       WHERE statut IN ('mis_en_pot','controle','archive');
```

### Numéro de lot

Généré automatiquement par le backend à la création : `YY` + séquentiel 4 chiffres.
Ex : `260001` = année 2026, lot n°0001.
Remis à `0001` au 1er janvier de chaque année.
Jamais supprimé ni réattribué (même si lot abandonné).

---

## 4. Formules de formulation Krencker

Vérifiées sur le lot betterave 250099. Toutes les valeurs matchent à 4 décimales.

### Entrées utilisateur

| Paramètre | Description |
|---|---|
| `poids_brut_kg` | Poids du fruit brut avant nettoyage/transformation (kg) |
| `pulpe_kg` | Poids de pulpe obtenue après préparation (kg) |
| `base_kg` | Part de pulpe utilisée pour ce lot (≤ pulpe_kg). Si base < pulpe : du jus a été retiré pour densifier. |
| `brix_fruit` | Brix mesuré au réfractomètre sur le mélange global (°Bx) |
| `brix_cible` | Objectif de concentration finale (°Bx) — pré-rempli depuis la saveur |
| `pct_fructose` | % de fructose dans le sucre ajouté total — pré-rempli depuis la saveur |
| `pa_formulation` | Paramètre de formulation en g de pulpe pour 100g de crufiture — pré-rempli depuis la saveur |

### Ordre de calcul

| Variable calculée | Formule | Description |
|---|---|---|
| `cible_kg` | `base_kg × 100 / pa_formulation` | Quantité théorique de crufiture produite |
| `total_sucre_kg` | `cible_kg × brix_cible / 100` | Sucre total nécessaire dans la crufiture finale |
| `sucre_fruit_kg` | `brix_fruit × base_kg / 100` | Sucre naturellement apporté par le fruit |
| `sa_kg` | `total_sucre_kg − sucre_fruit_kg` | Sucre à ajouter |
| `fructose_kg` | `sa_kg × (pct_fructose / 100)` | Part fructose du sucre ajouté |
| `saccharose_kg` | `sa_kg × (1 − pct_fructose / 100)` | Part saccharose du sucre ajouté |
| `masse_totale_kg` | `base_kg + sa_kg` | Masse totale à déposer sur le plateau |
| `evaporation_kg` | `masse_totale_kg − cible_kg` | **Eau à évaporer ← VALEUR CLÉ DE PRODUCTION** |
| `pa_etiquette` | `pulpe_kg × 100 / cible_kg` | PA réel sur l'étiquette (réglementaire) |

> Le `pa_etiquette` est calculé sur la pulpe totale (pas sur la base). Il peut être supérieur au `pa_formulation` saisi quand du jus a été retiré — c'est légalement valide, pas une erreur.

### Vérification lot betterave 250099

| Variable | Valeur |
|---|---|
| base_kg | 1,765 kg |
| brix_fruit | 12 °Bx |
| brix_cible | 70 °Bx |
| pa_formulation | 68 g/100g |
| pct_fructose | 50% |
| cible_kg calculé | 2,5956 kg ✓ |
| sa_kg calculé | 1,6051 kg ✓ |
| evaporation_kg calculé | 0,7745 kg ✓ |
| masse_totale_kg calculé | 3,3701 kg ✓ |

### Calcul à rebours (planification)

Optionnel — disponible si ≥ 1 lot antérieur avec `poids_reel_kg` renseigné pour la même saveur.
Affiché en aide contextuelle lors de la création du lot, avant la saisie des ingrédients.

```
rendement_brut_pulpe = AVG(pulpe_kg / poids_brut_kg)  sur lots précédents de la saveur
rendement_pulpe_cruf = AVG(poids_reel_kg / base_kg)   sur lots précédents de la saveur
poids_brut_nécessaire = cible_souhaitée / rendement_pulpe_cruf / rendement_brut_pulpe
```

---

## 5. Routes API en production

### Existantes (opérationnelles)

| Route | Description |
|---|---|
| `GET /crufiture/api/ping` | Healthcheck — requis par /ferme |
| `GET /crufiture/api/ferme-widget` | KPIs cockpit /ferme (timeout 3s) |
| `GET /crufiture/api/dashboard` | KPIs tableau de bord admin |
| `GET /crufiture/api/saveurs` | Liste toutes les saveurs (ordre alpha) |
| `POST /crufiture/api/saveurs` | Créer une saveur |
| `PUT /crufiture/api/saveurs/:id` | Modifier une saveur |
| `DELETE /crufiture/api/saveurs/:id` | Supprimer (physique) ou désactiver (si lots rattachés) |
| `GET /crufiture/api/recettes` | Liste toutes les recettes avec saveur, nb ingrédients, nb étapes |
| `GET /crufiture/api/recettes/:id` | Recette complète avec ingrédients (jointure rp_produit) et étapes |
| `POST /crufiture/api/recettes` | Créer une recette |
| `POST /crufiture/api/recettes/:id/dupliquer` | Nouvelle version à partir d'une recette existante |
| `PUT /crufiture/api/recettes/:id` | Modifier titre et note uniquement |
| `PUT /crufiture/api/recettes/:id/complet` | Sauvegarde complète (titre + note + ingrédients + étapes) |
| `DELETE /crufiture/api/recettes/:id` | Supprimer (physique) ou désactiver (si lots rattachés) |

### À créer (lots)

| Route | Description |
|---|---|
| `GET /crufiture/api/lots` | Liste tous les lots (filtrables par statut, saveur, année) |
| `GET /crufiture/api/lots/en-production` | Lots en statut `production` — PWA mobile |
| `GET /crufiture/api/lots/:id` | Fiche complète (fruits, relevés, contrôles, jarres) |
| `GET /crufiture/api/lots/:id/rendements` | Rendements historiques de la saveur (calcul à rebours) |
| `POST /crufiture/api/lots` | Créer un lot (génère le numéro, statut `preparation`) |
| `PUT /crufiture/api/lots/:id` | Modifier un lot en statut `preparation` |
| `PUT /crufiture/api/lots/:id/demarrer` | Transition `preparation` → `production` |
| `PUT /crufiture/api/lots/:id/stocker` | Transition `production` → `stock` (avec jarres) |
| `PUT /crufiture/api/lots/:id/abandonner` | Passer à `abandonné` (note obligatoire) |
| `POST /crufiture/api/lots/:id/fruits` | Ajouter/remplacer les fruits d'un lot |
| `POST /crufiture/api/lots/:id/releves` | Ajouter un relevé d'évaporation |
| `POST /crufiture/api/lots/:id/controles` | Ajouter un contrôle qualité |

### Contrat ferme-widget — KPIs remontés

- Lots cette année — `COUNT(*) WHERE YEAR(date_production) = annee AND statut != 'abandonné'`
- En production — `COUNT(*) WHERE statut = 'production'`
- Stock en jarres — `SUM(poids_actuel_kg) FROM cruf_jarre`
- Action urgente si lot `production` depuis > 1 jour sans relevé

---

## 6. Structure des fichiers déployés

### Backend (PHP)

```
crufiture/api/
├── bootstrap.php
├── index.php
├── routes/api.php
└── controllers/
    ├── PingController.php
    ├── FermeWidgetController.php
    ├── DashboardController.php
    ├── SaveurController.php
    ├── RecetteController.php
    └── LotController.php          ← à créer
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
│   ├── AppMenu.vue                ← ordre : Simulateur > Saveurs > Recettes > Lots
│   ├── AppSidebar.vue
│   └── AppMenuItem.vue
├── plugins/
│   ├── axios.js
│   ├── axiosCrufiture.js
│   └── axiosPeyrounet.js
├── router/index.js                ← ajouter routes lots
├── stores/
├── components/PageCard.vue
└── views/admin/
    ├── DashboardCrufiture.vue
    ├── SimulateurFormulation.vue
    ├── GestionSaveurs.vue
    ├── GestionRecettes.vue
    ├── EditionRecette.vue
    ├── GestionLots.vue            ← à créer
    ├── CreationLot.vue            ← à créer
    └── FicheLot.vue               ← à créer
```

### Frontend PWA mobile (second point d'entrée)

```
crufiture/
├── production.html                ← second index HTML (hors layout admin)
└── src-production/
    ├── main-production.js
    └── views/
        ├── ProductionAccueil.vue  ← liste lots en production
        └── ProductionPesee.vue    ← saisie relevé rapide
```

Règle .htaccess supplémentaire :
```apache
RewriteCond %{REQUEST_URI} ^/crufiture/production
RewriteRule ^crufiture/production(.*)$ /crufiture/production.html [L]
```

---

## 7. Simulateur de formulation

Page `/dashboard/simulateur` — aucun appel BDD, calculs 100% frontend en temps réel.

- Calculs en temps réel dès que tous les paramètres sont renseignés
- Bloc évaporation mis en avant (fond ambre) — valeur clé de production
- Tableau des quantités à peser : base, fructose, saccharose, SA total, masse totale
- KPIs : kg théoriques, PA étiquette, Rdt brut→pulpe, Rdt pulpe→cruf
- Alerte si évaporation > 30% de la masse (warning) ou > 40% (danger)
- Info si jus retiré (pulpe > base) : affiche le delta en grammes
- Bouton "Exemple betterave" charge les données réelles du lot 250099

**La logique de calcul du simulateur est réutilisée telle quelle dans `CreationLot.vue`.**

**Layout :** flexbox avec flex-wrap. Grilles internes en CSS grid natif `minmax(0, 1fr)`. InputNumber avec `inputClass="sim-input"` et `:deep(.sim-input) { width:100%; min-width:0 }`.

---

## 8. Gestion des saveurs

Page `/dashboard/saveurs` — CRUD complet sur `cruf_saveur`.

- Liste en cards avec avatar (2 initiales, couleur stable générée depuis le nom)
- Recherche live côté frontend
- Tri alphabétique géré côté API (`ORDER BY nom ASC`)
- Formulaire : nom, slug (auto-généré à la création), brix_cible, pa_cible, pct_fructose, note
- Suppression physique si aucun lot rattaché, soft-delete (`actif=0`) sinon
- Barre de recherche : `IconField` + `InputIcon`

---

## 9. Gestion des recettes

Page `/dashboard/recettes` — liste groupée par saveur, page dédiée `/dashboard/recettes/:id`.

- Recette liée à une saveur, plusieurs versions coexistent pour traçabilité
- Ingrédients : `type` pivot/fruit/additif géré par l'appli (non exposé UI)
- Chaque ingrédient référence obligatoirement `rp_produit` (jointure — pas de copie locale)
- Étapes : `cruf_recette_etape` — drag-and-drop via vuedraggable
- Duplication : `POST /recettes/:id/dupliquer` → nouvelle version (max+1)

---

## 10. Gestion des lots

Voir `WORKFLOW-LOT-CRUFITURE.md` pour le détail complet du tunnel métier.

### GestionLots.vue — `/dashboard/lots`

- Tableau : numéro, date, saveur, statut (badge), poids cible ou réel, actions
- Filtres : statut, saveur, année
- Badge statuts : preparation (gris), production (orange), stock (vert), abandonné (rouge barré)
- Bouton "Nouveau lot" → `/dashboard/lots/nouveau`

### CreationLot.vue — `/dashboard/lots/nouveau`

- Bloc 1 : saveur + recette + fruits (traçabilité) + calcul à rebours optionnel
- Bloc 2 : paramètres Krencker + résultats temps réel (logique SimulateurFormulation réutilisée)
- Création → statut `preparation` → redirect vers `/dashboard/lots/:id`

### FicheLot.vue — `/dashboard/lots/:id`

Vue unique adaptée au statut courant :
- `preparation` : formulaire éditable + bouton "Démarrer la production"
- `production` : fiche de suivi (valeurs clés) + relevés + bouton "Passer en stock"
- `stock` : résumé + jarres + contrôles qualité
- `abandonné` : lecture seule + note

### PWA mobile — `/crufiture/production`

Second point d'entrée HTML (hors layout admin). Mobile-first, gros boutons.
- `ProductionAccueil.vue` : liste lots `en production`, progression vers cible
- `ProductionPesee.vue` : saisie rapide relevé (heure, poids brut, tare, météo optionnel)

---

## 11. Points d'attention pour les futures discussions

| Point | Règle |
|---|---|
| `bootstrap.php` | Copie de foretfeerique avec logs adaptés. Ne jamais modifier la logique. |
| `index.php` | Copie EXACTE de foretfeerique. Ne jamais modifier. |
| `$mysqli` | Variable de connexion BDD dans tous les controllers. Jamais `$conn`, jamais `$db`. |
| `ResponseHelper` | `echo ResponseHelper::jsonResponse($message, $status, $details, $statusCode)`. Toujours `echo` devant, toujours `use helpers\ResponseHelper` en tête. |
| `bind_param` types | Types valides : `s`, `i`, `d`, `b` uniquement. Valeur NULLable → toujours type `'s'` avec variable intermédiaire. Un type invalide échoue silencieusement. |
| Cast TINYINT | Toujours `(int) $row['actif']`. PHP retourne une string `"1"` et Vue évalue `"1" === 1` à `false`. |
| Cast DECIMAL | Toujours `(float) $row['brix_cible']` etc. Même raison. |
| Cast statut | `$row['statut']` est une string — pas de cast nécessaire, mais comparer avec `===` en JS. |
| `v-for` + `v-model` | Dans un `v-for="(item, idx) in monRef"`, toujours `v-model="monRef[idx].prop"` et non `v-model="item.prop"`. |
| `window` dans template Vue | Inaccessible directement. Toujours passer par une fonction dans `<script setup>`. |
| Vite base | `base: '/crufiture/'` dans `vite.config.js`. Déployer `dist/` dans `/crufiture/`. |
| Assets SCSS | Copie de foretfeerique/src/assets/. Ne pas recréer, ne pas modifier. |
| `authStore` redirect | `router.push('/dashboard')` — pas `/dashboard/foret`. |
| InputNumber responsive | `inputClass="sim-input"` + `:deep(.sim-input){width:100%;min-width:0}` + CSS grid `minmax(0,1fr)`. Pas de PrimeFlex grid pour les groupes de champs. |
| Barre de recherche | Utiliser `IconField` + `InputIcon` — pas `p-input-icon-left` (rendu cassé). |
| Suppression saveur/recette | Soft-delete si lots rattachés, suppression physique sinon. |
| `cruf_recette_ingredient.produit_id` | NOT NULL — jointure obligatoire pour le libellé. |
| `InputText` dans `v-for` | Bug PrimeVue confirmé : bloque la saisie souris. Utiliser `<input type="text" class="p-inputtext p-component">` natif. |
| `vuedraggable` | Import : `import draggable from 'vuedraggable'`. Toujours utiliser `handle`. |
| Lot abandonné | Jamais de suppression physique. Toutes les données conservées. |
| Tare plaque | Saisie à chaque relevé — non stockée en BDD, soustraite à la volée côté frontend. |
| `cruf_releve_evaporation.poids_brut_kg` | Stocke le poids **net** (tare déjà déduite). |
| Numéro de lot | Généré backend. Logique : `SELECT MAX(numero_lot) LIKE 'YY%'` → YY + (max+1) sur 4 chiffres. |
| Transition production→stock | Requiert ≥1 relevé avec poids net ≤ cible_kg ET ≥1 contrôle qualité. |
| Calcul à rebours | Optionnel. Requiert ≥1 lot antérieur avec `poids_reel_kg` non NULL pour la même saveur. |

---

## 12. Déploiement

### Migrations à exécuter (dans l'ordre)

```sql
-- 1. Nouveau statut ENUM (si lots existants, faire les UPDATE avant)
ALTER TABLE cruf_lot 
MODIFY COLUMN statut ENUM('preparation','production','stock','abandonné') 
NOT NULL DEFAULT 'preparation';
```

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

### Checklist de vérification (existant)

- `GET /crufiture/api/ping` → `{message:'pong',status:'success'}`
- `GET /crufiture/api/ferme-widget` → JSON avec `module='crufiture'`
- `https://peyrounet.com/crufiture/` → page de login
- Login admin → dashboard (KPIs)
- `/dashboard/simulateur` → exemple betterave → résultats corrects
- `/dashboard/saveurs` → CRUD complet
- `/dashboard/recettes` → liste groupée + édition + duplication

### Checklist de vérification (lots — à valider après dev)

- Migration statut exécutée sans erreur
- `POST /crufiture/api/lots` → numéro généré correctement (format `YY0001`)
- `/dashboard/lots` → liste avec filtres
- `/dashboard/lots/nouveau` → création → redirect fiche lot
- Fiche lot `preparation` → démarrer production → statut `production`
- `/crufiture/production` → liste lots en production
- Saisie relevé mobile → visible dans fiche bureau
- Transition production → stock → jarres créées
- Widget `/ferme/dashboard` → KPIs mis à jour

---

*Ferme du Peyrounet — Module /crufiture v5 — 31 mars 2026*
