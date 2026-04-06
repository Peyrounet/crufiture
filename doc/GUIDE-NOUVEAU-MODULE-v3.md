# Guide de démarrage — Nouveau module Peyrounet
**Document de référence — v3 — 5 avril 2026**

À fournir en début de session pour tout nouveau module métier de la ferme.

> ⚠️ **Règle absolue** : ne pas écrire de code sans avoir les fichiers sources d'un module existant.
> Fournir les zips `api.zip` (backend) et `src.zip` (frontend) de `/foretfeerique`
> comme référence. Voir `ONBOARDING-CLAUDE-NOUVEAU-PROJET.md`.

---

## Architecture — rappel

```
public_html/
├── monpanier/          ← socle auth/BDD/emails (ne jamais modifier)
├── shared/             ← composants PHP partagés (OCR...) — voir SPEC-OCR-SERVICE.md
├── ferme/              ← cockpit admin + site public + référentiel métier ✅
├── compta/             ← service comptabilité ✅
├── prix/               ← service relevés de prix ✅
├── stock/              ← service stock ✅
├── foretfeerique/      ← module métier de référence ✅ en production
└── [nouveau-module]/   ← suit exactement le même pattern
```

**Dépendances unidirectionnelles — règle absolue :**
Un module consomme des services, il n'en fournit pas aux autres.
Exception : chaque module expose `GET /[module]/api/ferme-widget` pour le cockpit `/ferme`.

**Services disponibles à la consommation :**

| Service | Ce qu'il expose |
|---------|----------------|
| `/ferme` | Référentiel activités économiques, modules enregistrés |
| `/compta` | Écritures comptables, factures, clients, fournisseurs |
| `/prix` | Prix moyens, prix de revient sur liste d'ingrédients |
| `/stock` | Catalogue articles, disponibilité, tarifs de vente |

---

## Stack technique — identique pour tous les modules

| Composant | Valeur exacte |
|-----------|---------------|
| Backend | PHP 7.4+ (Hostinger) |
| Frontend | Vue 3.5+ + Pinia 2.x + Vue Router 4.x |
| UI | PrimeVue 3.x (pas v4) — `Dropdown`, `InputSwitch`, `AccordionTab` |
| Auth | Cookie JWT `peyrounet.com` via `/monpanier` |
| Thème | `aura-light-amber` depuis `/monpanier/themes/` |
| BDD | MySQL partagé, tables préfixées `[slug]_` |
| CSS | PrimeFlex + layout SCSS (copier `src/assets/` depuis foretfeerique) |
| Build | Vite 5.x — `base: '/[module]/'` obligatoire |

---

## Contraintes PHP 7.4 — non négociables

```php
// ❌ Interdit
function test(string|int $val) {}   // union types
match($x) { 1 => 'a' }             // match sans default
readonly string $prop;              // readonly
creerTruc(libelle: 'test');         // named arguments
const FOO = 'bar'; // dans un trait // constantes dans trait

// ✅ Correct
function test($val) {}
match($x) { 1 => 'a', default => 'b' }
public string $prop;
creerTruc('test');
```

---

## Fichiers à fournir en début de session

```
1. api.zip  — backend foretfeerique (bootstrap + index + routes + 1 controller)
2. src.zip  — frontend foretfeerique (tout le dossier src/)
3. Schéma SQL des nouvelles tables à créer
4. Description fonctionnelle du module
```

Sans ces 4 éléments, Claude doit les demander avant de coder.

---

## Pattern PHP — ce que Claude doit copier exactement

### bootstrap.php
Copie de `foretfeerique/api/bootstrap.php` — adapter uniquement les logs (nom du module).
Points critiques :
- Vérification `file_exists` avant de charger le socle → retourne JSON d'erreur explicite si introuvable
- `getenv('MONPANIER_API_PATH')` comme fallback pour le chemin
- Charge `AuthMiddleware.php` (présent dans monpanier)
- Logs via `helpers\LogHelper::addLog()`

### index.php
Copie exacte de `foretfeerique/api/index.php` — aucune adaptation nécessaire.
Points critiques :
- `display_errors` activé en localhost uniquement (détection via `$_SERVER['HTTP_HOST']`)
- CORS : `Access-Control-Allow-Origin` = origine de la requête (pas `*`)

### routes/api.php
Pattern obligatoire :
```php
use helpers\ResponseHelper;                        // ← namespace obligatoire
$mysqli = (new Database())->getConnection();       // ← pas $conn, pas $db
$prefix = $_ENV['[MODULE]_FOLDER'] ?? '/[module]'; // ← depuis .env
$api    = $prefix . '/api';
// ... dispatch switch/if-elseif
```

### Controllers
```php
use helpers\ResponseHelper;  // ← en tête de chaque controller

class MonController {
    private $mysqli;          // ← pas $conn

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }

    public function getData() {
        // ...
        echo ResponseHelper::jsonResponse('OK', 'success', $data);
        // Signature : jsonResponse($message, $status, $details = null, $statusCode = 200)
    }
}
```

