# Module /crufiture — Document de référence
**v4 — 31 mars 2026**
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
| Variable .env | `CRUFITURE_FOLDER=/crufiture` |
| Activité comptable | Cruficulture [id=5] |
| Thème | aura-light-amber |
| Statut | En production — v4 (saveurs + recettes opérationnelles) |

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
| `cruf_recette_etape` | Étapes de préparation ordonnées et réordonnables — **créée en migration** (absente du schema_v1.sql) |
| `cruf_lot` | Cœur du module — un lot = une session de production complète |
| `cruf_lot_fruit` | Fruits/légumes d'un lot avec traçabilité (fournisseur, origine, poids) |
| `cruf_jarre` | Stockage en jarres (1 à 3 par lot) avec poids initial et poids actuel |
| `cruf_releve_evaporation` | Relevés temps réel pendant la production (heure, poids, météo) |
| `cruf_controle` | Contrôles qualité post-production (Brix, Aw, pH) — plusieurs par lot |

### Cycle de vie d'un lot (colonne `statut`)

`formule` → `en_production` → `mis_en_pot` → `controle` → `archive`

### Numéro de lot

Généré automatiquement : YY + séquentiel 4 chiffres. Ex : `260001` = année 2026, lot n°0001.

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
| `brix_cible` | Objectif de concentration finale (°Bx) |
| `pct_fructose` | % de fructose dans le sucre ajouté total (le « 50/50 ») |
| `pa_formulation` | Paramètre de formulation en g de pulpe pour 100g de crufiture |

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

Disponible dès le 2ème lot de la même saveur :

```
rendement_brut_pulpe = AVG(pulpe_kg / poids_brut_kg)  sur lots précédents de la saveur
rendement_pulpe_cruf = AVG(poids_reel_kg / base_kg)   sur lots précédents de la saveur
poids_brut_nécessaire = cible_souhaitée / rendement_pulpe_cruf / rendement_brut_pulpe
```

---

## 5. Routes API en production

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
| `POST /crufiture/api/recettes` | Créer une recette → redirige vers liste après création |
| `POST /crufiture/api/recettes/:id/dupliquer` | Nouvelle version à partir d'une recette existante |
| `PUT /crufiture/api/recettes/:id` | Modifier titre et note uniquement |
| `PUT /crufiture/api/recettes/:id/complet` | Sauvegarde complète (titre + note + ingrédients + étapes) |
| `DELETE /crufiture/api/recettes/:id` | Supprimer (physique) ou désactiver (si lots rattachés) |

### Contrat ferme-widget — KPIs remontés

- Lots cette année — `COUNT(*) WHERE YEAR(date_production) = annee`
- En production — `COUNT(*) WHERE statut = 'en_production'`
- Stock en jarres — `SUM(poids_actuel_kg) FROM cruf_jarre`
- Action urgente si lot `en_production` depuis > 1 jour

---

## 6. Structure des fichiers déployés

### Backend (PHP)

```
crufiture/api/
├── bootstrap.php                  ← copie foretfeerique (logs 'Crufiture')
├── index.php                      ← copie EXACTE foretfeerique
├── routes/api.php                 ← router (GET/POST/PUT/DELETE)
└── controllers/
    ├── PingController.php
    ├── FermeWidgetController.php
    ├── DashboardController.php
    ├── SaveurController.php       ← v2
    └── RecetteController.php      ← v4 (bug bind_param NULL corrigé)
```

### Frontend (Vue 3)

