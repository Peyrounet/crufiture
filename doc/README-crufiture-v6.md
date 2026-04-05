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
| Statut | En production — v6 (saveurs + recettes + lots bureau complets, PWA mobile à faire) |

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
| BDD | MySQL partagé `u191509486_dbboutique` — tables préfixées `cruf_*` |
| Auth | Cookie JWT peyrounet.com via /monpanier |
| Thème | aura-light-amber depuis /monpanier/themes/ |
| CSS | PrimeFlex + layout SCSS copié depuis foretfeerique/src/assets/ |
| Drag & drop | `vuedraggable@^4.1.0` (ajouté dans package.json) |

---

## 3. Schéma BDD — tables cruf_*

9 tables dans la base `u191509486_dbboutique`. Référence : `schema_crufiture_v3.sql`. Migration v3 exécutée en prod le 4 avril 2026.

| Table | Rôle |
|---|---|
| `cruf_saveur` | Référentiel des saveurs avec paramètres de formulation par défaut (brix_cible, pa_cible, pct_fructose) |
| `cruf_recette` | Fiche technique de préparation liée à une saveur — plusieurs versions peuvent coexister |
| `cruf_recette_ingredient` | Ingrédients d'une recette — `type` pivot/fruit/additif, `pct_base`, lien obligatoire vers `rp_produit` |
| `cruf_recette_etape` | Étapes de préparation ordonnées et réordonnables |
| `cruf_lot` | Cœur du module — un lot = une session de production complète |
| `cruf_lot_fruit` | Ingrédients utilisés dans un lot avec poids et traçabilité |
| `cruf_jarre` | Stockage en jarres (1 à 3 par lot) avec poids initial et poids actuel |
| `cruf_releve_evaporation` | Relevés de pesée pendant la production (heure, poids net, météo) |
| `cruf_controle` | Contrôles qualité (Brix, Aw, pH) — plusieurs par lot, 1 obligatoire avant stock |

### cruf_lot — colonnes clés (v3)

- `poids_brut_kg`, `poids_pulpe_kg`, `poids_base_kg` — sommes pivot + fruits (sans additifs), calculées par l'appli
- `statut` — ENUM `preparation | en_repos | production | stock | abandonné`
- `brix_fruit` — peut être 0 (ex: ail) — ne jamais tester `> 0` pour valider

### cruf_lot_fruit — colonnes clés (v3)

- `produit_id` — FK rp_produit (peyrounet), libellé par jointure, jamais copié localement
- `type` — ENUM `pivot | fruit | additif`
- `pct_base` — NULL pour pivot ; % du pivot pour fruits ; % base totale fruits pour additifs
- `poids_brut_kg`, `poids_pulpe_kg` — pivot et fruits uniquement, NULL pour additifs
- `poids_base_kg` — poids net dans la formule Krencker

### Cycle de vie d'un lot

```
preparation → en_repos → production → stock
     ↕            ↕           ↕
  abandonné    abandonné  abandonné
```

- `preparation` : fiche entièrement modifiable (4 blocs)
- `en_repos` : lot en chambre froide — **seuls les paramètres Krencker modifiables** (brix_fruit, brix_cible, pct_fructose, pa_cible, note)
- `production` : pesées en cours — **fiche verrouillée définitivement**
- `stock` : mis en jarres, contrôle qualité effectué
- `abandonné` : lot perdu, jamais supprimé

### Numéro de lot

Format `YY` + séquentiel 4 chiffres (ex: `260001`). Généré à la première sauvegarde (`POST /lots`). Remis à `0001` au 1er janvier. Jamais réutilisé.

---

## 4. Formules de formulation Krencker

Vérifiées sur le lot betterave 250099. Toutes les valeurs matchent à 4 décimales.

### Entrées utilisateur