### ResponseHelper — signature exacte
```php
// /monpanier/api/helpers/ResponseHelper.php — namespace helpers
ResponseHelper::jsonResponse($message, $status, $details = null, $statusCode = 200)
// Retourne une string JSON — toujours préfixer avec echo
// Format : {"message":"...","status":"success","details":{...}}
```

---

## Convention inter-services — `require_once` direct, jamais HTTP

**Règle absolue valable pour tous les modules sans exception.**

Les appels entre modules se font par inclusion PHP directe du controller cible.
Jamais d'appel HTTP interne (`curl`, `file_get_contents` vers une URL locale).

Justification : même serveur, même base MySQL. Un appel HTTP interne ajoute
de la latence et complique l'authentification sans aucun bénéfice.

### Pattern standard

```php
// Exemple : appel vers /compta pour enregistrer une écriture de vente
require_once $_SERVER['DOCUMENT_ROOT'] . '/compta/api/controllers/EcritureController.php';
$ecritureCtrl = new EcritureController($mysqli);
$result = $ecritureCtrl->creerEcritureInterne([
    'document_id' => $docId,
    'activite_id' => $activiteId,
    // ...
]);
```

### Convention double méthode

Tout controller qui expose une fonctionnalité consommable par d'autres modules
doit proposer deux variantes :

```php
// Méthode HTTP — lit php://input, répond via ResponseHelper
public function creerEcriture(): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $result = $this->creerEcritureInterne($data);
    echo ResponseHelper::jsonResponse('Écriture créée.', 'success', $result, 201);
}

// Méthode inter-service — accepte un tableau, retourne un tableau
// Jamais d'echo, jamais de ResponseHelper — retour PHP pur
public function creerEcritureInterne(array $data): array
{
    // logique métier ici
    return ['id' => $id, 'numero' => $numero];
}
```

**Règles :**
- La méthode `Interne` contient toute la logique métier
- La méthode HTTP est un wrapper qui lit le body et appelle `Interne`
- La méthode `Interne` ne fait jamais d'`echo` et ne lève jamais d'exception —
  elle retourne toujours un tableau, avec une clé `erreur` si quelque chose échoue
- Le module appelant détecte l'erreur via la présence de la clé `erreur`

### Appels inter-services disponibles

| Depuis | Vers | Méthode | Objet |
|--------|------|---------|-------|
| tout module | `/ferme` | `ActiviteController::getActivitesInterne()` | Lister les activités |
| tout module | `/compta` | `EcritureController::creerEcritureInterne()` | Enregistrer une vente |
| tout module | `/compta` | `FournisseurController::creerFournisseurInterne()` | Créer un fournisseur |
| tout module | `/compta` | `FournisseurController::ocrMatchInterne()` | Matching libellé → fournisseur |
| tout module | `/prix` | `ProduitController::getPrixMoyenInterne()` | Prix moyen d'un article |
| `/prix` | `/compta` | `FournisseurController::creerFournisseurInterne()` | Délégation création tiers |

---

## Pattern Vue — ce que Claude doit copier exactement

### main.js
Copie de `foretfeerique/src/main.js` — adapter uniquement :
- Importer `axios[Module].js` au lieu de `axiosForetfeerique.js`

Points critiques :
- Tous les composants PrimeVue enregistrés individuellement (pas `app.use(PrimeVue)` seul)
- Directives : `tooltip`, `badge`, `ripple`, `styleclass`
- Services : `ToastService`, `DialogService`, `ConfirmationService`
- `PageCard` enregistré globalement

### userStore.js
Le user monpanier utilise des **flags plats** (pas un tableau `roles[]`) :
```js
// ✅ Structure réelle du user
{ is_admin: 0, is_organizer: 1, is_producer: 1, ... }

// Getters à implémenter
userRoles: (state) => {
    const roles = [];
    if (state.user?.is_admin)     roles.push('admin');
    if (state.user?.is_organizer) roles.push('organizer');
    if (state.user?.is_producer)  roles.push('producer');
    return roles;
},
isAdmin:     (state) => !!state.user?.is_admin,
isOrganizer: (state) => !!state.user?.is_organizer,
isProducer:  (state) => !!state.user?.is_producer,
```

### authStore.js + router/index.js
Copie exacte depuis foretfeerique. Ne jamais réécrire le guard — boucles infinies garanties.
Adapter uniquement :
- `createWebHistory('/[module]/')` dans le router
- Les routes (noms + composants)
- `document.title` dans le guard

### axios.js (instance monpanier)
```js
// Utilise axios.defaults, pas axios.create()
axios.defaults.baseURL = import.meta.env.VITE_API_URL ?? '/monpanier/api';
axios.defaults.withCredentials = true;
// + intercepteur qui résout les 4xx comme des succès (pattern foretfeerique)
```

