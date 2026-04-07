# Workflow — Gestion des lots /crufiture
**v3 — 5 avril 2026**
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

## Fiche lot — phase préparation

**Statut : `preparation`**
**Interface : bureau uniquement**
**La fiche est entièrement modifiable tant que le statut est `preparation` ou `en_repos`.**
**Elle est verrouillée en lecture seule dès que le statut passe à `production`.**

La fiche est une page unique avec 4 blocs qui se déverrouillent progressivement.
Sauvegarde possible à tout moment. L'utilisateur peut quitter et revenir reprendre la fiche.

---

### Bloc 1 — Identité

**Toujours éditable.**
**Sauvegarde → génère le numéro de lot (première fois uniquement).**

Champs :
- `date_production` — date du jour par défaut, modifiable
- `saveur_id` — Dropdown → `cruf_saveur`
- `recette_id` — Dropdown filtré sur la saveur choisie → `cruf_recette` — **obligatoire**
- `installation` — texte libre (ex: "Inox", "Plastique") — peut être complété au démarrage PWA

**Déverrouille le bloc 2** quand : saveur + recette + date renseignés.

---

### Bloc 2 — Pivot

**Déverrouillé quand bloc 1 complet.**

Ligne pivot (`cruf_recette_ingredient` type=`pivot`) :
- Nom du fruit — lecture (depuis `rp_produit.libelle_canonique`)
- `poids_brut_kg` — saisi
- `poids_pulpe_kg` — saisi
- `poids_base_kg` — pré-rempli avec `poids_pulpe_kg`, modifiable
- `fournisseur`, `origine` — texte libre, traçabilité

**Déverrouille le bloc 3** quand : `poids_base_kg` du pivot renseigné.

---

### Bloc 3 — Autres ingrédients

**Déverrouillé quand `poids_base_kg` du pivot renseigné.**

#### Fruits non-pivot (`type = 'fruit'`)

| Champ | Description |
|---|---|
| Nom | Lecture |
| Proportion | Lecture (`pct_base`% du pivot) |
| `poids_base_kg` | Calculé : `poids_base_kg_pivot × pct_base / 100` — lecture seule |
| `poids_pulpe_kg` | Pré-rempli = `poids_base_kg` calculé, modifiable |
| `poids_brut_kg` | Saisi |
| `fournisseur`, `origine` | Texte libre |

#### Additifs (`type = 'additif'`)

Grisés tant que `poids_base_kg` total fruits non renseigné.

| Champ | Description |
|---|---|
| Nom | Lecture |
| Proportion | Lecture (`pct_base`% de la base totale fruits) |
| `poids_base_kg` | Calculé : `poids_base_kg_total_fruits × pct_base / 100` |

**Déverrouille le bloc 4** quand : tous les `poids_brut_kg` des fruits non-pivot saisis.

---

### Bloc 4 — Krencker

**Déverrouillé quand tous les `poids_brut_kg` non-pivot saisis.**
**Reste éditable en statut `en_repos`** (les autres blocs sont verrouillés).

Totaux en lecture seule : `poids_brut_kg`, `poids_pulpe_kg`, `poids_base_kg` (pivot + fruits).

Champs à saisir :
- `brix_fruit` — Brix mesuré (°Bx) — peut être 0 (ex: ail)
- `brix_cible` — pré-rempli depuis la saveur, modifiable
- `pct_fructose` — pré-rempli depuis la saveur, modifiable
- `pa_cible` — pré-rempli depuis la saveur, modifiable

Résultats Krencker affichés en temps réel.

**Bouton "Mettre en repos"** — disponible quand `brix_fruit` renseigné et calculs cohérents.

---

## Phase en_repos

**Statut : `en_repos`**
**Fiche entièrement verrouillée — blocs 1, 2, 3 et 4 tous en lecture seule.**

