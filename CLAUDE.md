# CLAUDE.md — Module /transformation
**Ferme du Peyrounet — Contexte permanent pour Claude Code**
*Placer ce fichier à la racine du dossier `crufiture/` du repo (répertoire Hostinger conservé).*

---

## ⚠️ AVANT TOUTE CHOSE — lire en premier, avant toute question

**Dès le démarrage d'une session, avant d'écrire la moindre ligne et avant de répondre
à la moindre question :**

1. Lire **`../ferme/docs/Modules/ECOSYSTEME-PEYROUNET.md`** — contexte global de la ferme,
   philosophie, carte des modules, guide de décision. Ce document répond à "qui fait quoi
   et pourquoi" dans cet écosystème.

2. Lire ce fichier intégralement.

3. Lire **`../ferme/docs/Modules/UI-PATTERNS.md`** — patterns visuels Vue/PrimeVue transverses
   à tous les modules. **Obligatoire avant tout codage frontend**, sans exception.

4. Ne jamais coder sans avoir les fichiers sources (`api.zip` + `src.zip`) d'un module
   existant. En l'absence de ces fichiers, les demander avant de commencer.

Les erreurs les plus coûteuses viennent de conventions ignorées (mauvaise variable BDD,
mauvais composant PrimeVue, colonne SQL inventée). ECOSYSTEME-PEYROUNET.md évite les
questions d'orientation. Ce fichier évite les bugs de convention. UI-PATTERNS.md évite
les divergences visuelles entre modules.

---

## Stack technique

| Composant | Valeur |
|-----------|--------|
| Backend | **PHP 7.4** — pas d'union types, pas de named arguments, pas de `match` sans default |
| Frontend | **Vue 3.5 + Pinia + Vue Router 4 + PrimeVue 3.x** |
| BDD | MySQL partagé Hostinger — tables `cruf_*` (crufiture) + `transfo_*` (tronc commun) + `mace_alcool_*` (macération alcoolique) |
| Auth | Cookie JWT monpanier — via `bootstrap.php` |
| Hébergement | Hostinger — déploiement par `git push` + transfert FTP/SSH |

---

## Conventions ABSOLUES — ne jamais dévier

### Backend PHP

```php
// Variable BDD — toujours $mysqli, jamais $db, $conn, $pdo
$mysqli  // ✅
$db      // ❌

// Helper de réponse — signature exacte
use helpers\ResponseHelper;
ResponseHelper::jsonResponse($message, $status, $data, $httpCode)
// Exemples :
ResponseHelper::jsonResponse('OK', 'success', $data, 200)
ResponseHelper::jsonResponse('Erreur SQL: ' . $mysqli->error, 'error', null, 500)
// ⚠️ Structure JSON retournée — clé "details", PAS "data"
// { "message": "OK", "status": "success", "details": { ... } }
// Côté Vue : response.data.details  ✅ — response.data.data  ❌ (toujours undefined)

// Communication inter-services — require_once UNIQUEMENT, jamais HTTP interne
require_once $_SERVER['DOCUMENT_ROOT'] . '/compta/api/controllers/EcritureController.php';
// ❌ Jamais : file_get_contents(), curl vers URL locale

// Méthodes inter-services — suffixe "Interne", retour tableau PHP (jamais echo/ResponseHelper)
$result = $ctrl->creerEcritureInterne([...]);
if (isset($result['erreur'])) { /* gérer */ }

// Variables d'environnement sur Hostinger (mode CGI)
$val = $_ENV['MA_VAR'] ?? $_SERVER['MA_VAR'] ?? getenv('MA_VAR') ?? 'defaut';

// bind_param — types valides uniquement : s, i, d, b
// bind_param avec valeurs NULL — variable intermédiaire obligatoire
$valeur = null;
$stmt->bind_param('si', $valeur, $id); // ✅
$stmt->bind_param('si', null, $id);    // ❌ plante silencieusement

// Cast obligatoire sur les entiers retournés par fetch_assoc()
$id    = (int)$row['id'];
$actif = (int)$row['actif'];
```

### Frontend Vue

```javascript
// PrimeVue v3 — composants exacts (v3 ≠ v4 !)
<Dropdown>        // ✅  — pas <Select>
<InputSwitch>     // ✅  — pas <ToggleSwitch>
<AccordionTab>    // ✅  — pas <AccordionPanel>

// Tous les composants PrimeVue sont enregistrés globalement dans main.js
// Ne jamais les importer localement dans une vue

// Typage des IDs — MySQL retourne des strings, PrimeVue Dropdown est strict
const id = Number(item.id)  // ✅ toujours caster

// Axios
import axiosCrufiture from '@/plugins/axiosCrufiture'
// baseURL = /transformation/api  (fichier conservé axiosCrufiture.js, nom interne)

// CSS sur Hostinger — bg-*-50 indisponibles
style="background: #f8fafc"   // ✅
class="bg-blue-50"             // ❌ — classe absente du build Tailwind Hostinger

// Icônes PrimeVue — certaines absentes
pi-arrow-up / pi-arrow-down        // ✅
pi-trending-up / pi-trending-down  // ❌ — n'existent pas

// window inaccessible dans les templates — toujours passer par une fonction
const naviguer = (url) => { window.location.href = url; }

// v-for + v-model — item n'est pas réactif, toujours indexer
<input v-model="maListe[idx].nom" />  // ✅
<input v-model="item.nom" />          // ❌ ne se met pas à jour

// stock_article.unite — ENUM strict, 5 valeurs uniquement
// 'kg' | 'L' | 'piece' | 'm' | 'kWh'
// ⚠️ 'bouteille' n'existe pas — utiliser 'piece' pour toute unité de comptage
// Une unité hors-ENUM passe silencieusement à 0 dans enregistrerMouvement()
const UNITES_STOCK = ['kg', 'L', 'piece', 'm', 'kWh']  // toujours utiliser ce tableau
```

