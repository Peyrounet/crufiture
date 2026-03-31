# Workflow — Gestion des lots /crufiture
**v1 — 31 mars 2026**
*À fournir en début de toute discussion sur les lots, le suivi de production et la PWA mobile*

---

## Vue d'ensemble

Un lot est l'unité centrale de production de crufiture. Il existe dès la phase de préparation et suit un tunnel linéaire jusqu'au stock. Chaque plaque de production = un lot. Plusieurs lots peuvent coexister simultanément (plusieurs plaques en parallèle).

```
[création]
    ↓
préparation  ←─── saisie ingrédients, paramètres Krencker, fiche de production
    ↓
production   ←─── relevés de pesée réguliers (mobile), fin quand poids ≤ poids cible
    ↓
stock        ←─── mise en jarres, contrôle qualité obligatoire avant cette transition
    
    ↕ possible à tout moment
abandonné    ←─── lot jeté/perdu, traçabilité conservée (anti black)
```

---

## Numéro de lot

- Format : `YY` + séquentiel 4 chiffres → ex: `260001`
- Généré automatiquement par le backend à la création du lot
- Séquentiel remis à `0001` au 1er janvier de chaque année
- Logique PHP : `SELECT MAX(numero_lot) WHERE numero_lot LIKE 'YY%'` → incrémenter ou démarrer à `YY0001`
- Unique, jamais réutilisé (même si lot abandonné)

---

## Migration SQL — statuts

Le schéma v2 utilise encore les anciens statuts. Migration à exécuter avant tout dev lots :

```sql
ALTER TABLE cruf_lot 
MODIFY COLUMN statut ENUM('preparation','production','stock','abandonné') 
NOT NULL DEFAULT 'preparation';
```

Correspondance anciens → nouveaux statuts :

| Ancien | Nouveau | Note |
|--------|---------|------|
| `formule` | `preparation` | |
| `en_production` | `production` | |
| `mis_en_pot` | `stock` | |
| `controle` | `stock` | fusionné — contrôle = action, pas état |
| `archive` | `stock` | supprimé — stock est l'état final |

Si des lots existent déjà en prod avec les anciens statuts :
```sql
UPDATE cruf_lot SET statut = 'preparation' WHERE statut = 'formule';
UPDATE cruf_lot SET statut = 'production'  WHERE statut = 'en_production';
UPDATE cruf_lot SET statut = 'stock'       WHERE statut IN ('mis_en_pot','controle','archive');
```

---

## Phase 1 — Préparation

**Statut : `preparation`**
**Interface : bureau uniquement (`/dashboard/lots/nouveau` puis `/dashboard/lots/:id`)**

### Données saisies

**Identité du lot**
- Date de production (date du jour par défaut, modifiable)
- Saveur (Dropdown → `cruf_saveur`)
- Recette (Dropdown filtré sur la saveur choisie → `cruf_recette`) — optionnelle
- Installation (champ texte libre — ex: "Inox", "Plastique")

**Calcul à rebours** (optionnel, affiché si ≥ 1 lot précédent pour cette saveur)
- L'utilisateur saisit une quantité cible souhaitée (kg de crufiture)
- Calcul frontend :
  ```
  rendement_brut_pulpe = AVG(pulpe_kg / poids_brut_kg) sur lots précédents de la saveur
  rendement_pulpe_cruf = AVG(poids_reel_kg / base_kg)  sur lots précédents de la saveur
  poids_brut_nécessaire = cible / rendement_pulpe_cruf / rendement_brut_pulpe
  ```
- Affiché en aide contextuelle sous la liste des ingrédients de la recette
- Non bloquant — l'utilisateur peut ignorer et saisir ce qu'il veut

**Fruits utilisés** (`cruf_lot_fruit` — traçabilité)
- Liste dynamique (1 à N lignes)
- Par ligne : fruit (texte), fournisseur (texte), origine (texte), poids_kg
- Au minimum 1 ligne obligatoire
- Ordre libre

**Paramètres Krencker** (saisis en fin de préparation, quand le mélange est prêt)
- `poids_brut_kg` — fruits bruts avant nettoyage (kg)
- `pulpe_kg` — pulpe obtenue après préparation (kg)
- `base_kg` — part de pulpe utilisée pour ce lot (≤ pulpe_kg)
- `brix_fruit` — Brix mesuré au réfractomètre sur le mélange (°Bx)
- `brix_cible` — pré-rempli depuis la saveur, modifiable
- `pct_fructose` — pré-rempli depuis la saveur, modifiable
- `pa_cible` — pré-rempli depuis la saveur, modifiable

**Résultats calculés en temps réel** (même logique que SimulateurFormulation.vue)
- `cible_kg`, `sa_kg`, `fructose_kg`, `saccharose_kg`, `masse_totale_kg`, `evaporation_kg`, `pa_etiquette`
- Ces valeurs sont stockées dans `cruf_lot` à la validation