Lot en chambre froide. Recette terminée, fruits et sucres déjà pesés et mélangés — aucune modification possible. Visible dans la liste et le menu suivi.

**Bouton "Démarrer la production"** (bureau ou PWA) :
→ Statut passe à `production` → fiche verrouillée définitivement.

---

## Phase production — démarrage

**Transition `en_repos` → `production` : `PUT /lots/:id/demarrer`**

### Depuis le bureau

Bouton de confirmation simple. Heure auto. Statut change.

### Depuis la PWA mobile — `ProductionDemarrage.vue`

Le formulaire de démarrage capture le contexte physique de production :

| Champ | Stockage | Obligatoire |
|---|---|---|
| Heure de début | `cruf_lot.heure_debut` | Auto (modifiable) |
| Installation | `cruf_lot.installation` | Non |
| **Tare plaque** (à vide) | `cruf_lot.tare_kg` | Oui |
| **Poids brut initial** (plateau + mélange) | → 1er relevé | Oui |
| Météo (ensoleillement, température, humidité, vent) | → 1er relevé | Non |

**La tare est mesurée une seule fois** — pesée de la plaque à vide avant de poser le mélange. Elle ne peut plus être mesurée ensuite. Stockée sur le lot, utilisée pour tous les calculs de poids net ultérieurs.

Actions au submit :
1. `PUT /lots/:id/demarrer` → passe en `production`, enregistre heure, installation, tare_kg
2. `POST /lots/:id/releves` → enregistre le premier relevé (poids net = brut - tare)

Si l'utilisateur revient en arrière sans soumettre → lot reste `en_repos`, aucun effet.

---

## Phase production — relevés de pesée

**Statut : `production`**
**Fiche verrouillée — aucune modification des paramètres possible.**
**Interface bureau + PWA mobile.**

### Tare et poids net

- `cruf_lot.tare_kg` — stockée sur le lot au démarrage, ne change pas
- Poids brut lu sur la balance = plateau + mélange restant
- **Poids net** = poids brut − tare_kg (calculé côté frontend)
- `cruf_releve_evaporation.poids_brut_kg` stocke le **poids net** (tare déjà déduite)
- La tare n'est **pas affichée** sur le formulaire de pesée — elle est déduite automatiquement en silence

### Valeurs clés de production

- `cible_kg` — **poids cible à atteindre** (valeur opérationnelle principale)
- `masse_totale_kg` — masse initiale posée sur la plaque
- `evaporation_kg` — eau à évaporer
- `fructose_kg`, `saccharose_kg` — sucres à peser

### Saisie d'un relevé — depuis la PWA `ProductionPesee.vue`

| Champ | Description |
|---|---|
| `heure` | Auto, modifiable |
| Poids brut plateau | Lu sur la balance — la tare est déduite silencieusement |
| `ensoleillement` | Boutons : ☁️ Couvert / 🌥 Voilé / ⛅ Mi-ombre / ☀️ Ensoleillé |
| `temperature` | °C |
| `humidite` | % HR |
| `vent_kmh` | km/h |
| `remarque` | Optionnel |

**Résultat affiché après validation** (pas en temps réel pendant la saisie) :
- Poids net
- Reste à évaporer
- Message si cible atteinte

### Indicateur de progression

`(masse_totale_kg - poids_net_dernier_relevé) / evaporation_kg × 100%`

### Historique des relevés

