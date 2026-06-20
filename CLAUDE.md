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
| BDD | MySQL partagé Hostinger — tables `cruf_*` (crufiture) + `transfo_*` (tronc commun) |
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
| `transfo_lot_produit` | Produits sortants d'un lot — 1..N, `quantite_produite` NULL avant stocker() |
| `transfo_controle` | Contrôles qualité (brix, aw, ph, abv selon gamme) |

**Règle `transfo_lot_produit` :**
- Gammes à produit connu à l'avance (crufiture, jus, macération) → ligne créée à la création du lot avec `quantite_produite = NULL`, renseignée au `stocker()`
- Distillation → lignes créées uniquement au `stocker()` (sorties inconnues à l'avance)

### Tables extension crufiture — `cruf_*`

| Table | Rôle |
|-------|------|
| `cruf_saveur` | Catalogue des saveurs — lié à `stock_article_id` et bridge `transfo_produit_id` |
| `cruf_recette` | Recettes de crufiture (versionnées) |
| `cruf_recette_ingredient` | Ingrédients d'une recette (→ prix_article) |
| `cruf_lot` | Lots crufiture — spécificités : tare_kg, brix_initial, formule Krencker. Bridge `transfo_lot_id` |
| `cruf_lot_ingredient` | Ingrédients du lot crufiture (avec rôles : fruit, pivot, additif) |
| `cruf_lot_releve` | Relevés de suivi en cours de production (météo, brix intermédiaire) |
| `cruf_lot_jarre` | Jarres produites à la fin du lot (tare_kg + poids_pleine_kg) |
| `cruf_lot_controle` | Contrôles qualité crufiture (aw, brix final) |

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

### Recettes

| Méthode | URI | Contrôleur | Action |
|---------|-----|-----------|--------|
| GET | `/recettes` | RecetteController | Liste avec versioning |
| GET | `/recettes/:id` | RecetteController | Fiche complète |
| POST | `/recettes` | RecetteController | Créer |
| POST | `/recettes/:id/dupliquer` | RecetteController | Fork nouvelle version |
| PUT | `/recettes/:id/complet` | RecetteController | Mise à jour complète (saveur + ingrédients) |
| PUT | `/recettes/:id` | RecetteController | Mise à jour partielle |
| DELETE | `/recettes/:id` | RecetteController | Supprimer |

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

**Numéro de lot :** format `YY0001` — séquentiel annuel remis à 0001 au 1er janvier. Ex: `260001`.

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
```

**⚠️ Lors de la migration des routes :** mettre à jour tous les `router.push()` et `<router-link to="">` dans les vues existantes. Vérifier aussi les liens hardcodés dans les breadcrumbs et les boutons "Retour".

Les vues `production/` exposent une interface mobile (PWA) distincte des vues admin desktop.
Voir `PWA-PATTERNS.md` pour les patterns mobile si on touche à ces vues.

---

## Dépendances inter-services

| Service | Usage | Méthode |
|---------|-------|---------|
| `/prix` | Autocomplétion articles dans recettes et lots | `GET /prix/api/inter/articles?q=xxx` (HTTP — frontend Vue) |
| `/stock` | Déclaration entrée stock au stocker() | `StockMouvementController::enregistrerMouvement()` (require_once) |
| `/ferme` | Widget cockpit | `GET /transformation/api/ferme-widget` exposé |

`source_service` dans les mouvements /stock :
- Mouvements historiques (avant juin 2026) : `'crufiture'`
- Nouveaux lots : `'crufiture'` conservé pour la gamme crufiture — à revoir lors de l'intégration des nouvelles gammes

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
| Gammes jus, séchage, macération, distillation | ⬜ À intégrer lors des sessions futures |

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

── [future gamme] ─────────────────────────────────── (items génériques)
  Dashboard             /dashboard/:slug
  Produits              /dashboard/:slug/produits
  Lots                  /dashboard/:slug/lots

PORTAIL
  Retour ferme
```

**Menu dynamique :** `AppMenu.vue` lit le store Pinia `gammeStore` (`src/stores/gammeStore.js`). `CatalogueGammes.vue` appelle `gammeStore.charger()` après chaque mutation — le menu se met à jour instantanément sans rechargement de page. Chaque gamme active génère une section. Crufiture a des items spécifiques (Saveurs, Recettes, Simulateur) ; les futures gammes ont des items génériques (Produits, Lots). Si une gamme est inactive, sa section est masquée.

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
| juin 2026 | UI multi-gammes mise en production — `DashboardTransfo.vue` (dashboard cross-gammes, `GET /dashboard-transfo`), `CatalogueGammes.vue` (CRUD gammes, toggle actif/inactif), `FicheGamme.vue` (édition gamme + produits avec autocomplete `/stock`), `gammeStore.js` Pinia (menu dynamique temps réel), `GammeController`, `ProduitTransfoController`, `DashboardTransfoController`, migration routes `/dashboard/crufiture/*` complète, fix `AppTopbar.vue` (logo), fix `AppMenu.vue` (gamme.libelle + store). État développement mis à jour. |
| juin 2026 | Architecture UI validée — section Navigation & Architecture UI ajoutée : menu à deux niveaux dynamique par gamme, palette couleurs par gamme (6 gammes), structure routes migrée `/dashboard/crufiture/*`, 3 nouvelles vues (DashboardTransfo, CatalogueGammes, FicheGamme), routes API gammes + produits (GammeController, ProduitTransfoController), état développement mis à jour. |
| juin 2026 | Contenu spécifique ajouté — rôle, BDD (cruf_* + transfo_*), routes API, workflow lot, Krencker, vues Vue, inter-services, état développement, conventions. Renommage module /crufiture → /transformation reflété. |
| mai 2026 | Création scaffold — bloc AVANT TOUTE CHOSE, conventions universelles, docs transverses. |
