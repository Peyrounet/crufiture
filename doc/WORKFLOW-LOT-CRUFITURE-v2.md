# Workflow — Gestion des lots /crufiture
**v2 — 4 avril 2026**
*À fournir en début de toute discussion sur les lots, le suivi de production et la PWA mobile*

---

## Vue d'ensemble

Un lot est l'unité centrale de production de crufiture. Chaque plaque de production = un lot. Plusieurs lots peuvent coexister simultanément (plusieurs plaques en parallèle).

```
[création — bloc 1 sauvegardé]
    ↓
preparation  ←─── fiche en cours de remplissage (4 blocs progressifs)
    ↓
en_repos     ←─── bloc 4 complet, lot en chambre froide, prêt à démarrer
    ↓
production   ←─── pesées en cours (bureau + PWA mobile)
    ↓
stock        ←─── mise en jarres, contrôle qualité obligatoire

    ↕ possible à tout moment (preparation, en_repos, production)
abandonné    ←─── lot jeté/perdu, traçabilité conservée
```

---

## Numéro de lot

- Format : `YY` + séquentiel 4 chiffres → ex: `260001`
- Généré automatiquement par le backend à la **première sauvegarde** (fin bloc 1)
- Affiché immédiatement et en permanence en haut de la fiche dès sa création
- C'est la référence que l'utilisateur note sur son plateau, sa jarre, son étiquette
- Séquentiel remis à `0001` au 1er janvier de chaque année
- Logique PHP : `SELECT MAX(numero_lot) WHERE numero_lot LIKE 'YY%'` → incrémenter ou démarrer à `YY0001`
- Unique, jamais réutilisé (même si lot abandonné)

---

## Migration SQL — statuts

```sql
ALTER TABLE cruf_lot
MODIFY COLUMN statut ENUM('preparation','en_repos','production','stock','abandonné')
NOT NULL DEFAULT 'preparation';
```

Si des lots existent déjà en prod :
```sql
UPDATE cruf_lot SET statut = 'preparation' WHERE statut IN ('formule','preparation');
UPDATE cruf_lot SET statut = 'production'  WHERE statut = 'en_production';
UPDATE cruf_lot SET statut = 'stock'       WHERE statut IN ('mis_en_pot','controle','archive');
```

---

## Fiche lot — phase préparation

**Statut : `preparation`**
**Interface : bureau uniquement**
**La fiche est entièrement modifiable tant que le statut est `preparation` ou `en_repos`.**
**Elle est verrouillée en lecture seule dès que le statut passe à `production`.**

La fiche est une page unique avec 4 blocs qui se déverrouillent progressivement.
Sauvegarde possible à tout moment — autant de fois que nécessaire.
L'utilisateur peut quitter et revenir reprendre la fiche où il en était.

---

### Bloc 1 — Identité

**Toujours éditable.**
**Sauvegarde → génère le numéro de lot (première fois uniquement).**

Champs :
- `date_production` — date du jour par défaut, modifiable
- `saveur_id` — Dropdown → `cruf_saveur`
- `recette_id` — Dropdown filtré sur la saveur choisie → `cruf_recette` — **obligatoire**
- `installation` — texte libre (ex: "Inox", "Plastique")

La recette sélectionnée affiche en lecture la liste de ses ingrédients (pivot, fruits, additifs) avec leurs proportions.

**Déverrouille le bloc 2** quand : saveur + recette + date renseignés.

---

### Bloc 2 — Pivot

**Déverrouillé quand bloc 1 complet.**
Correspond au premier aller en cuisine : préparer le fruit pivot.

Ligne pivot (issue de `cruf_recette_ingredient` type=`pivot`) :
- Nom du fruit — affiché en lecture (depuis `rp_produit.libelle_canonique`)
- `poids_brut_kg` — saisi (fruit brut avant préparation)
- `poids_pulpe_kg` — saisi (après préparation du pivot)
- `poids_base_kg` — pré-rempli avec `poids_pulpe_kg`, modifiable (si jus retiré sur le pivot)
- `fournisseur` — texte libre, traçabilité
- `origine` — texte libre, traçabilité

Stocké dans `cruf_lot_fruit` (ligne type=`pivot`).

**Calcul à rebours** (optionnel, affiché si ≥ 1 lot antérieur en `stock` avec `poids_reel_kg` renseigné pour la même saveur) — aide contextuelle, non bloquant.

**Déverrouille le bloc 3** quand : `poids_base_kg` du pivot renseigné.

---

### Bloc 3 — Autres ingrédients