Accessible depuis `ProductionHistorique.vue` (lien depuis `ProductionPesee.vue`).
Lecture seule — liste du plus récent au plus ancien.
Non accessible depuis le bureau (FicheLot.vue affiche l'historique complet).

### Transition vers stock

Requiert : ≥ 1 relevé avec poids net ≤ `cible_kg`.
Depuis la PWA : bouton "Mettre en stock" → `ProductionStock.vue`.

### Abandon depuis la PWA

Menu caché "···" → 4 étapes de confirmation :
1. Avertissement : action irréversible
2. Saisie note obligatoire (raison)
3. Saisie du numéro de lot pour débloquer le bouton final
4. `PUT /lots/:id/abandonner`

---

## Phase stock — mise en jarres

**Depuis la PWA — `ProductionStock.vue`**

La mise en jarre se fait sur la zone de production. Workflow par jarre :

```
Pour chaque jarre :
  1. Peser la jarre à vide  → tare_kg
  2. Remplir la jarre
  3. Peser la jarre pleine  → poids_pleine_kg
  4. Contenu = pleine - tare (calculé et affiché immédiatement)
  5. Répéter jusqu'à ce que la plaque soit vide
```

Résumé final :
- `poids_reel_kg` (lot) = somme des contenus → calculé côté **backend** au save
- Perte = poids net dernière pesée − poids_reel_kg (recalculable, affiché pour info)

Pas de limite de nombre de jarres.

### Contrôle qualité obligatoire

Au moins 1 des 3 mesures requise : Brix, Aw, pH.
Type : `mise_en_pot`. Stocké dans `cruf_controle`.

### `PUT /lots/:id/stocker`

Payload :
```json
{
  "jarres": [
    { "tare_kg": 0.320, "poids_pleine_kg": 1.850 },
    { "tare_kg": 0.318, "poids_pleine_kg": 1.620 }
  ],
  "controle": {
    "type_controle": "mise_en_pot",
    "brix_mesure": 71.2,
    "aw_mesure": 0.7800,
    "aspect": "Gélifié, couleur homogène"
  }
}
```

Le backend calcule `poids_reel_kg` = somme des `(poids_pleine_kg - tare_kg)`.

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

## Interface mobile — PWA `/crufiture/production`

**Accès :** `https://peyrounet.com/crufiture/production`
**Même SPA** que le dashboard bureau — pas de point d'entrée HTML séparé.
**Layout** : `ProductionLayout.vue` — fond vert foncé `#0f1b0f`, header minimal sticky (titre dynamique + bouton retour + déconnexion), pas de sidebar.
**Auth** : cookie JWT monpanier, même guard Vue Router que le bureau.

### Routes PWA

```
/crufiture/production                      → ProductionAccueil.vue
/crufiture/production/lot/:id/demarrer     → ProductionDemarrage.vue
/crufiture/production/lot/:id              → ProductionPesee.vue
/crufiture/production/lot/:id/historique   → ProductionHistorique.vue
/crufiture/production/lot/:id/stocker      → ProductionStock.vue
```

### `ProductionAccueil.vue`

Lots `en_repos` et `production` — card par lot :
- `en_repos` → badge bleu, invite "Appuyer pour démarrer" → `/demarrer`
- `production` → badge orange, dernier poids net, barre de progression → `/lot/:id`

### `ProductionDemarrage.vue`

Formulaire 3 blocs : contexte (heure, installation) + pesée initiale (tare + brut → net) + météo (boutons ensoleillement + 3 champs numériques).

### `ProductionPesee.vue`

Formulaire relevé (action principale en haut) + résultat post-validation + dernier relevé + lien historique + menu abandon caché (4 étapes).

### `ProductionHistorique.vue`

Liste relevés du plus récent au plus ancien. Lecture seule.

### `ProductionStock.vue`

Saisie jarres (tare + pleine → contenu calculé) + résumé perte + contrôle qualité.

### PWA installable

- `manifest.json` : `start_url=/crufiture/production`, `theme_color=#0f1b0f`, `display=standalone`
- `index.html` : balises PWA complètes (manifest, apple-touch-icon ×4, theme-color)
- Icônes : générées depuis `icon-base.svg` via realfavicongenerator.net
- Installable iOS : Safari → Partager → Sur l'écran d'accueil
- Installable Android : Chrome → ⋮ → Installer l'application

---

## Routes API

| Route | Description |
|---|---|
| `GET /crufiture/api/lots` | Liste tous les lots (recherche numéro, filtre saveur, tri par statut) |
| `GET /crufiture/api/lots/suivi` | Lots `en_repos` et `production` — menu suivi + PWA (inclut tare_kg) |
| `GET /crufiture/api/lots/:id` | Fiche complète (fruits, relevés, contrôles, jarres) |
| `GET /crufiture/api/lots/:id/rendements` | Rendements historiques de la saveur (calcul à rebours) |
| `POST /crufiture/api/lots` | Créer un lot (bloc 1 — génère le numéro, statut `preparation`) |
| `PUT /crufiture/api/lots/:id` | Sauvegarder la fiche (prep : tout ; en_repos : Krencker uniquement) |
| `PUT /crufiture/api/lots/:id/mettre-en-repos` | Transition `preparation` → `en_repos` |
| `PUT /crufiture/api/lots/:id/demarrer` | Transition `en_repos` → `production` — accepte heure_debut, installation, tare_kg |
| `PUT /crufiture/api/lots/:id/stocker` | Transition `production` → `stock` (avec jarres tare+pleine) |
| `PUT /crufiture/api/lots/:id/abandonner` | Passage à `abandonné` (note obligatoire) |
| `POST /crufiture/api/lots/:id/releves` | Ajouter un relevé (poids net + météo structurée 4 champs) |
| `POST /crufiture/api/lots/:id/controles` | Ajouter un contrôle qualité |

---

## Mouvements de stock vers `/stock`

### Principe

`/crufiture` déclare ses mouvements vers le service `/stock` via `require_once` PHP direct (jamais HTTP).
Les mouvements sont non bloquants — une erreur `/stock` ne doit pas empêcher la transition du lot.

### Phase 1 — Consommation intrants (`preparation` → `en_repos`)

Déclenchée par `PUT /lots/:id/mettre-en-repos`.

Pour chaque ingrédient du lot ayant un lien dans `cruf_stock_memoire_ingredient` :
- Fruits (pivot + autres) → `sortie_consommation` de `poids_base_kg`
- Fructose → `sortie_consommation` de `fructose_kg` (calculé Krencker)
- Saccharose → `sortie_consommation` de `saccharose_kg` (calculé Krencker)
- Additifs → `sortie_consommation` de `poids_base_kg`

Les ingrédients sans lien dans `cruf_stock_memoire_ingredient` sont ignorés silencieusement (ex : fleurs de sureau).

**Unité transmise :** toujours `kg`. C'est `/stock` qui gère la conversion vers son unité de référence interne.

**Traçabilité :** `source_service = 'crufiture'`, `source_id = lot_id`.

**Abandon :** si le lot est abandonné depuis `en_repos` ou `production`, les consommations restent déclarées — les denrées ont bien été consommées. Une déclaration de perte manuelle reste possible depuis `/stock/dashboard`.

### Phase 2 — Entrée produit fini (`production` → `stock`)

Déclenchée par `PUT /lots/:id/stocker`.

- Article cible : `stock_article_id` lié à la saveur du lot (colonne `stock_article_id` sur `cruf_saveur`)
- Quantité : `poids_reel_kg` du lot (somme réelle des contenus jarres — pas `cible_kg` théorique)
- Type : `entree_production`
- Unité transmise : `kg`
- Traçabilité : `source_service = 'crufiture'`, `source_id = lot_id`

Si la saveur n'a pas de `stock_article_id` renseigné → mouvement ignoré silencieusement (log à prévoir).

### Liaison saveurs → stock

La colonne `stock_article_id` est portée par `cruf_saveur`. Elle se renseigne dans la **fiche saveur** (bureau) via un champ de recherche autocomplétion `GET /stock/api/articles?q=...`. La liaison est faite une fois, elle s'applique à tous les lots de cette saveur.

### Liaison ingrédients → stock

Table locale `cruf_stock_memoire_ingredient` — clé : `produit_id` (article `/prix`).
La liaison est optionnelle : seuls les ingrédients gérés en stock y figurent.
Elle se renseigne dans la **fiche recette** (bureau), champ optionnel par ingrédient.
Préremplissage automatique cross-recettes : si un `produit_id` est déjà mémorisé, le champ se prérempli à l'ouverture de toute recette qui utilise cet ingrédient.

### Jarres

Hors scope v1 — matériel réutilisable, pas de `sortie_consommation` pour les jarres.

---

## Règles métier

| Règle | Détail |
|---|---|
| Numéro de lot | Généré à la première sauvegarde (bloc 1). Format `YY` + 4 chiffres. Remis à `0001` le 1er janvier. Jamais réutilisé. |
| Recette | Obligatoire — pas de lot sans recette. |
| Fiche modifiable | Statut `preparation` uniquement. |
| Fiche verrouillée | Dès passage en `en_repos`. Aucune modification possible. |
| Transition → `en_repos` | Requiert : bloc 4 complet, `brix_fruit` renseigné, `poids_base_kg` ≤ `poids_pulpe_kg`, `brix_fruit` < `brix_cible`. Déclenche les mouvements de stock (consommations intrants). |
| Transition → `production` | Aucune condition supplémentaire — le lot est prêt dès `en_repos`. |
| Transition → `stock` | Requiert : ≥ 1 relevé avec poids net ≤ `cible_kg` + ≥ 1 contrôle qualité. Déclenche l'entrée du produit fini dans `/stock`. |
| Tare plaque | Saisie une fois au démarrage (PWA). Stockée sur `cruf_lot.tare_kg`. Non ressaisie aux relevés suivants. |
| `poids_brut_kg` relevé | Stocke le poids **net** (tare déjà déduite côté frontend). Ne pas confondre avec le brut lu sur la balance. |
| `poids_brut_kg` du lot | Calculé = somme pivot + fruits uniquement. Non modifiable. |
| `poids_pulpe_kg` du lot | Calculé = somme pivot + fruits uniquement. Non modifiable. |
| `poids_base_kg` du lot | Calculé = somme pivot + fruits uniquement. Non modifiable. C'est le `base_kg` de la formule Krencker. |
| Additifs | Poids net uniquement (`poids_base_kg`). Calculé sur `poids_base_kg` total fruits. Non inclus dans les totaux du lot. |
| Abandon | Possible depuis `preparation`, `en_repos`, `production`. Note obligatoire. Depuis la PWA : 4 étapes dont saisie du numéro de lot. Jamais de suppression physique. |
| Jarres | Sans limite applicative. `poids_reel_kg` lot = somme(`poids_pleine_kg - tare_kg`) calculé backend. |
| Lots simultanés | Normal (1 plaque = 1 lot). Pas de contrainte applicative. |
| Sorties stock | Hors scope v1. |
| Météo relevés | Structurée : temperature (°C), humidite (%), vent_kmh, ensoleillement (0-3). Prévu capteur Bluetooth. |
| Mouvements `/stock` | Non bloquants — une erreur `/stock` ne bloque pas la transition du lot. |
| Unité vers `/stock` | Toujours `kg` — c'est `/stock` qui gère la conversion vers son unité de référence. |
| Abandon après `en_repos` | Consommations intrants déjà déclarées — pas de contre-passation. Perte déclarable manuellement depuis `/stock/dashboard`. |

---

## Changelog

| Date | Modifications |
|------|---------------|
| 7 avril 2026 | Bloc 4 Krencker verrouillé en `en_repos` (recette terminée, sucres déjà pesés). Ajout section mouvements `/stock` (consommations intrants à `en_repos`, entrée produit fini à `stock`, liaisons saveur/ingrédients, jarres hors scope v1). Règles métier mises à jour. |
| 5 avril 2026 | v3 — PWA mobile, météo structurée, datetime_debut |