### Transition vers production

Bouton "Démarrer la production" — vérifie :
- ✅ Au moins 1 fruit renseigné
- ✅ Tous les paramètres Krencker renseignés
- ✅ Calculs cohérents (base_kg ≤ pulpe_kg, brix_fruit < brix_cible)

→ Sauvegarde tous les champs calculés dans `cruf_lot`, passe statut à `production`
→ Affiche la fiche de production (pesées à effectuer)

### Abandon en phase préparation

Bouton "Abandonner ce lot" — demande une note obligatoire (raison)
→ Statut `abandonné`, note stockée dans `note_production`

---

## Phase 2 — Production

**Statut : `production`**
**Interface bureau : fiche lot en lecture + formulaire ajout pesée**
**Interface mobile : PWA `/crufiture/production`**

### Fiche de production (bureau)

Affiche les valeurs clés issues des calculs Krencker :
- Masse totale à poser sur la plaque : `masse_totale_kg`
- **Poids cible à atteindre** : `cible_kg` (mis en avant — valeur opérationnelle clé)
- Eau à évaporer : `evaporation_kg`
- Fructose à peser : `fructose_kg`
- Saccharose à peser : `saccharose_kg`

Tableau des relevés d'évaporation (`cruf_releve_evaporation`) — liste chronologique :
- Heure, poids plateau (brut, tare non déduite), reste à évaporer calculé, météo

Indicateur de progression : `(masse_totale_kg - poids_actuel) / evaporation_kg × 100%`

### Saisie d'un relevé (bureau et mobile)

Champs :
- `heure` (heure actuelle par défaut, modifiable)
- `poids_brut_kg` — poids plateau lu sur la balance (tare incluse)
- `tare_kg` — poids de la plaque vide (ressaisi à chaque relevé — les plaques sont interchangeables)
- `meteo` — texte libre optionnel (ex: "Ensoleillé 28°C")
- `remarque` — optionnel

Calcul affiché immédiatement après saisie :
- Poids net = `poids_brut_kg - tare_kg`
- Reste à évaporer = `poids_net - cible_kg`
- Si reste ≤ 0 → alerte "Poids cible atteint — vous pouvez passer à la mise en stock"

**Note schéma :** `cruf_releve_evaporation.poids_brut_kg` stocke le poids net (tare déjà déduite). La tare est soustraite à la saisie, non stockée.

### Transition vers stock

Possible uniquement si au moins 1 relevé dont le poids net ≤ cible_kg.
Bouton "Passer en stock" → ouvre le formulaire de mise en jarres.

### Abandon en phase production

Bouton "Abandonner ce lot" — note obligatoire → statut `abandonné`.

---

## Phase 3 — Stock

**Statut : `stock`**
**Interface : bureau uniquement**

### Mise en jarres

Formulaire déclenché à la transition production → stock :
- Saisie du poids réel total mis en pot (`poids_reel_kg` dans `cruf_lot`)
- Création des jarres (`cruf_jarre`) : 1 à 3 jarres
  - Par jarre : `poids_initial_kg`, note optionnelle
  - Somme des jarres doit être cohérente avec `poids_reel_kg` (warning si écart > 100g)

### Contrôle qualité

Au moins 1 contrôle obligatoire (validé lors de la transition production → stock).
Contrôles suivants libres (J+7, J+30, à la demande).