| Paramètre | Description |
|---|---|
| `poids_brut_kg` | Poids du fruit brut avant nettoyage/transformation (kg) |
| `poids_pulpe_kg` | Poids de pulpe obtenue après préparation (kg) |
| `poids_base_kg` | Part de pulpe utilisée (≤ poids_pulpe_kg). Si base < pulpe : du jus a été retiré. |
| `brix_fruit` | Brix mesuré au réfractomètre sur le mélange global (°Bx) — **peut être 0** (ex: ail) |
| `brix_cible` | Objectif de concentration finale (°Bx) — pré-rempli depuis la saveur |
| `pct_fructose` | % de fructose dans le sucre ajouté total — pré-rempli depuis la saveur |
| `pa_cible` | Paramètre de formulation en g de pulpe pour 100g de crufiture — pré-rempli depuis la saveur |

### Ordre de calcul

| Variable calculée | Formule | Description |
|---|---|---|
| `cible_kg` | `poids_base_kg × 100 / pa_cible` | Quantité théorique de crufiture produite |
| `total_sucre_kg` | `cible_kg × brix_cible / 100` | Sucre total nécessaire |
| `sucre_fruit_kg` | `brix_fruit × poids_base_kg / 100` | Sucre apporté par le fruit |
| `sa_kg` | `total_sucre_kg − sucre_fruit_kg` | Sucre à ajouter |
| `fructose_kg` | `sa_kg × (pct_fructose / 100)` | Part fructose |
| `saccharose_kg` | `sa_kg × (1 − pct_fructose / 100)` | Part saccharose |
| `masse_totale_kg` | `poids_base_kg + sa_kg` | Masse totale à déposer sur le plateau |
| `evaporation_kg` | `masse_totale_kg − cible_kg` | **Eau à évaporer ← VALEUR CLÉ DE PRODUCTION** |
| `pa_etiquette` | `poids_pulpe_kg × 100 / cible_kg` | PA réel sur l'étiquette (réglementaire) |

> `pa_etiquette` calculé sur la pulpe totale (pas sur la base). Peut dépasser `pa_cible` si du jus a été retiré — légalement valide.

> `brix_fruit` peut être 0. Calcul valide si `brix_fruit < brix_cible`. Ne jamais tester `brix_fruit > 0`.

### Vérification lot betterave 250099

| Variable | Valeur |
|---|---|
| poids_base_kg | 1,765 kg |
| brix_fruit | 12 °Bx |
| brix_cible | 70 °Bx |
| pa_cible | 68 g/100g |
| pct_fructose | 50% |
| cible_kg calculé | 2,5956 kg ✓ |
| sa_kg calculé | 1,6051 kg ✓ |
| evaporation_kg calculé | 0,7745 kg ✓ |
| masse_totale_kg calculé | 3,3701 kg ✓ |

### Calcul à rebours (planification)

Optionnel — disponible si ≥ 1 lot antérieur avec `poids_reel_kg` renseigné pour la même saveur.

```
rendement_brut_pulpe = AVG(poids_pulpe_kg / poids_brut_kg)  sur lots précédents
rendement_pulpe_cruf = AVG(poids_reel_kg  / poids_base_kg)  sur lots précédents
poids_brut_nécessaire = cible_souhaitée / rendement_pulpe_cruf / rendement_brut_pulpe
```

---

## 5. Routes API — toutes opérationnelles

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
| `GET /crufiture/api/lots` | Liste — `?numero=&saveur_id=` — tri par statut puis date |
| `GET /crufiture/api/lots/suivi` | Lots `en_repos` et `production` — menu suivi + PWA |
| `GET /crufiture/api/lots/:id` | Fiche complète (fruits + relevés + contrôles + jarres) |
| `GET /crufiture/api/lots/:id/rendements` | Rendements historiques saveur (calcul à rebours) |
| `POST /crufiture/api/lots` | Créer lot (bloc 1 — génère numéro, statut `preparation`) |
| `PUT /crufiture/api/lots/:id` | Sauvegarder fiche (prep : tout ; en_repos : Krencker uniquement) |
| `PUT /crufiture/api/lots/:id/mettre-en-repos` | `preparation` → `en_repos` |
| `PUT /crufiture/api/lots/:id/demarrer` | `en_repos` → `production` (verrouille définitivement) |
| `PUT /crufiture/api/lots/:id/stocker` | `production` → `stock` |
| `PUT /crufiture/api/lots/:id/abandonner` | Abandon avec note obligatoire (interdit depuis `stock`) |
| `POST /crufiture/api/lots/:id/releves` | Ajouter relevé — poids reçu = NET (tare déduite côté frontend) |
| `POST /crufiture/api/lots/:id/controles` | Ajouter contrôle qualité (production et stock) |