**Déverrouillé quand `poids_base_kg` du pivot renseigné.**
Correspond au retour en cuisine : préparer et ajouter les autres ingrédients.

#### Fruits non-pivot (`type = 'fruit'`)

Pour chaque fruit non-pivot :

| Champ | Description |
|---|---|
| Nom | Affiché en lecture (depuis `rp_produit.libelle_canonique`) |
| Proportion | Affiché en lecture (`pct_base`% du pivot) |
| `poids_base_kg` | Calculé : `poids_base_kg_pivot × pct_base / 100` — affiché, non modifiable |
| `poids_pulpe_kg` | Pré-rempli avec `poids_base_kg` calculé, modifiable (si jus retiré) |
| `poids_brut_kg` | Saisi par l'utilisateur (traçabilité) |
| `fournisseur` | Texte libre, traçabilité |
| `origine` | Texte libre, traçabilité |

Stocké dans `cruf_lot_fruit`.

**Totaux fruits** (pivot + non-pivot) calculés automatiquement, lecture seule :
- `poids_brut_kg` total = somme des `poids_brut_kg` pivot + fruits
- `poids_pulpe_kg` total = somme des `poids_pulpe_kg` pivot + fruits
- `poids_base_kg` total = somme des `poids_base_kg` pivot + fruits

Ces totaux alimentent `cruf_lot.poids_brut_kg`, `cruf_lot.poids_pulpe_kg`, `cruf_lot.poids_base_kg`.
`cruf_lot.poids_base_kg` est le `base_kg` utilisé dans la formule Krencker.

#### Additifs (`type = 'additif'`)

Affichés dans le bloc 3 dès le départ, **grisés** tant que `poids_base_kg` total fruits n'est pas connu.
**Déverrouillés et calculés** dès que tous les `poids_base_kg` des fruits non-pivot sont renseignés.

Pour chaque additif :

| Champ | Description |
|---|---|
| Nom | Affiché en lecture (depuis `rp_produit.libelle_canonique`) |
| Proportion | Affiché en lecture (`pct_base`% de la base totale fruits) |
| `poids_base_kg` | Calculé : `poids_base_kg_total_fruits × pct_base / 100` — affiché, confirmable |

Un additif = un poids net à ajouter au mélange (cannelle, jus de citron...).
Pas de `poids_brut_kg` ni `poids_pulpe_kg` pour les additifs.
Le poids des additifs **n'entre pas** dans les totaux `poids_brut_kg`, `poids_pulpe_kg`, `poids_base_kg` du lot.

Stocké dans `cruf_lot_fruit` (`poids_base_kg` uniquement).

**Déverrouille le bloc 4** quand : tous les `poids_brut_kg` des fruits non-pivot saisis.

---

### Bloc 4 — Krencker

**Déverrouillé quand tous les `poids_brut_kg` non-pivot saisis.**
Correspond au dernier retour en cuisine : mélange final prêt, brix mesuré.

Les trois premiers champs sont les totaux fruits du bloc 3, en lecture seule :
- `poids_brut_kg` — total fruits, lecture seule
- `poids_pulpe_kg` — total fruits, lecture seule
- `poids_base_kg` — total fruits, lecture seule (= `base_kg` dans la formule Krencker)

Champs à saisir :
- `brix_fruit` — Brix mesuré au réfractomètre sur le mélange global (°Bx)
- `brix_cible` — pré-rempli depuis la saveur, modifiable
- `pct_fructose` — pré-rempli depuis la saveur, modifiable
- `pa_cible` — pré-rempli depuis la saveur, modifiable

Résultats Krencker affichés en temps réel (même logique que `SimulateurFormulation.vue`) :
- `cible_kg`, `sa_kg`, `fructose_kg`, `saccharose_kg`, `masse_totale_kg`, `evaporation_kg`, `pa_etiquette`

Ces valeurs sont stockées dans `cruf_lot` à la sauvegarde.

**Bouton "Mettre en repos"** — disponible quand `brix_fruit` renseigné et calculs cohérents :
- `poids_base_kg` ≤ `poids_pulpe_kg`
- `brix_fruit` < `brix_cible`
- → Statut passe à `en_repos`
- → Fiche reste modifiable

---

## Phase en_repos

**Statut : `en_repos`**
**La fiche reste modifiable.**

Le lot est en chambre froide, en attente de démarrage des pesées.
Visible dans la liste des lots avec badge bleu.
Accessible depuis le menu "Suivi de production" pour démarrer les pesées.

**Bouton "Démarrer la production"** (depuis le menu suivi ou la fiche) :
- → Statut passe à `production`
- → **Fiche verrouillée définitivement en lecture seule**