---

## Rôle du module

Module de transformation de la ferme du Peyrounet. Gère toutes les **gammes de transformation**
(crufiture, jus, séchage, macération alcoolique/huileuse, distillation). L'URL publique est
`/transformation` ; le répertoire physique Hostinger reste `crufiture/`.

En production en juin 2026 : uniquement la gamme **crufiture** (fruits + sucre → pots de confiture crue).
Les autres gammes s'intégreront progressivement via le tronc commun `transfo_*`.

---

## Architecture BDD

### Tables tronc commun — `transfo_*`

| Table | Rôle |
|-------|------|
| `transfo_gamme` | Gammes de transformation (crufiture, jus, sechage, maceration_alcool, maceration_huile, distillation) |
| `transfo_produit` | Produits finis — 1 gamme + 1 stock_article. Slug global unique préfixé par gamme (ex: `crufiture-framboise`) |
| `transfo_lot` | Lot de production tronc commun — sans produit_id direct (sorties dans transfo_lot_produit) |
| `transfo_lot_ingredient` | Ingrédients entrants d'un lot (→ prix_article cross-service) |
| `transfo_lot_produit` | Produits sortants d'un lot — 1..N, `stock_article_id INT NULL` (direct, bypass transfo_produit), `libelle VARCHAR(255) NULL`, `quantite_produite` NULL avant stocker(), `unite`, `dlc DATE` |
| `transfo_controle` | Contrôles qualité (brix, aw, ph, abv selon gamme) |
| `transfo_recette` | Identité recette tronc commun — gamme_id, nom, famille, actif |
| `transfo_recette_version` | Version recette — statut brouillon/en_test/validee, nb_unites, unite_production, materiel, difficulte, conservation, description (notes libres / valorisation provenance) |
| `transfo_recette_ingredient` | Ingrédients recette — stock_article_id nullable (non-bloquant), libelle stocké direct, quantite, coeff_perte, unite |
| `transfo_recette_phase` | Phases du protocole — ordre, temporalite, label |
| `transfo_recette_etape` | Étapes — phase_id, ordre, description |
| `transfo_recette_controle` | Points de contrôle qualité — etape_label, point_controle, valeur_cible, action_corrective |