Champs d'un contrôle (`cruf_controle`) :
- `date_controle` (aujourd'hui par défaut)
- `type_controle` : `mise_en_pot` (premier), `suivi`, `autre`
- `brix_mesure`, `aw_mesure`, `ph_mesure` (tous optionnels mais au moins 1 requis)
- `aspect` — description visuelle/texture
- `remarque`

### Sorties (hors scope v1)

Les sorties de stock (vente vrac, mise en pot 100g/300g) décrémentent `cruf_jarre.poids_actuel_kg`. Fonctionnalité à développer ultérieurement.

---

## Architecture des interfaces

### Interface bureau — `/dashboard/lots`

**`GestionLots.vue`** — liste de tous les lots
- Tableau avec : numéro, date, saveur, statut (badge coloré), poids_reel ou cible, actions
- Filtres : par statut, par saveur, par année
- Bouton "Nouveau lot" → `/dashboard/lots/nouveau`
- Clic sur un lot → `/dashboard/lots/:id`
- Badge statuts : preparation (gris), production (orange), stock (vert), abandonné (rouge)

**`CreationLot.vue`** — `/dashboard/lots/nouveau`
- Formulaire en 2 blocs sur la même page (pas de stepper) :
  - Bloc 1 : identité + saveur + recette + fruits (traçabilité) + calcul à rebours optionnel
  - Bloc 2 : paramètres Krencker + résultats temps réel (réutiliser la logique de SimulateurFormulation.vue)
- Bouton "Créer le lot" → sauvegarde en statut `preparation`, redirige vers `/dashboard/lots/:id`
- Le numéro de lot est généré par le backend à la création

**`FicheLot.vue`** — `/dashboard/lots/:id`
- Vue unique adaptée selon le statut
- En-tête : numéro lot, saveur, date, statut (badge), bouton Abandonner
- Contenu contextuel :
  - `preparation` → formulaire éditable (identité + fruits + Krencker) + bouton "Démarrer la production"
  - `production` → fiche de production (valeurs clés) + liste relevés + formulaire ajout relevé + bouton "Passer en stock"
  - `stock` → résumé complet + jarres + liste contrôles + formulaire ajout contrôle
  - `abandonné` → résumé en lecture seule + note d'abandon

### Interface mobile — PWA `/crufiture/production`

**Accès :** `https://peyrounet.com/crufiture/production`
**Hors layout admin** — pas de sidebar, pas de topbar. Interface plein écran, fond blanc.
**Mobile-first** — gros boutons, texte lisible au soleil.

**Règles .htaccess à ajouter** (en plus des règles SPA existantes) :
```apache
RewriteCond %{REQUEST_URI} ^/crufiture/production
RewriteRule ^crufiture/production(.*)$ /crufiture/production.html [L]
```
→ C'est un second point d'entrée HTML (`production.html`) avec son propre `main-production.js`, comme `/peyrounet/saisie`.

**Vues PWA :**

`ProductionAccueil.vue` — liste des lots en statut `production`
- Card par lot : numéro, saveur, dernier relevé (heure + poids), progression vers cible
- Si aucun lot en production : message "Aucune production en cours"
- Tap sur un lot → formulaire de pesée

`ProductionPesee.vue` — saisie d'un relevé pour un lot
- Affiche : numéro lot, saveur, poids cible, dernier poids connu
- Champs : heure (now), poids brut, tare plaque, météo (optionnel)
- Affiche le résultat immédiatement : poids net, reste à évaporer
- Si cible atteinte : message de succès + suggestion de passer en stock depuis le bureau
- Bouton retour → liste

**Auth PWA :** même cookie JWT que le dashboard. Si non connecté → redirect vers `/crufiture/` (login).

---

## Routes API à créer

| Route | Description |
|---|---|
| `GET /crufiture/api/lots` | Liste tous les lots (filtrables par statut, saveur, année) |
| `GET /crufiture/api/lots/en-production` | Liste lots en statut `production` — pour la PWA mobile |
| `GET /crufiture/api/lots/:id` | Fiche complète d'un lot (avec fruits, relevés, contrôles, jarres) |
| `POST /crufiture/api/lots` | Créer un lot (génère le numéro, statut `preparation`) |
| `PUT /crufiture/api/lots/:id` | Modifier les champs d'un lot en `preparation` |
| `PUT /crufiture/api/lots/:id/demarrer` | Transition `preparation` → `production` |
| `PUT /crufiture/api/lots/:id/stocker` | Transition `production` → `stock` (avec jarres) |
| `PUT /crufiture/api/lots/:id/abandonner` | Passage à `abandonné` (note obligatoire) |
| `POST /crufiture/api/lots/:id/fruits` | Ajouter/remplacer les fruits d'un lot |
| `POST /crufiture/api/lots/:id/releves` | Ajouter un relevé d'évaporation |
| `POST /crufiture/api/lots/:id/controles` | Ajouter un contrôle qualité |
| `GET /crufiture/api/lots/:id/rendements` | Rendements historiques de la saveur (calcul à rebours) |

---

## Règles métier récapitulées

| Règle | Détail |
|---|---|
| Numéro de lot | Généré backend à la création. Format `YY` + 4 chiffres. Remis à `0001` le 1er janvier. |
| Lot créé = lot tracé | Même abandonné, le lot conserve toutes ses données. Jamais de suppression physique. |
| Transition preparation→production | Requiert : ≥1 fruit, tous les paramètres Krencker, base_kg ≤ pulpe_kg |
| Transition production→stock | Requiert : ≥1 relevé avec poids net ≤ cible_kg + ≥1 contrôle qualité |
| Tare plaque | Saisie à chaque relevé — non stockée, soustraite à la volée |
| Calcul à rebours | Optionnel. Disponible si ≥1 lot antérieur avec poids_reel_kg renseigné pour la même saveur |
| Contrôle qualité | Multiple par lot. Au moins 1 obligatoire avant passage en stock. |
| Jarres | 1 à 3 par lot. Somme jarres ≈ poids_reel_kg (warning si écart > 100g) |
| Sorties stock | Par prélèvement en poids sur les jarres. Hors scope v1. |
| Plusieurs lots simultanés | Normal (1 plaque = 1 lot). Pas de contrainte applicative. |