### Règles métier clés

- `PUT /lots/:id` en `en_repos` : seuls brix_fruit, brix_cible, pct_fructose, pa_cible, note_production acceptés. Poids et fruits ignorés.
- `PUT /lots/:id/demarrer` : accepte uniquement `en_repos` (pas `preparation`)
- `PUT /lots/:id/stocker` : requiert ≥1 relevé poids_net ≤ cible_kg + ≥1 contrôle qualité
- `POST /lots/:id/releves` : statut `production` uniquement

---

## 6. Structure des fichiers déployés

### Backend

```
crufiture/api/
├── bootstrap.php               ← copie foretfeerique avec logs adaptés. Ne jamais modifier.
├── index.php                   ← copie EXACTE foretfeerique. Ne jamais modifier.
├── routes/api.php              ← router v6
└── controllers/
    ├── PingController.php
    ├── FermeWidgetController.php   ← KPIs mis à jour v6
    ├── DashboardController.php
    ├── SaveurController.php
    ├── RecetteController.php
    └── LotController.php           ← réécrit v6, toutes les routes opérationnelles
```

### Frontend bureau

```
crufiture/src/
├── main.js
├── App.vue
├── assets/                         ← copie foretfeerique, ne pas modifier
├── layout/
│   ├── AppLayout.vue
│   ├── AppTopbar.vue
│   ├── AppMenu.vue                 ← Simulateur > Saveurs > Recettes > Lots > Suivi
│   ├── AppSidebar.vue
│   └── AppMenuItem.vue
├── plugins/
│   ├── axios.js                    ← baseURL /monpanier/api
│   ├── axiosCrufiture.js           ← baseURL /crufiture/api
│   └── axiosPeyrounet.js           ← baseURL /peyrounet/api, withCredentials: true
├── router/index.js                 ← routes lots présentes
├── stores/
├── components/PageCard.vue
└── views/admin/
    ├── DashboardCrufiture.vue
    ├── SimulateurFormulation.vue
    ├── GestionSaveurs.vue
    ├── GestionRecettes.vue
    ├── EditionRecette.vue
    ├── GestionLots.vue             ← liste + recherche numéro + filtre saveur (v6)
    ├── CreationLot.vue             ← bloc 1 uniquement + calcul à rebours (v6)
    └── FicheLot.vue                ← page unique adaptée au statut, validée (v6)
```

### Frontend PWA mobile (à créer)