```
crufiture/src/
├── main.js
├── App.vue
├── assets/                        ← copie EXACTE foretfeerique (ne jamais modifier)
├── layout/
│   ├── AppLayout.vue
│   ├── AppTopbar.vue
│   ├── AppMenu.vue                ← ordre : Simulateur > Saveurs > Recettes > Lots
│   ├── AppSidebar.vue
│   └── AppMenuItem.vue
├── plugins/
│   ├── axios.js                   ← baseURL /monpanier/api
│   ├── axiosCrufiture.js          ← baseURL /crufiture/api
│   └── axiosPeyrounet.js          ← baseURL /peyrounet/api (ajouté v4)
├── router/index.js                ← routes : dashboard, simulateur, saveurs, recettes, recettes/:id
├── stores/
│   ├── authStore.js
│   └── userStore.js
├── components/PageCard.vue
└── views/
    ├── LoginView.vue
    ├── common/Error.vue
    └── admin/
        ├── DashboardCrufiture.vue
        ├── SimulateurFormulation.vue
        ├── GestionSaveurs.vue     ← v2
        ├── GestionRecettes.vue    ← v4
        └── EditionRecette.vue     ← v4 — drag vuedraggable, input natif, layout 50/50
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
- Recherche live côté frontend (liste courte, pas d'appel API supplémentaire)
- Tri alphabétique géré côté API (`ORDER BY nom ASC`)
- Formulaire : nom, slug (auto-généré à la création, modifiable), brix_cible, pa_cible, pct_fructose, note
- Suppression physique si aucun lot rattaché, soft-delete (`actif=0`) sinon — le toast indique lequel
- Flag `actif` non exposé dans l'UI (filet de sécurité backend uniquement)
- Barre de recherche : `IconField` + `InputIcon` (pas `p-input-icon-left`)

---

## 9. Gestion des recettes

Page `/dashboard/recettes` — liste groupée par saveur, page dédiée `/dashboard/recettes/:id` pour l'édition.

### Structure d'une recette

Une recette est liée à une saveur. Plusieurs versions peuvent coexister — la version est auto-incrémentée par saveur. Les anciennes versions sont conservées pour la traçabilité des lots.

Les ingrédients sont stockés dans `cruf_recette_ingredient` avec trois types (gérés par l'appli, non exposés dans l'UI) :

- `type = 'pivot'` → fruit de référence, un seul par recette. `pct_base = NULL`. Les proportions des autres fruits sont exprimées en % de ce fruit.
- `type = 'fruit'` → autre fruit de la mixture. `pct_base = % du pivot`.
- `type = 'additif'` → ingrédient supplémentaire (fleurs, épices, jus…). `pct_base = % de la base totale`.

Chaque ingrédient est obligatoirement lié à un `produit_id` dans `rp_produit` (peyrounet). Le libellé affiché est `rp_produit.libelle_canonique` obtenu par jointure — pas de copie locale.

Les étapes de préparation sont stockées dans `cruf_recette_etape` — ordonnées, réordonnables via drag-and-drop (vuedraggable). Le champ `instructions` TEXT de `cruf_recette` est conservé pour compatibilité mais n'est plus utilisé.

### EditionRecette.vue — points techniques clés

- **Drag & drop** : `vuedraggable` sur les 3 listes (fruits, additifs, étapes) — même techno, même rendu. `handle=".ed-drag-handle"` pour fruits/additifs, `handle=".ed-etape-drag-handle"` pour étapes.
- **Champs de recherche produit** : `<input>` HTML natif (pas `InputText` PrimeVue) — `InputText` dans un `v-for` bloque la saisie souris dans ce contexte (bug PrimeVue confirmé).
- **Autocomplétion** : `GET /peyrounet/api/inter/produits?q=xxx` via `axiosPeyrounet`. Debounce 300ms par ingrédient via `ing._key`.
- **Layout** : 2 colonnes 50/50 (`grid-template-columns: 1fr 1fr`). Colonne gauche : infos + mélange + additifs. Colonne droite : étapes (sticky).
- **Grid interne lignes fruit** : `grid-template-columns: 1fr 160px auto` avec `align-items: start` — champ produit, champ %, bouton poubelle.
- **Après création** : redirection vers `/dashboard/recettes` (liste), pas vers la page d'édition.

### Duplication / nouvelle version

`POST /recettes/:id/dupliquer` crée une nouvelle version (version max+1) en copiant ingrédients et étapes. La recette source est conservée intacte.

### Calcul du prix de revient

Via `POST /peyrounet/api/inter/prix-revient` :

- Fruit(s) : `poids_brut_kg` (ou `base_kg` selon référencement dans `rp_produit`)
- Fructose : `fructose_kg`
- Saccharose : `saccharose_kg`
- Additifs : `base_kg × (pct_base / 100)` pour chaque `cruf_recette_ingredient`

Résultat : coût HT matière / `poids_reel_kg` = coût HT par kg produit.

---

## 10. Points d'attention pour les futures discussions

| Point | Règle |
|---|---|
| `bootstrap.php` | Copie de foretfeerique avec logs adaptés. Ne jamais modifier la logique. |
| `index.php` | Copie EXACTE de foretfeerique. Ne jamais modifier. |
| `$mysqli` | Variable de connexion BDD dans tous les controllers. Jamais `$conn`, jamais `$db`. |
| `ResponseHelper` | `echo ResponseHelper::jsonResponse($message, $status, $details, $statusCode)`. Toujours `echo` devant, toujours `use helpers\ResponseHelper` en tête. |
| `bind_param` types | Types valides : `s`, `i`, `d`, `b` uniquement. Valeur NULLable → toujours type `'s'` avec variable intermédiaire. Un type invalide échoue silencieusement. |
| Cast TINYINT | Toujours `(int) $row['actif']`. PHP retourne une string `"1"` et Vue évalue `"1" === 1` à `false`. |
| Cast DECIMAL | Toujours `(float) $row['brix_cible']` etc. Même raison. |
| `v-for` + `v-model` | Dans un `v-for="(item, idx) in monRef"`, toujours `v-model="monRef[idx].prop"` et non `v-model="item.prop"`. |
| `window` dans template Vue | Inaccessible directement. Toujours passer par une fonction dans `<script setup>`. |
| Vite base | `base: '/crufiture/'` dans `vite.config.js`. Déployer `dist/` dans `/crufiture/`. |
| Assets SCSS | Copie de foretfeerique/src/assets/. Ne pas recréer, ne pas modifier. |
| `authStore` redirect | `router.push('/dashboard')` — pas `/dashboard/foret`. |
| InputNumber responsive | `inputClass="sim-input"` + `:deep(.sim-input){width:100%;min-width:0}` + CSS grid `minmax(0,1fr)`. Pas de PrimeFlex grid pour les groupes de champs. |
| Barre de recherche | Utiliser `IconField` + `InputIcon` — pas `p-input-icon-left` (rendu cassé). |
| Suppression saveur | Soft-delete si lots rattachés (`actif=0`), suppression physique sinon. Flag `actif` non exposé dans l'UI. |
| Suppression recette | Même règle : soft-delete si lots rattachés, suppression physique + cascade ingrédients + étapes sinon. |
| `cruf_recette_ingredient.produit_id` | NOT NULL — un ingrédient doit toujours référencer un produit existant dans `rp_produit`. Libellé affiché via jointure. |
| `cruf_recette_ingredient.type` | Géré par l'appli selon la zone de saisie UI (fruits vs additifs). Jamais exposé à l'utilisateur. |
| Autocomplétion produits | `GET /peyrounet/api/inter/produits?q=xxx` via `axiosPeyrounet` (baseURL `/peyrounet/api`). Pas via `axios` (baseURL `/monpanier/api`). |
| `InputText` dans `v-for` | Bug PrimeVue confirmé : bloque la saisie souris. Utiliser `<input type="text" class="p-inputtext p-component">` natif à la place. |
| `vuedraggable` | Installé (`^4.1.0`). Import : `import draggable from 'vuedraggable'`. Toujours utiliser `handle` pour ne pas bloquer les inputs enfants. |

---

## 11. Déploiement

### Ordre de déploiement initial (fait)

1. Importer `schema_crufiture_v1.sql` dans phpMyAdmin
2. Exécuter la migration `cruf_recette_etape` (absente du schema v1)
3. Ajouter `CRUFITURE_FOLDER=/crufiture` dans `public_html/.env`
4. Copier `api/` dans `public_html/crufiture/api/`
5. Ajouter les règles `.htaccess` (voir ci-dessous)
6. `npm install && npm run build` → déployer `dist/` dans `public_html/crufiture/`
7. Enregistrer dans `/peyrounet/dashboard/parametres/modules` (slug: `crufiture`)

### Migration cruf_recette_etape

```sql
CREATE TABLE cruf_recette_etape (
    id          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    recette_id  INT UNSIGNED     NOT NULL,
    ordre       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    contenu     TEXT             NOT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recette_id) REFERENCES cruf_recette(id),
    INDEX idx_recette_ordre (recette_id, ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Règles .htaccess

```apache
# API crufiture
RewriteCond %{REQUEST_URI} ^/crufiture/api
RewriteRule ^crufiture/api/(.*)$ /crufiture/api/index.php [L,QSA]

# SPA crufiture
RewriteCond %{REQUEST_URI} ^/crufiture/.*$
RewriteCond %{REQUEST_URI} !^/crufiture/favicon\.ico$
RewriteCond %{REQUEST_URI} !^/crufiture/assets/.*$
RewriteCond %{REQUEST_URI} !^/crufiture/images(/.*)?$
RewriteRule ^crufiture/ /crufiture/index.html [L]
```

### Checklist de vérification

- `GET /crufiture/api/ping` → `{message:'pong',status:'success'}`
- `GET /crufiture/api/ferme-widget` → JSON avec `module='crufiture'`
- `https://peyrounet.com/crufiture/` → page de login
- Login admin → dashboard (KPIs)
- Widget visible dans `/ferme/dashboard`
- `/dashboard/simulateur` → exemple betterave → résultats corrects
- `/dashboard/saveurs` → liste + création + modification + suppression
- `/dashboard/recettes` → liste groupée par saveur
- `/dashboard/recettes/nouvelle` → création avec fruits + additifs + étapes → retour liste
- `/dashboard/recettes/:id` → édition + sauvegarde + duplication

---

*Ferme du Peyrounet — Module /crufiture v4 — 31 mars 2026*