---

## Phase production

**Statut : `production`**
**Fiche verrouillée — aucune modification des paramètres possible.**
**Interface bureau : fiche lot en lecture + formulaire ajout pesée**
**Interface mobile : PWA `/crufiture/production`**

### Valeurs clés affichées

- `cible_kg` — **poids cible à atteindre** (valeur opérationnelle principale)
- `masse_totale_kg` — masse initiale posée sur la plaque
- `evaporation_kg` — eau à évaporer
- `fructose_kg` — fructose pesé
- `saccharose_kg` — saccharose pesé

### Saisie d'un relevé de pesée

Champs :
- `heure` — heure actuelle par défaut, modifiable
- `poids_brut_kg` — poids plateau lu sur la balance (tare incluse)
- `tare_kg` — poids de la plaque vide (ressaisi à chaque relevé — non stocké)
- `meteo` — texte libre optionnel
- `remarque` — optionnel

Calculs affichés immédiatement :
- Poids net = `poids_brut_kg - tare_kg`
- Reste à évaporer = `poids_net - cible_kg`
- Si reste ≤ 0 → alerte "Poids cible atteint"

**Note schéma :** `cruf_releve_evaporation.poids_brut_kg` stocke le **poids net** (tare déjà déduite côté frontend).

### Indicateur de progression

`(masse_totale_kg - poids_net_dernier_relevé) / evaporation_kg × 100%`

### Transition vers stock

Requiert : ≥ 1 relevé avec poids net ≤ `cible_kg`.
Bouton "Passer en stock" → formulaire mise en jarres + contrôle qualité obligatoire.

---

## Phase stock

**Statut : `stock`**
**Interface : bureau uniquement**

### Mise en jarres

- `poids_reel_kg` — poids réel total mis en pot (saisi)
- `cruf_jarre` : 1 à 3 jarres, par jarre : `poids_initial_kg` + note optionnelle
- Warning si somme des jarres ≠ `poids_reel_kg` (écart > 100g)

### Contrôle qualité

Au moins 1 obligatoire à la mise en stock. Contrôles suivants libres (J+7, J+30...).

Champs (`cruf_controle`) :
- `date_controle`, `type_controle` (`mise_en_pot` | `suivi` | `autre`)
- `brix_mesure`, `aw_mesure`, `ph_mesure` (au moins 1 requis)
- `aspect`, `remarque`

### Sorties (hors scope v1)

Prélèvements par poids sur les jarres (`cruf_jarre.poids_actuel_kg`).

---

## Liste des lots — `/dashboard/lots`

- **Recherche** par numéro de lot
- **Filtre** par saveur
- **Tri** par statut (ordre de priorité) puis date décroissante :
  1. `production` — en cours, priorité maximale
  2. `en_repos` — attend d'être lancé
  3. `preparation` — en cours de saisie
  4. `stock` — terminé
  5. `abandonné` — en bas

Badge statuts :
- `preparation` → gris
- `en_repos` → bleu
- `production` → orange
- `stock` → vert
- `abandonné` → rouge

---

## Menu "Suivi de production"

Affiche uniquement les lots `en_repos` et `production` :
- `en_repos` → bouton "Démarrer les pesées"
- `production` → bouton "Continuer les pesées"

---

## Interface mobile — PWA `/crufiture/production`

**Accès :** `https://peyrounet.com/crufiture/production`
**Hors layout admin** — pas de sidebar, pas de topbar. Mobile-first, gros boutons.

`ProductionAccueil.vue` — lots `en_repos` et `production` :
- Card par lot : numéro, saveur, statut, dernier relevé (si production), progression
- `en_repos` → tap → démarre la production + formulaire pesée
- `production` → tap → formulaire pesée

`ProductionPesee.vue` — saisie d'un relevé :
- Affiche : numéro lot, saveur, `cible_kg`, dernier poids net connu
- Champs : heure, poids brut, tare plaque, météo (optionnel)
- Résultat immédiat : poids net, reste à évaporer
- Si cible atteinte : message + suggestion de passer en stock depuis le bureau

Règle `.htaccess` :
```apache
RewriteCond %{REQUEST_URI} ^/crufiture/production
RewriteRule ^crufiture/production(.*)$ /crufiture/production.html [L]
```

---

## Schéma `cruf_lot_fruit` — champs par type d'ingrédient