```
crufiture/
├── production.html                 ← second point d'entrée HTML
└── src-production/
    ├── main-production.js
    └── views/
        ├── ProductionAccueil.vue   ← lots en_repos et production, progression
        └── ProductionPesee.vue     ← saisie relevé mobile-first
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

**Layout :** flexbox avec flex-wrap. Grilles internes en CSS grid natif `minmax(0, 1fr)`. InputNumber avec `inputClass="sim-input"` et `:deep(.sim-input) { width:100%; min-width:0 }`.

---

## 8. Gestion des saveurs

Page `/dashboard/saveurs` — CRUD complet sur `cruf_saveur`.

- Liste en cards avec avatar (2 initiales, couleur stable générée depuis le nom)
- Recherche live côté frontend
- Tri alphabétique géré côté API (`ORDER BY nom ASC`)
- Formulaire : nom, slug (auto-généré à la création uniquement), brix_cible, pa_cible, pct_fructose, note
- Suppression physique si aucun lot rattaché, soft-delete (`actif=0`) sinon
- Barre de recherche : `IconField` + `InputIcon`

---

## 9. Gestion des recettes

Page `/dashboard/recettes` — liste groupée par saveur, page dédiée `/dashboard/recettes/:id`.

- Recette liée à une saveur, plusieurs versions coexistent pour traçabilité
- Ingrédients : `type` pivot/fruit/additif géré par l'appli (non exposé UI)
- Chaque ingrédient référence obligatoirement `rp_produit` (jointure — pas de copie locale)
- Étapes : `cruf_recette_etape` — drag-and-drop via `vuedraggable`
- Duplication : `POST /recettes/:id/dupliquer` → nouvelle version (max+1)

---

## 10. Gestion des lots (v6 — validé)

### GestionLots.vue — `/dashboard/lots`

- Recherche par numéro (debounce 300ms, `?numero=`)
- Filtre par saveur (`?saveur_id=`)
- Tri côté API : production > en_repos > preparation > stock > abandonné via `FIELD()`
- Badge statuts : preparation (gris), en_repos (bleu), production (orange), stock (vert), abandonné (rouge)
- Colonne poids : poids_reel_kg si stock, sinon cible_kg

### CreationLot.vue — `/dashboard/lots/nouveau`

- Bloc 1 uniquement : saveur + recette (triée version décroissante) + date + installation
- Colonne droite : calcul à rebours si lots précédents en stock pour la saveur
- `POST /lots` → redirect immédiat vers `FicheLot` avec le numéro généré
- Recette obligatoire

### FicheLot.vue — `/dashboard/lots/:id`

Page unique adaptée au statut.

**Verrouillage par statut :**

| Bloc | preparation | en_repos | production |
|---|---|---|---|
| 1 — Identité | éditable | verrouillé | — |
| 2 — Pivot | éditable | verrouillé | — |
| 3 — Autres ingrédients | éditable (grisage progressif) | verrouillé | — |
| 4 — Krencker | éditable | **éditable** | — |

**Grisage progressif (bloc 3) :**
- Fruits non-pivot : grisés tant que `pivot.poids_base_kg` non renseigné
- Additifs : grisés tant que tous les `poids_base_kg` des fruits non-pivot inconnus

**Calculs temps réel :**
- `watch(pivot.poids_base_kg)` → recalcule `poids_base_kg` et `poids_pulpe_kg` des fruits non-pivot
- `watch(baseTotaleFruits)` → recalcule `poids_base_kg` des additifs
- `totauxTempsReel` computed → brut/pulpe/base + flags `brutComplet` et `pulpeComplet`
- Brut total affiché uniquement si `brutComplet`, pulpe totale si `pulpeComplet`
- `calc` Krencker computed utilise `totauxTempsReel.base`

**Alertes :**
- 🔴 Rouge : brut < pulpe, base > pulpe (pivot), pulpe < base (non-pivot)
- 🟠 Orange : jus retiré (base < pulpe)
- `erreursSaisie` computed → bloque bouton Enregistrer si anomalies

**Initialisation des fruits :**
Si `lot.fruits` est vide (nouveau lot), `charger()` appelle `initialiserFruitsDepuisRecette(recette_id)` → `GET /recettes/:id` → construit `lot.fruits` depuis `recette.ingredients`.

**Statut production :**
- Valeurs clés affichées (cible_kg, evaporation_kg, masse_totale_kg, fructose_kg, saccharose_kg)
- Formulaire relevé de pesée (heure + poids brut + tare non stockée + météo)
- Poids net = poids brut − tare, calculé côté frontend avant envoi
- Progression évaporation en ProgressBar
- Bouton "Passer en stock" si cible atteinte

**Statut stock :**
- Résumé poids réel vs cible
- Jarres avec poids initial
- Tableau contrôles qualité + bouton "Ajouter un contrôle"

### PWA mobile — `/crufiture/production`

Second point d'entrée HTML (hors layout admin). Mobile-first, gros boutons.
- `ProductionAccueil.vue` : liste lots `en_repos` et `production`, progression, tap → pesée
- `ProductionPesee.vue` : saisie rapide relevé (heure, poids brut, tare, météo optionnel)

---

## 11. Points d'attention pour les futures discussions

| Point | Règle |
|---|---|
| `bootstrap.php` / `index.php` | Copies de foretfeerique. Ne jamais modifier. |
| `$mysqli` | Variable de connexion BDD dans tous les controllers. Jamais `$conn`, jamais `$db`. |
| `ResponseHelper` | `echo ResponseHelper::jsonResponse($message, $status, $details, $statusCode)`. Toujours `echo` devant, toujours `use helpers\ResponseHelper` en tête. |
| `bind_param` types | Types valides : `s`, `i`, `d`, `b` uniquement. Pas d'espaces dans la chaîne. Un type invalide échoue silencieusement. |
| Cast TINYINT | Toujours `(int) $row['actif']`. PHP retourne une string `"1"` et Vue évalue `"1" === 1` à `false`. |
| Cast DECIMAL | Toujours `(float) $row['brix_cible']` etc. Même raison. |
| `brix_fruit = 0` | Valide (ail...). Tester `=== null` ou `=== ''` pour valider la saisie, jamais `<= 0`. |
| `v-for` + `v-model` | Toujours `v-model="monRef[idx].prop"` et non `v-model="item.prop"`. |
| `window` dans template Vue | Inaccessible directement. Toujours passer par une fonction dans `<script setup>`. |
| Vite base | `base: '/crufiture/'` dans `vite.config.js`. Déployer `dist/` dans `/crufiture/`. |
| Assets SCSS | Copie de foretfeerique/src/assets/. Ne pas recréer, ne pas modifier. |
| `authStore` redirect | `router.push('/dashboard')` — pas `/dashboard/foret`. |
| InputNumber responsive | `inputClass="sim-input"` + `:deep(.sim-input){width:100%;min-width:0}` + CSS grid `minmax(0,1fr)`. |
| Barre de recherche | Utiliser `IconField` + `InputIcon` — pas `p-input-icon-left` (rendu cassé). |
| `InputText` dans `v-for` | Bug PrimeVue confirmé : bloque la saisie souris. Utiliser `<input type="text" class="p-inputtext p-component">` natif. |
| `vuedraggable` | Import : `import draggable from 'vuedraggable'`. Toujours utiliser `handle`. |
| Suppression saveur/recette | Soft-delete si lots rattachés, suppression physique sinon. |
| `cruf_recette_ingredient.produit_id` | NOT NULL — jointure obligatoire pour le libellé. |
| Lot abandonné | Jamais de suppression physique. Toutes les données conservées. |
| Tare plaque | Saisie à chaque relevé — non stockée. `cruf_releve_evaporation.poids_brut_kg` = poids **net**. |
| Numéro de lot | Généré backend. Logique : `SELECT MAX(numero_lot) LIKE 'YY%'` → YY + (max+1) sur 4 chiffres. |
| Transition production→stock | Requiert ≥1 relevé poids_net ≤ cible_kg ET ≥1 contrôle qualité. |
| Calcul à rebours | Requiert ≥1 lot en `stock` avec `poids_reel_kg` non NULL pour la même saveur. |
| Totaux lot | `poids_brut_kg`, `poids_pulpe_kg`, `poids_base_kg` = pivot + fruits uniquement, sans additifs. |
| `axiosPeyrounet` | Instance séparée `baseURL: '/peyrounet/api'`, `withCredentials: true`. |
| `actif` flag | Backend uniquement — soft-delete silencieux. Ne jamais exposer en UI sauf contexte pertinent. |

---

## 12. Déploiement

### Checklist validée en prod (v6)

- ✅ Migration BDD v3 exécutée
- ✅ `POST /lots` → numéro format `YY0001`
- ✅ Liste lots, filtres, tri par statut
- ✅ Création lot → redirect fiche
- ✅ Fiche preparation — 4 blocs, calculs temps réel, alertes, grisage
- ✅ Fiche en_repos — blocs 1/2/3 verrouillés, bloc 4 Krencker éditable
- ✅ Transitions mettre-en-repos et demarrer opérationnelles

### À valider en prod

- Relevé de pesée → progression évaporation
- Passage en stock → jarres + contrôle qualité
- Widget /ferme/dashboard → KPIs corrects

### Règles .htaccess

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

# PWA mobile (à ajouter)
RewriteCond %{REQUEST_URI} ^/crufiture/production
RewriteRule ^crufiture/production(.*)$ /crufiture/production.html [L]
```

---

*Ferme du Peyrounet — Module /crufiture v6 — 4 avril 2026*