**Règle `transfo_lot_produit` :**
- Crufiture, jus → ligne créée à la création du lot avec `quantite_produite = NULL`, renseignée au `stocker()`
- Macération alcoolique, distillation et gammes à sorties libres → lignes créées uniquement au `stocker()` (formats de bouteilles/sorties inconnus à l'avance)

**`transfo_recette_*` — recettes tronc commun (type classique) :**
- Utilisées par macération alcoolique et les futures gammes (jus, séchage, distillation)
- Crufiture conserve ses tables `cruf_recette_*` (recettes ratio-based, incompatibles avec le tronc commun)
- Les ingrédients référencent `stock_article_id` directement (pas de catalogue intermédiaire)
- Versioning identique au pattern fermeauberge : brouillon → en_test → validée, fork = nouvelle version

### Tables extension macération alcoolique — `mace_alcool_*`

| Table | Rôle |
|-------|------|
| `mace_alcool_recette_version` | Extension recette macération — bridge 1:1 vers `transfo_recette_version`, paramètres spécifiques |
| `mace_alcool_lot` | Extension lot macération — bridge vers `transfo_lot`, statut workflow détaillé, horodatages |

**`mace_alcool_recette_version` :**
- `transfo_recette_version_id` UNIQUE (bridge 1:1)
- `duree_maceration_cible_j` INT — durée cible macération en jours
- `duree_maturation_cible_j` INT — durée cible maturation en jours
- `abv_cible_pct` DECIMAL(5,2) — taux d'alcool cible (%vol)
- `brix_cible` DECIMAL(5,2) — brix cible (liqueurs uniquement)
- `avec_assemblage` TINYINT — 1 si ajout sirop (liqueur), 0 sinon (eau-de-vie)

**`mace_alcool_lot` :**
- `transfo_lot_id` UNIQUE NOT NULL (bridge obligatoire — utilisé proprement dès le premier lot)
- `recette_version_id` — version validée utilisée (immuable une fois le lot créé)
- `statut` ENUM(`preparation`, `en_maceration`, `filtration`, `assemblage`, `maturation`, `stock`, `abandonne`)
- `lot_test` TINYINT NOT NULL DEFAULT 0 — dérivé du statut `en_test` de la recette version, immuable une fois le lot créé
- `date_debut_maceration` DATETIME NULL
- `duree_maceration_cible_j` INT — copié depuis la recette (immuable, le lot garde sa cible même si la recette évolue)
- `date_filtration` DATETIME NULL — horodatage fin macération / début filtration
- `date_assemblage` DATETIME NULL — horodatage début assemblage (si avec_assemblage = 1)
- `date_debut_maturation` DATETIME NULL
- `duree_maturation_cible_j` INT — copié depuis la recette
- `avec_assemblage` TINYINT — copié depuis la recette
- `date_mise_en_stock` DATETIME NULL — horodatage mise en stock (enregistré par stocker())

### Tables extension crufiture — `cruf_*`

| Table | Rôle |
|-------|------|
| `cruf_saveur` | Catalogue des saveurs — lié à `stock_article_id` et bridge `transfo_produit_id` |
| `cruf_recette` | Recettes de crufiture (versionnées) |
| `cruf_recette_ingredient` | Ingrédients d'une recette — `produit_id` FK legacy (libellé via `cruf_stock_memoire_ingredient` → `stock_article`) |
| `cruf_lot` | Lots crufiture — spécificités : tare_kg, brix_initial, formule Krencker. Bridge `transfo_lot_id` |
| `cruf_lot_fruit` | Ingrédients du lot crufiture (avec rôles : fruit, pivot, additif) |
| `cruf_releve_evaporation` | Relevés de suivi en cours de production (météo, poids évaporation) |
| `cruf_jarre` | Jarres produites à la fin du lot (tare_kg + poids_pleine_kg) |
| `cruf_controle` | Contrôles qualité crufiture (aw, brix final) |

### Bridges cruf → transfo (FK optionnelles, NULL = non migré)

```sql
cruf_saveur.transfo_produit_id  → transfo_produit.id  (ON DELETE SET NULL)
cruf_lot.transfo_lot_id         → transfo_lot.id       (ON DELETE SET NULL)
```

---

## Routes API — `/transformation/api`

Paramétré via `$_ENV['CRUFITURE_FOLDER']` → `/transformation`. Variable dans api.php : `$prefix`.

### Ping & cockpit

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/ping` | PingController | Healthcheck |
| GET | `/ferme-widget` | FermeWidgetController | Widget cockpit /ferme |
| GET | `/dashboard-transfo` | DashboardTransfoController | KPIs cross-gammes (dashboard global /transformation) |
| GET | `/dashboard` | DashboardController | KPIs crufiture (dashboard gamme) |

### Gammes & Produits (tronc commun)

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/gammes` | GammeController | Liste toutes les gammes |
| POST | `/gammes` | GammeController | Créer une gamme |
| PUT | `/gammes/:id` | GammeController | Modifier une gamme |
| DELETE | `/gammes/:id` | GammeController | Supprimer une gamme |
| GET | `/gammes/:id/produits` | ProduitTransfoController | Produits d'une gamme |
| POST | `/gammes/:id/produits` | ProduitTransfoController | Créer un produit |
| PUT | `/gammes/:id/produits/:produitId` | ProduitTransfoController | Modifier un produit |
| DELETE | `/gammes/:id/produits/:produitId` | ProduitTransfoController | Supprimer un produit |

### Saveurs (catalogue crufiture)

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/saveurs` | SaveurController | Liste toutes les saveurs |
| POST | `/saveurs` | SaveurController | Créer une saveur |
| PUT | `/saveurs/:id` | SaveurController | Modifier une saveur |
| DELETE | `/saveurs/:id` | SaveurController | Supprimer une saveur |

### Recettes crufiture (catalogue cruf_recette_*)

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/recettes` | RecetteController | Liste avec versioning |
| GET | `/recettes/:id` | RecetteController | Fiche complète |
| POST | `/recettes` | RecetteController | Créer |
| POST | `/recettes/:id/dupliquer` | RecetteController | Fork nouvelle version |
| PUT | `/recettes/:id/complet` | RecetteController | Mise à jour complète (saveur + ingrédients) |
| PUT | `/recettes/:id` | RecetteController | Mise à jour partielle |
| DELETE | `/recettes/:id` | RecetteController | Supprimer |

### Recettes tronc commun (transfo_recette_* — macération alcoolique et futures gammes)

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/recettes-transfo` | RecetteTransfoController | Liste groupée par recette (filtre ?gamme_id=) |
| GET | `/recettes-transfo/version` | RecetteTransfoController | Détail complet d'une version (?version_id=) |
| POST | `/recettes-transfo` | RecetteTransfoController | Créer recette + v1 |
| PUT | `/recettes-transfo` | RecetteTransfoController | Modifier une version |
| POST | `/recettes-transfo/dupliquer` | RecetteTransfoController | Fork (?version_id=) |
| PUT | `/recettes-transfo/statut` | RecetteTransfoController | Changer statut version |
| DELETE | `/recettes-transfo` | RecetteTransfoController | Supprimer version (?version_id=) |
| GET | `/recettes-transfo/export-pdf` | RecetteTransfoController | Export fiche technique (?version_id=&format=chef\|complet) |

### Lots macération alcoolique

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/mace-alcool/lots` | LotMaceAlcoolController | Liste lots macération alcoolique |
| GET | `/mace-alcool/lots/:id` | LotMaceAlcoolController | Fiche lot complète |
| POST | `/mace-alcool/lots` | LotMaceAlcoolController | Créer un lot (preparation) |
| POST | `/mace-alcool/lots/:id/controles` | LotMaceAlcoolController | Ajouter mesure dans transfo_controle |
| PUT | `/mace-alcool/lots/:id/demarrer-maceration` | LotMaceAlcoolController | preparation → en_maceration |
| PUT | `/mace-alcool/lots/:id/filtrer` | LotMaceAlcoolController | en_maceration → filtration (horodate date_filtration — date fournie ou NOW()) |
| PUT | `/mace-alcool/lots/:id/assembler` | LotMaceAlcoolController | filtration → assemblage (horodate date_assemblage — date fournie ou NOW()) |
| PUT | `/mace-alcool/lots/:id/demarrer-maturation` | LotMaceAlcoolController | filtration\|assemblage → maturation |
| PUT | `/mace-alcool/lots/:id/stocker` | LotMaceAlcoolController | maturation → stock + push /stock + push /registres |
| PUT | `/mace-alcool/lots/:id/abandonner` | LotMaceAlcoolController | Tout statut → abandonné |

### Lots

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/lots` | LotController | Liste tous les lots |
| GET | `/lots/suivi` | LotController | Lots en_repos + production (PWA mobile) |
| GET | `/lots/:id` | LotController | Fiche lot complète |
| GET | `/lots/:id/rendements` | LotController | Calculs de rendement Krencker |
| POST | `/lots` | LotController | Créer un lot (statut preparation) |
| POST | `/lots/:id/releves` | LotController | Ajouter un relevé de suivi |
| POST | `/lots/:id/controles` | LotController | Ajouter un contrôle qualité |
| PUT | `/lots/:id` | LotController | Modifier lot (champs libres) |
| PUT | `/lots/:id/mettre-en-repos` | LotController | preparation → en_repos |
| PUT | `/lots/:id/demarrer` | LotController | en_repos → production (heure_debut, installation, tare_kg) |
| PUT | `/lots/:id/stocker` | LotController | production → stock + push /stock |
| PUT | `/lots/:id/abandonner` | LotController | Tout statut → abandonné |

---

## Workflow lot crufiture

```
preparation  →  en_repos  →  production  →  stock
                                          ↘  abandonné
```

- **preparation** : lot créé avec saveur + recette sélectionnées
- **en_repos** : fruits macèrent (durée variable)
- **production** : démarrage avec heure_debut, installation, tare_kg
  - Relevés de suivi en cours (météo, brix)
  - Formule Krencker calculée à partir de brix_initial + recette
- **stock** : jarres pesées (tare + poids_pleine), push `/stock` avec `source_service = 'crufiture'`
- **abandonné** : lot annulé à n'importe quelle étape

**Numéro de lot :** format `[PRÉFIXE][YY][NNNN]` — préfixe gamme (2 lettres) + année 2 chiffres + séquence 4 chiffres. Séquentiel annuel **par gamme**, remis à 0001 au 1er janvier. Ex: `CR260001` (crufiture), `MA260001` (macération alcoolique).

| Gamme | Préfixe |
|-------|---------|
| Crufiture | `CR` |
| Jus | `JU` |
| Séchage | `SE` |
| Macération alcool | `MA` |
| Macération huile | `MH` |
| Distillation | `DI` |

Le préfixe est hardcodé par slug dans les contrôleurs — pas de colonne dans `transfo_gamme`.

---

## Workflow lot macération alcoolique

```
preparation → en_maceration → filtration → [assemblage] → maturation → stock
                                                                      ↘ abandonné (tout statut)
```

- **preparation** : lot créé — recette version validée sélectionnée, ingrédients saisis dans `transfo_lot_ingredient`, numéro lot généré (`MA[YY][NNNN]`)
- **en_maceration** : `date_debut_maceration` horodatée — alerte dashboard calculée à la volée quand `NOW() >= date_debut_maceration + duree_maceration_cible_j jours`
- **filtration** : `date_filtration` horodatée (obligatoire pour traçabilité) — mesures dans `transfo_controle` (ABV, aspect)
- **assemblage** (si `avec_assemblage = 1`) : `date_assemblage` horodatée (date fournie ou NOW()) — ajout sirop de sucre — mesure brix final dans `transfo_controle`
- **maturation** : `date_debut_maturation` horodatée (date fournie ou NOW()) — alerte quand `NOW() >= date_debut_maturation + duree_maturation_cible_j jours`
- **stock** : `date_mise_en_stock` enregistrée (NOW()) — saisie des bouteilles produites → création lignes `transfo_lot_produit` (`stock_article_id` direct + `libelle` + `quantite` + `unite` ENUM + `DLC`), push `/stock` par ligne, push `/registres` (conformité bio)
- **abandonné** : annulation à n'importe quelle étape

**Règle `transfo_lot_produit` pour macération alcoolique :** lignes créées uniquement au `stocker()` — les formats de bouteilles (1L, 75cL, 50cL...) ne sont pas connus à l'avance. Chaque format est un `transfo_produit` distinct lié à un `stock_article` distinct. DLC renseignée à ce moment.

**Alerte maturation :** même mécanique que l'alerte macération — calculée à la volée dans les requêtes dashboard, pas de table dédiée.

---

## Formule Krencker — spécifique crufiture

La formule Krencker calcule les proportions fruit/sucre/eau optimales pour atteindre
un brix cible et un pourcentage d'alcool apparent (`pa`) cible. Elle est encapsulée
dans la méthode **privée** `calculerKrencker()` de `LotController.php`.

**Ne pas généraliser.** Cette formule est propre à la crufiture — elle n'a aucune
équivalence dans les autres gammes.

Paramètres : `poids_base_kg`, `brix_fruit`, `brix_cible`, `pa_cible`, `pct_fructose`.
Retourne un tableau avec tous les résultats calculés (quantités, rendements théoriques).

---

## Structure des vues Vue

```
src/views/
├── admin/
│   │
│   │  ── NIVEAU MODULE /transformation ──────────────────────────
│   ├── DashboardTransfo.vue      ← dashboard global (KPIs cross-gammes)  [✅ production]
│   ├── CatalogueGammes.vue       ← CRUD gammes (liste + actions)          [✅ production]
│   ├── FicheGamme.vue            ← édition gamme + gestion produits       [✅ production]
│   │
│   │  ── GAMME CRUFITURE — /dashboard/crufiture/* ────────────────
│   ├── DashboardCrufiture.vue    ← dashboard gamme crufiture
│   ├── GestionSaveurs.vue        ← CRUD saveurs
│   ├── GestionRecettes.vue       ← liste recettes versionnées
│   ├── EditionRecette.vue        ← formulaire recette (formulaire complexe en sections)
│   ├── SimulateurFormulation.vue ← simulateur Krencker
│   ├── GestionLots.vue           ← liste des lots
│   ├── CreationLot.vue           ← wizard création lot
│   └── FicheLot.vue              ← fiche détail lot
│   │
│   │  ── GAMME MACÉRATION ALCOOLIQUE — /dashboard/maceration_alcool/* ──
│   ├── DashboardMacerationAlcool.vue  ← dashboard gamme + alertes macération/maturation
│   ├── GestionRecettesMace.vue        ← liste recettes + filtre statut + changement statut + PDF
│   ├── EditionRecetteMace.vue         ← édition recette — layout sidebar (pattern FicheTechnique fermeauberge)
│   ├── GestionLotsMace.vue            ← liste lots macération
│   ├── CreationLotMace.vue            ← création lot (recette + ingrédients)
│   ├── FicheLotMace.vue               ← fiche lot + workflow + contrôles + stocker
│   └── mace/                          ← sous-composants EditionRecetteMace
│       ├── MACE_Identification.vue    ← nom, famille (création), notes, nb_unités, difficulté, conservation
│       ├── MACE_Parametres.vue        ← durées macération/maturation, ABV cible, brix, assemblage, matériel
│       ├── MACE_Ingredients.vue       ← ingrédients avec autocomplete /stock (stock_article_id exact)
│       ├── MACE_Protocole.vue         ← phases + étapes drag-drop (vuedraggable)
│       ├── MACE_Controles.vue         ← points de contrôle qualité
│       └── MACE_Valorisation.vue      ← description/provenance (colonne transfo_recette_version.description)
│
└── production/                   ← PWA mobile saisie terrain (inchangé)
    ├── ProductionAccueil.vue
    ├── ProductionDemarrage.vue
    ├── ProductionPesee.vue
    ├── ProductionHistorique.vue
    └── ProductionStock.vue
```

### Structure des routes Vue Router (après migration)

```
/dashboard                           → DashboardTransfo.vue        (nouveau)
/dashboard/gammes                    → CatalogueGammes.vue         (nouveau)
/dashboard/gammes/:id                → FicheGamme.vue              (nouveau)
/dashboard/crufiture                 → DashboardCrufiture.vue      (migré depuis /dashboard)
/dashboard/crufiture/saveurs         → GestionSaveurs.vue          (migré depuis /dashboard/saveurs)
/dashboard/crufiture/recettes        → GestionRecettes.vue         (migré depuis /dashboard/recettes)
/dashboard/crufiture/recettes/:id    → EditionRecette.vue          (migré depuis /dashboard/recettes/:id)
/dashboard/crufiture/lots            → GestionLots.vue             (migré depuis /dashboard/lots)
/dashboard/crufiture/lots/nouveau    → CreationLot.vue             (migré depuis /dashboard/lots/nouveau)
/dashboard/crufiture/lots/:id        → FicheLot.vue                (migré depuis /dashboard/lots/:id)
/dashboard/crufiture/simulateur      → SimulateurFormulation.vue   (migré depuis /dashboard/simulateur)

/dashboard/maceration_alcool                    → DashboardMacerationAlcool.vue
/dashboard/maceration_alcool/recettes           → GestionRecettesMace.vue
/dashboard/maceration_alcool/recettes/:id       → EditionRecetteMace.vue
/dashboard/maceration_alcool/lots               → GestionLotsMace.vue
/dashboard/maceration_alcool/lots/nouveau       → CreationLotMace.vue
/dashboard/maceration_alcool/lots/:id           → FicheLotMace.vue
```

**⚠️ Lors de la migration des routes :** mettre à jour tous les `router.push()` et `<router-link to="">` dans les vues existantes. Vérifier aussi les liens hardcodés dans les breadcrumbs et les boutons "Retour".

Les vues `production/` exposent une interface mobile (PWA) distincte des vues admin desktop.
Voir `PWA-PATTERNS.md` pour les patterns mobile si on touche à ces vues.

---

## Dépendances inter-services

| Service | Usage | Méthode |
|---------|-------|---------|
| `/prix` | Autocomplétion articles dans recettes et lots **crufiture** | `GET /prix/api/inter/articles?q=xxx` (HTTP — frontend Vue, retourne `prix_article.id`) |
| `/stock` | Autocomplétion ingrédients recettes **macération** + produits `FicheGamme` | `GET /stock/api/articles?q=xxx` (HTTP — frontend Vue via `axiosStock`, retourne `stock_article_id`) |
| `/stock` | Déclaration entrée stock au stocker() | `StockMouvementController::enregistrerMouvement()` (require_once) |
| `/registres` | Push intervention à chaque mise en stock d'un lot | `RegistreController::push()` (require_once) |
| `/ferme` | Widget cockpit | `GET /transformation/api/ferme-widget` exposé |

`source_service` dans les mouvements /stock :
- Mouvements crufiture : `'crufiture'`
- Mouvements macération alcoolique : `'maceration_alcool'`
- Convention générale : slug de la gamme (`transfo_gamme.slug`)

**Push `/registres` au stocker() :** chaque lot mis en stock = intervention tracée pour la conformité bio. Obligatoire pour toutes les gammes.

---

## État du développement (juin 2026)

| Composant | État |
|-----------|------|
| Gamme crufiture complète | ✅ Production |
| URL `/transformation` | ✅ En prod (.htaccess + .env + ferme_module migrés) |
| Tronc commun `transfo_*` | ✅ Tables déployées, migration cruf_saveur → transfo_produit effectuée |
| Bridge `cruf_lot.transfo_lot_id` | ⬜ NULL sur tous les lots existants — à remplir lors des prochains lots |
| UI catalogue gammes/produits | ✅ Production — `CatalogueGammes.vue` + `FicheGamme.vue` + `gammeStore` Pinia |
| Migration routes `/dashboard/crufiture/*` | ✅ Production — router + menu + liens internes migrés |
| Dashboard global `/transformation` | ✅ Production — `DashboardTransfo.vue` + `DashboardTransfoController` |
| ALTER `transfo_lot_produit` — colonne `dlc DATE` | ✅ Déployé (SQL appliqué en production) |
| SQL gamme macération alcoolique | ✅ Déployé — `transfo_recette_*`, `mace_alcool_recette_version`, `mace_alcool_lot` (voir `doc/schema-mace-alcool.sql`) |
| ALTER `mace_alcool_lot` — `date_assemblage` + `date_mise_en_stock` | ✅ Déployé (SQL appliqué) |
| ALTER `transfo_lot_produit` — `stock_article_id INT NULL` + `libelle VARCHAR(255) NULL` + `produit_id` nullable | ✅ Déployé (SQL appliqué) |
| Backend recettes tronc commun | ✅ En test — `RecetteTransfoController.php` : GET liste, GET version, POST créer, PUT modifier, POST dupliquer, PUT statut, DELETE, GET export-pdf |
| Backend lots macération alcoolique | ✅ En test — `LotMaceAlcoolController.php` : workflow 6 statuts + contrôles + stocker (push /stock + /registres) ; toutes transitions acceptent `date` optionnelle dans le body ; `getOrCreateStockArticle()` crée article à la volée avec `unite` ENUM validée |
| Routes API macération alcoolique | ✅ En test — `api/routes/api.php` : `/recettes-transfo/*` + `/mace-alcool/lots/*` |
| Dashboard macération alcoolique | ✅ En test — `DashboardMacerationAlcool.vue` (KPIs, alertes macération/maturation, lots en cours) |
| Recettes macération — liste | ✅ En test — `GestionRecettesMace.vue` (Tag PrimeVue, filtre statut, changement statut via menu popup + refs dynamiques, export PDF chef/complet, server-side filter par gamme_id, duplication) |
| Recettes macération — édition | ✅ En test — `EditionRecetteMace.vue` réécrite : layout sidebar pattern FicheTechnique fermeauberge, 6 sections, autocomplete ingrédients via `/stock` (stock_article_id), 6 sous-composants dans `mace/`, payload mace_alcool flat |
| Lots macération — liste | ✅ En test — `GestionLotsMace.vue` (filtres statut + filtre Tests, badge TEST, barre progression workflow) |
| Lots macération — création | ✅ En test — `CreationLotMace.vue` (versions validées + en_test, bandeau alerte lot test, autocomplete /stock) |
| Lots macération — fiche | ✅ En test — `FicheLotMace.vue` : bandeau TEST, chronologie complète (date_assemblage + date_mise_en_stock), Calendar inline horodatage avant chaque bouton de transition (date paramétrable), ingrédients éditables en statut preparation, dialog stocker avec AutoComplete /stock + Dropdown unité (UNITES_STOCK) + DLC + checkbox déclaration stock (lots test) |
| Lots de test macération alcoolique | ✅ En test — `lot_test TINYINT` sur `mace_alcool_lot`, dérivé statut `en_test` recette, toujours tracé /registres avec `[TEST]`, push /stock optionnel (checkbox `declarer_en_stock`) |
| Menu dynamique macération alcoolique | ✅ En test — `AppMenu.vue` ITEMS_SPECIFIQUES + `router/index.js` 6 routes |
| Gammes jus, séchage, macération huileuse, distillation | ⬜ À intégrer lors des sessions futures |

---

## Navigation & Architecture UI (validée juin 2026)

### Menu — deux niveaux

Le menu est à **deux niveaux** : une zone module puis une section par gamme active.

```
TRANSFORMATIONS
  Tableau de bord       /dashboard            ← DashboardTransfo — cross-gammes
  Gammes & Produits     /dashboard/gammes     ← CatalogueGammes — CRUD gammes

── CRUFITURE ──────────────────────────────────────── (section dynamique par gamme)
  Dashboard             /dashboard/crufiture
  Saveurs               /dashboard/crufiture/saveurs
  Recettes              /dashboard/crufiture/recettes
  Lots                  /dashboard/crufiture/lots
  Simulateur            /dashboard/crufiture/simulateur

── MACÉRATION ALCOOLIQUE ───────────────────────────── (section spécifique)
  Dashboard             /dashboard/maceration_alcool
  Recettes              /dashboard/maceration_alcool/recettes
  Lots                  /dashboard/maceration_alcool/lots

── [future gamme] ─────────────────────────────────── (items génériques)
  Dashboard             /dashboard/:slug
  Produits              /dashboard/:slug/produits
  Lots                  /dashboard/:slug/lots

PORTAIL
  Retour ferme
```

**Menu dynamique :** `AppMenu.vue` lit le store Pinia `gammeStore` (`src/stores/gammeStore.js`). `CatalogueGammes.vue` appelle `gammeStore.charger()` après chaque mutation — le menu se met à jour instantanément sans rechargement de page. Chaque gamme active génère une section. Crufiture et macération alcoolique ont des items spécifiques dans `ITEMS_SPECIFIQUES` ; les futures gammes ont des items génériques (Produits, Lots). Si une gamme est inactive, sa section est masquée.

`ITEMS_SPECIFIQUES` dans `AppMenu.vue` :
```javascript
maceration_alcool: [
    { label: 'Dashboard',  icon: 'pi pi-fw pi-chart-pie', to: '/dashboard/maceration_alcool' },
    { label: 'Recettes',   icon: 'pi pi-fw pi-book',      to: '/dashboard/maceration_alcool/recettes' },
    { label: 'Lots',       icon: 'pi pi-fw pi-list',      to: '/dashboard/maceration_alcool/lots' },
],
```

### Palette couleurs par gamme

Les couleurs sont assignées par convention — ne pas laisser l'utilisateur choisir librement. Utilisées pour les badges gamme, les icônes de card, et les indicateurs visuels.

| Gamme | Couleur principale | Fond badge |
|-------|-------------------|------------|
| Crufiture | `#1D9E75` (teal) | `#E1F5EE` |
| Jus de fruit | `#BA7517` (amber) | `#FAEEDA` |
| Séchage | `#639922` (vert) | `#EAF3DE` |
| Macération alcool | `#7F77DD` (violet) | `#EEEDFE` |
| Macération huile | `#D4537E` (rose) | `#FBEAF0` |
| Distillation | `#378ADD` (bleu) | `#E6F1FB` |

Ces couleurs sont **hardcodées par slug** dans le frontend — pas de colonne couleur en BDD. Utiliser `style="background: #xxx"` (jamais `class="bg-*-50"`, absent du build Tailwind Hostinger).

### Dashboard global vs dashboard gamme

- **`DashboardTransfo.vue`** (`/dashboard`) : KPIs cross-gammes (lots actifs toutes gammes, stock total, production saison, gammes actives), mini-listes lots en cours + dernières mises en stock avec badge gamme coloré, bande récapitulative gammes.
- **`DashboardCrufiture.vue`** (`/dashboard/crufiture`) : dashboard spécifique crufiture — contenu actuel inchangé, juste déplacé.
- Les dashboards des futures gammes seront créés lors des sessions d'intégration de chaque gamme.

---

## Conventions spécifiques à ce module

- Le fichier `src/plugins/axiosCrufiture.js` est conservé avec ce nom (interne) même si l'URL a changé
- `$prefix` dans `api/routes/api.php` vient de `$_ENV['CRUFITURE_FOLDER']` — ne jamais hardcoder `/crufiture` ou `/transformation`
- Les contrôleurs sont dans `api/controllers/` — pas de sous-dossier par domaine
- La méthode `calculerKrencker()` est privée et ne doit jamais être déplacée hors de `LotController.php`
- Les vues admin et production sont dans des dossiers séparés — ne pas mélanger
- Les couleurs par gamme sont hardcodées dans le frontend par slug (voir section Navigation) — ne pas créer de colonne couleur dans `transfo_gamme`
- `src/stores/gammeStore.js` — store Pinia partagé entre `AppMenu.vue` et `CatalogueGammes.vue`. Appeler `gammeStore.charger()` après toute mutation de gamme pour synchroniser le menu instantanément

---

## Documents de référence

### Docs transverses à tous les modules — `../ferme/docs/Modules/`

```
../ferme/docs/Modules/
├── ECOSYSTEME-PEYROUNET.md             ← ① lire en premier — contexte global ferme + guide de décision
├── ARCHITECTURE-PEYROUNET.md           ← ② architecture technique de l'écosystème en production
├── API-INTER-MODULES.md                ← contrat inter-services complet avec exemples de code
├── SPEC-FERME-WIDGET.md                ← spec widget cockpit /ferme
├── GUIDE-NOUVEAU-MODULE.md             ← créer un module from scratch
├── ONBOARDING-CLAUDE-NOUVEAU-PROJET.md ← checklist démarrage sur un projet existant
├── GUIDE-OCR-CONSOMMATEUR.md           ← utiliser le service OCR depuis un module
└── UI-PATTERNS.md                      ← patterns visuels Vue/PrimeVue transverses
```

---

## Changelog

| Date | Modifications |
|------|---------------|
| juin 2026 | Horodatage transitions macération + unités stock — `mace_alcool_lot` : 2 nouvelles colonnes `date_assemblage DATETIME NULL` et `date_mise_en_stock DATETIME NULL` (SQL appliqué). Toutes les méthodes de transition de `LotMaceAlcoolController.php` acceptent une `date` optionnelle dans le body (NOW() par défaut). `stocker()` enregistre `date_mise_en_stock`. `FicheLotMace.vue` : Calendar inline `showTime` devant chaque bouton de transition (pré-rempli à l'heure courante), chronologie complète avec `date_assemblage` et `date_mise_en_stock`, Dropdown `UNITES_STOCK` au lieu d'InputText libre pour l'unité des nouvelles bouteilles, défaut `'piece'` (ENUM `/stock` : `kg`, `L`, `piece`, `m`, `kWh` — `'bouteille'` n'existe pas). `transfo_lot_produit` : `stock_article_id INT NULL` + `libelle VARCHAR(255) NULL` + `produit_id` devenu nullable (bypass `transfo_produit` pour macération). Convention `UNITES_STOCK` ajoutée dans les conventions frontend. |
| juin 2026 | Bug fixes crufiture — `LotController.php` + `RecetteController.php` : `JOIN rp_produit` (table supprimée) remplacé par `LEFT JOIN cruf_stock_memoire_ingredient sm … LEFT JOIN stock_article sa` pour récupérer le libellé des ingrédients. Noms de tables corrigés dans CLAUDE.md : `cruf_lot_ingredient` → `cruf_lot_fruit`, `cruf_lot_releve` → `cruf_releve_evaporation`, `cruf_lot_jarre` → `cruf_jarre`, `cruf_lot_controle` → `cruf_controle`. Fix `EditionRecette.vue` : slot `#item` vuedraggable avait 2 éléments racines (erreur "Item slot must have only one child") → enveloppés dans un `<div>`. |
| juin 2026 | Lots de test macération alcoolique — `lot_test TINYINT` sur `mace_alcool_lot` (SQL à appliquer : `ALTER TABLE mace_alcool_lot ADD COLUMN lot_test TINYINT NOT NULL DEFAULT 0 AFTER avec_assemblage`). Backend `LotMaceAlcoolController.php` : accepte versions `en_test` à la création, dérive `lot_test` du statut recette, `pushRegistres()` préfixe `[TEST]` si lot_test, `stocker()` push /stock conditionnel via `declarer_en_stock`. Frontend : `CreationLotMace.vue` (filtre versions `en_test` + `validee`, bandeau alerte lot test, autocomplete migré `/prix` → `/stock`), `GestionLotsMace.vue` (filtre Tests + badge TEST), `FicheLotMace.vue` (bandeau TEST, checkbox `declarer_en_stock` dans dialog stocker, payload `bouteilles:` correct). Fix focus-loss `MACE_Protocole.vue` : `item-key="_uid"` stable au lieu de `item-key="description"` — plus de destruction du DOM à chaque frappe. |
| juin 2026 | `EditionRecetteMace.vue` réécrite avec pattern sidebar FicheTechnique (fermeauberge) — 6 sous-composants `MACE_*.vue` dans `src/views/admin/mace/` (Identification, Paramètres, Ingrédients, Protocole, Contrôles, Valorisation). `GestionRecettesMace.vue` : `<Tag>` PrimeVue, filtre statut, changement statut depuis liste (menu popup + refs dynamiques Vue 3), export PDF `chef`/`complet`, server-side filter par `gamme_id`. Bug fixes : autocomplete ingrédients migré `/prix` → `/stock` (retourne `stock_article_id` réel, pas `prix_article.id`), payload `mace_alcool_*` envoyé en flat (fix undefined côté PHP). Colonne `description TEXT` de `transfo_recette_version` documentée (champ valorisation/provenance). |
| juin 2026 | Gamme macération alcoolique déployée et en test — SQL appliqué en production (`doc/schema-mace-alcool.sql` : `transfo_recette_*`, `mace_alcool_recette_version`, `mace_alcool_lot`, ALTER `transfo_lot_produit` dlc). Backend : `RecetteTransfoController.php` (CRUD recettes tronc commun + extension mace_alcool ON DUPLICATE KEY, blocage suppression si lot actif), `LotMaceAlcoolController.php` (workflow 6 statuts, genererNumeroLot MA[YY][NNNN], syncStatutTransfoLot, declarerEntreeProduitFini, pushRegistres non-bloquant). Routes `api.php` : `/recettes-transfo/*` + `/mace-alcool/lots/*`. Frontend : `DashboardMacerationAlcool.vue` (KPIs + alertes), `GestionRecettesMace.vue` (liste versionnée + duplication), `EditionRecetteMace.vue` (4 sections : info, paramètres mace, ingrédients autocomplete `/prix/inter/articles`, protocole phases/étapes + changement statut), `GestionLotsMace.vue` (filtres + barre progression), `CreationLotMace.vue` (recette validée + ingrédients autocomplete), `FicheLotMace.vue` (chronologie, transitions workflow, contrôles qualité ABV/brix/aspect, dialog stocker bouteilles+DLC). Menu `AppMenu.vue` + 6 routes `router/index.js`. |
| juin 2026 | Architecture gamme macération alcoolique validée — recettes tronc commun `transfo_recette_*` (pattern fermeauberge, ingrédients directs `stock_article_id`), extension `mace_alcool_recette_version` (duree_maceration_cible_j, duree_maturation_cible_j, abv_cible_pct, brix_cible, avec_assemblage), extension `mace_alcool_lot` (workflow 6 statuts : preparation→en_maceration→filtration→assemblage→maturation→stock, horodatages filtration+maturation, bridge transfo_lot obligatoire), ALTER `transfo_lot_produit` (ajout `dlc DATE`), sorties lot libres au stocker() (formats bouteilles inconnus à l'avance), format numéro lot `[PRÉFIXE][YY][NNNN]` par gamme (CR, JU, SE, MA, MH, DI), push `/registres` obligatoire au stocker() pour conformité bio, `source_service` = slug gamme. |
| juin 2026 | UI multi-gammes mise en production — `DashboardTransfo.vue` (dashboard cross-gammes, `GET /dashboard-transfo`), `CatalogueGammes.vue` (CRUD gammes, toggle actif/inactif), `FicheGamme.vue` (édition gamme + produits avec autocomplete `/stock`), `gammeStore.js` Pinia (menu dynamique temps réel), `GammeController`, `ProduitTransfoController`, `DashboardTransfoController`, migration routes `/dashboard/crufiture/*` complète, fix `AppTopbar.vue` (logo), fix `AppMenu.vue` (gamme.libelle + store). État développement mis à jour. |
| juin 2026 | Architecture UI validée — section Navigation & Architecture UI ajoutée : menu à deux niveaux dynamique par gamme, palette couleurs par gamme (6 gammes), structure routes migrée `/dashboard/crufiture/*`, 3 nouvelles vues (DashboardTransfo, CatalogueGammes, FicheGamme), routes API gammes + produits (GammeController, ProduitTransfoController), état développement mis à jour. |
| juin 2026 | Contenu spécifique ajouté — rôle, BDD (cruf_* + transfo_*), routes API, workflow lot, Krencker, vues Vue, inter-services, état développement, conventions. Renommage module /crufiture → /transformation reflété. |
| mai 2026 | Création scaffold — bloc AVANT TOUTE CHOSE, conventions universelles, docs transverses. |