| Champ | Pivot | Fruit | Additif |
|---|---|---|---|
| `produit_id` | depuis recette | depuis recette | depuis recette |
| `type` | `pivot` | `fruit` | `additif` |
| `pct_base` | NULL | % du pivot | % de la base totale fruits |
| `poids_brut_kg` | saisi bloc 2 | saisi bloc 3 | — |
| `poids_pulpe_kg` | saisi bloc 2 | pré-rempli = `poids_base_kg` calculé, modifiable | — |
| `poids_base_kg` | pré-rempli = `poids_pulpe_kg`, modifiable | calculé (`poids_base_kg_pivot × pct_base / 100`) | calculé (`poids_base_kg_total_fruits × pct_base / 100`) |
| `fournisseur` | texte libre | texte libre | — |
| `origine` | texte libre | texte libre | — |

**Totaux → `cruf_lot` (calculés, lecture seule, pivot + fruits uniquement) :**
- `cruf_lot.poids_brut_kg` = `SUM(poids_brut_kg)` pivot + fruits
- `cruf_lot.poids_pulpe_kg` = `SUM(poids_pulpe_kg)` pivot + fruits
- `cruf_lot.poids_base_kg` = `SUM(poids_base_kg)` pivot + fruits → c'est le `base_kg` de la formule Krencker

Les additifs ont leur `poids_base_kg` stocké dans `cruf_lot_fruit` pour la traçabilité mais n'entrent dans aucun des trois totaux du lot.

---

## Routes API

| Route | Description |
|---|---|
| `GET /crufiture/api/lots` | Liste tous les lots (recherche numéro, filtre saveur, tri par statut) |
| `GET /crufiture/api/lots/suivi` | Lots `en_repos` et `production` — menu suivi + PWA |
| `GET /crufiture/api/lots/:id` | Fiche complète (fruits, relevés, contrôles, jarres) |
| `GET /crufiture/api/lots/:id/rendements` | Rendements historiques de la saveur (calcul à rebours) |
| `POST /crufiture/api/lots` | Créer un lot (bloc 1 — génère le numéro, statut `preparation`) |
| `PUT /crufiture/api/lots/:id` | Sauvegarder la fiche (blocs 1-4, statuts `preparation` et `en_repos` uniquement) |
| `PUT /crufiture/api/lots/:id/mettre-en-repos` | Transition `preparation` → `en_repos` |
| `PUT /crufiture/api/lots/:id/demarrer` | Transition `en_repos` → `production` (verrouille la fiche) |
| `PUT /crufiture/api/lots/:id/stocker` | Transition `production` → `stock` (avec jarres) |
| `PUT /crufiture/api/lots/:id/abandonner` | Passage à `abandonné` (note obligatoire) |
| `POST /crufiture/api/lots/:id/releves` | Ajouter un relevé d'évaporation |
| `POST /crufiture/api/lots/:id/controles` | Ajouter un contrôle qualité |

---

## Règles métier

| Règle | Détail |
|---|---|
| Numéro de lot | Généré à la première sauvegarde (bloc 1). Format `YY` + 4 chiffres. Remis à `0001` le 1er janvier. Jamais réutilisé. |
| Recette | Obligatoire — pas de lot sans recette. |
| Fiche modifiable | Statuts `preparation` et `en_repos` uniquement. |
| Fiche verrouillée | Dès passage en `production`. Aucune modification possible. |
| Transition → `en_repos` | Requiert : bloc 4 complet, `brix_fruit` renseigné, `poids_base_kg` ≤ `poids_pulpe_kg`, `brix_fruit` < `brix_cible`. |
| Transition → `production` | Aucune condition supplémentaire — le lot est prêt dès `en_repos`. |
| Transition → `stock` | Requiert : ≥ 1 relevé avec poids net ≤ `cible_kg` + ≥ 1 contrôle qualité. |
| Tare plaque | Saisie à chaque relevé — non stockée, soustraite côté frontend. |
| `poids_brut_kg` du lot | Calculé = somme pivot + fruits uniquement. Non modifiable. |
| `poids_pulpe_kg` du lot | Calculé = somme pivot + fruits uniquement. Non modifiable. |
| `poids_base_kg` du lot | Calculé = somme pivot + fruits uniquement. Non modifiable. C'est le `base_kg` de la formule Krencker. |
| Additifs | Poids net uniquement (`poids_base_kg`). Calculé sur `poids_base_kg` total fruits. Non inclus dans les totaux du lot. |
| Abandon | Possible depuis `preparation`, `en_repos`, `production`. Note obligatoire. Jamais de suppression physique. |
| Lots simultanés | Normal (1 plaque = 1 lot). Pas de contrainte applicative. |
| Sorties stock | Hors scope v1. |