### index.html
```html
<link id="theme-css" rel="stylesheet" href="/monpanier/themes/aura-light-amber/theme.css" />
<script type="module" src="/src/main.js"></script>
```

### Assets SCSS
Copier intégralement `foretfeerique/src/assets/` (layout + styles.scss).
Ne pas recréer — ces fichiers contiennent toute la structure layout (topbar, sidebar, menu...).

### Contraintes Tailwind/PrimeVue sur Hostinger

- Classes `bg-*-50` indisponibles → utiliser `style="background:#XXXXXX"` inline
- Icônes `pi-trending-up` / `pi-trending-down` inexistantes → utiliser `pi-arrow-up` / `pi-arrow-down`
- Typage strict PrimeVue Dropdown : MySQL retourne les IDs en string → toujours caster avec `Number()` côté frontend

---

## Contrat ferme-widget — obligatoire pour le cockpit /ferme

Chaque module doit exposer :
```
GET /[module]/api/ferme-widget
```
Réponse :
```json
{
  "message": "OK", "status": "success",
  "details": {
    "module": "slug",
    "libelle": "Nom affiché",
    "kpis": [
      { "label": "...", "valeur": 42, "unite": null, "couleur": "green|orange|red|neutral" }
    ],
    "actions_urgentes": [
      { "label": "...", "severite": "danger|warning|info", "lien": "/url" }
    ]
  }
}
```
Voir `CONTRAT-FERME-WIDGET.md` pour la spec complète.

---

## .htaccess racine — ajouter ces règles

```apache
# API [module]
RewriteCond %{REQUEST_URI} ^/[module]/api
RewriteRule ^[module]/api/(.*)$ /[module]/api/index.php [L,QSA]

# SPA [module]
RewriteCond %{REQUEST_URI} ^/[module]/.*$
RewriteCond %{REQUEST_URI} !^/[module]/favicon\.ico$
RewriteCond %{REQUEST_URI} !^/[module]/assets/.*$
RewriteCond %{REQUEST_URI} !^/[module]/images(/.*)?$
RewriteRule ^[module]/ /[module]/index.html [L]
```

Jamais de `.htaccess` dans `/[module]/` ou `/[module]/api/`.

---

## Variables d'environnement

Ajouter dans `public_html/.env` :
```env
[MODULE]_FOLDER=/[module]
```

---

## Tables BDD — conventions

```sql
CREATE TABLE [slug]_[entite] (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actif      TINYINT(1)  NOT NULL DEFAULT 1,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Préfixe obligatoire `[slug]_`. Tables lues dans le schéma phpMyAdmin avant tout SELECT.

---

## Enregistrement dans /ferme

Après déploiement, aller dans `/ferme/dashboard/modules` et créer le module avec son slug.
Requis pour :
- Apparaître dans le cockpit `/ferme`
- Être reconnu comme module valide par les services transverses

---

## Ordre de développement recommandé

1. **Ping PHP** — copier bootstrap + index + routes avec une route `/ping` → tester en prod
2. **Frontend squelette** — copier src depuis foretfeerique, adapter menu et routes → tester le login
3. **Enregistrement /ferme** — créer le module dans les paramètres
4. **ferme-widget** — implémenter l'endpoint (même vide) → vérifier qu'il apparaît dans /ferme
5. **Tables BDD** — créer dans phpMyAdmin, vérifier les colonnes
6. **Controllers + vues** — développer fonctionnalité par fonctionnalité

---

## Ce que le module ne gère pas

| Besoin | Solution |
|--------|----------|
| Auth | Cookie JWT monpanier — automatique |
| Activités économiques | `require_once /ferme/api/controllers/ActiviteController.php` |
| Écriture comptable | `require_once /compta/api/controllers/EcritureController.php` |
| Factures clients | `require_once /compta/api/controllers/DocumentController.php` |
| Fournisseurs / tiers | `require_once /compta/api/controllers/FournisseurController.php` |
| Prix moyens / prix de revient | `require_once /prix/api/controllers/ProduitController.php` |
| Stock / disponibilité | `require_once /stock/api/controllers/StockController.php` |
| OCR images | `require_once /shared/ocr/OcrServiceFactory.php` — voir `SPEC-OCR-SERVICE.md` |
| Emails | `MailService` hérité via bootstrap |

---

## Changelog

| Version | Date | Modifications |
|---------|------|---------------|
| v3 | 5 avril 2026 | Refonte architecture — `/peyrounet` éclaté en `/compta` + `/prix` + `/stock`. Ajout `/shared`. Convention inter-services `require_once`. Référence `/foretfeerique` comme module de référence. Enregistrement dans `/ferme` au lieu de `/peyrounet`. |
| v2 | 28 mars 2026 | Version initiale |
