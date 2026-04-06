# Architecture — Refonte des services transverses
**Document de travail — v2 — 5 avril 2026**

Mise à jour depuis v1 : toutes les décisions issues de la session de discussion
du 5 avril 2026 sont actées. Ce document remplace v1 comme référence.

---

## Stratégie de migration

**Big bang** — migration en une seule session planifiée.
Justification : aucune donnée de production réelle en service.
Pas de couche de compatibilité temporaire, pas de double système.

Implications concrètes :
- `/peyrounet` est découpé en une passe
- `/foretfeerique` et tous les modules consommateurs sont rebranchés en même temps
- La base MySQL reste unique (Hostinger) — pas de migration de données, uniquement du code et des renommages de tables

---

## Quatre services transverses

```
peyrounet.com/
│
├── monpanier/        ← SOCLE TECHNIQUE (inchangé — générique multi-tenant)
│   └── auth, BDD, emails, boutique en ligne
│
├── ferme/            ← PORTAIL + RÉFÉRENTIEL MÉTIER (existe déjà — à compléter)
│   └── cockpit admin, site vitrine, activités économiques, modules enregistrés
│
├── compta/           ← SERVICE COMPTABILITÉ (ex-peyrounet partie compta)
│   └── écritures double entrée, plan de comptes, TVA, factures, tiers
│
├── prix/             ← SERVICE RELEVÉS DE PRIX (ex-peyrounet partie prix)
│   └── relevés fournisseurs, OCR, prix moyens, prix de revient
│
├── stock/            ← SERVICE STOCK (nouveau)
│   └── référentiel articles produits, mouvements, tarifs de vente, disponibilité
│
├── shared/           ← COMPOSANTS PHP PARTAGÉS (nouveau)
│   └── services techniques réutilisables entre modules (OCR, futurs...)
│
├── foretfeerique/    ← MODULE MÉTIER (existant)
├── poulailler/       ← MODULE MÉTIER (futur)
├── fermeauberge/     ← MODULE MÉTIER (futur)
├── brebis/           ← MODULE MÉTIER (futur)
└── boutiquealaferme/ ← MODULE VENDEUR (futur)
```

---

## Hiérarchie des dépendances

```
/monpanier
    ↑ consommé par tous (auth, BDD)

/ferme
    ↑ consommé par /compta, /stock, /prix (référentiel activités et modules)

/stock
    ↑ consommé par /compta (sorties de stock ↔ écritures)
    ↑ consommé par /monpanier (disponibilité articles)
    ↑ consommé par /boutiquealaferme (disponibilité articles)
    ↑ consommé par les modules producteurs (entrées de stock)

/compta
    ↑ consommé par les modules métier (enregistrement des ventes)
    ↑ consommé par /prix (création/lecture fournisseurs)

/prix
    ↑ consommé par les modules métier (prix de revient, prix moyens)

/shared
    ↑ consommé par /compta et /prix (driver OCR)
```

**Règle absolue — dépendances unidirectionnelles :**
Un service en amont ne connaît jamais les services en aval.
`/monpanier` ne sait pas que `/compta` existe.
`/ferme` ne sait pas que `/poulailler` existe.
`/stock` ne sait pas ce que `/foretfeerique` vend.

---

## Cartographie des tables

### Renommage par préfixe de service

Chaque table prend le préfixe du service propriétaire.

#### `/ferme` — référentiel métier

| Ancien nom | Nouveau nom | Notes |
|------------|-------------|-------|
| `pey_module` | `ferme_module` | Registre des apps déployées |
| `pey_activite` | `ferme_activite` | Entité économique, régime TVA horodaté |

#### `/compta` — comptabilité

| Ancien nom | Nouveau nom | Notes |
|------------|-------------|-------|
| `pey_poste` | `compta_poste` | Plan de comptes |
| `pey_caisse` | `compta_caisse` | |
| `pey_session_caisse` | `compta_session_caisse` | |
| `pey_document` | `compta_document` | |
| `pey_document_ligne` | `compta_document_ligne` | |
| `pey_ecriture` | `compta_ecriture` | |
| `pey_ecriture_ligne` | `compta_ecriture_ligne` | |
| `pey_compta_sequence` | `compta_sequence` | |
| `pey_compta_cle_repartition` | `compta_cle_repartition` | |
| `pey_compta_tva_declaration` | `compta_tva_declaration` | |
| `pey_client` | `compta_client` | Tiers client |
| `pey_fournisseur` | `compta_fournisseur` | Tiers fournisseur — propriétaire unique |
| `pey_ocr_memoire_fournisseur` | `compta_ocr_memoire_fournisseur` | Matching OCR fournisseur |
| `pey_ocr_memoire_poste` | `compta_ocr_memoire_poste` | Mémoire poste+activité par produit — purement comptable |
| `pey_config` | `compta_config` | Config locale à /compta (OCR_SERVICE, OCR_MAX_LIGNES...) |

#### `/prix` — relevés de prix

| Ancien nom | Nouveau nom | Notes |
|------------|-------------|-------|
| `rp_produit` | `prix_article` | Catalogue des intrants achetés — libellé canonique + unité référence |
| `rp_conditionnement` | `prix_conditionnement` | |
| `rp_releve` | `prix_releve` | Voir modification colonne ci-dessous |
| `pey_ocr_memoire_produit` | `prix_ocr_memoire_produit` | |
| *(nouvelle)* | `prix_config` | Config locale à /prix (OCR_SERVICE, PRIX_PERIODE_MOIS...) |

**`rp_prix_vente` est supprimée de `/prix`** — migre dans `/stock` (voir ci-dessous).

#### `/stock` — gestion de stock

| Ancien nom | Nouveau nom | Notes |
|------------|-------------|-------|
| `rp_prix_vente` | `stock_tarif_vente` | Tarifs de vente des articles produits par la ferme |
| *(nouvelles)* | `stock_article`, etc. | Référentiel et mouvements — à modéliser |

---

## Décisions clés actées

### `compta_fournisseur` — propriété `/compta`

Le fournisseur est un tiers comptable. `/compta` en est le propriétaire unique.

`/prix` ne crée pas de fournisseur directement — il délègue à `/compta` :

```
POST /compta/api/fournisseurs    ← /prix demande la création si fournisseur inconnu
GET  /compta/api/fournisseurs    ← /prix lit pour autocomplete et matching
GET  /compta/api/fournisseurs/:id
```

`/compta` valide, crée, retourne l'id. C'est `/compta` qui décide si la création est valide.

Le champ `inclus_moyenne` reste dans `compta_fournisseur` — c'est un attribut du
fournisseur que `/prix` lit pour filtrer ses calculs de moyenne.

### `compta_ocr_memoire_fournisseur` — matching via `/compta`

Le matching OCR fournisseur (libellé brut → fournisseur_id) est centralisé dans `/compta`.
`/prix` appelle `/compta` pour résoudre un libellé détecté :

```
GET /compta/api/fournisseurs/ocr-match?libelle=AUCHAN
```

### Référence cross-service dans `prix_releve`

La colonne `document_ligne_id` (FK vers `pey_document_ligne`) est remplacée par
deux colonnes — référence applicative sans contrainte FK :

```sql
`source_service`     VARCHAR(20)  DEFAULT NULL   -- ex: 'compta'
`source_document_id` INT UNSIGNED DEFAULT NULL   -- id du compta_document
```

On pointe vers le document entier (pas la ligne) car c'est l'unité de visualisation.
L'intégrité est garantie par la règle métier : les documents comptables ne se suppriment pas.
La colonne est documentée explicitement dans le code et dans l'API inter-services.

### `stock_tarif_vente` — ex `rp_prix_vente`

Les tarifs de vente des produits de la ferme appartiennent à `/stock`, pas à `/prix`.

- `prix_article` = catalogue des **achats fournisseurs** (sucre, farine, alimentation animaux...)
- `stock_article` = catalogue des **articles produits** par la ferme (œufs, miel, légumes...)
- `stock_tarif_vente` = tarifs de vente par article et par canal

**Canal `interne`** ajouté à l'enum — couvre les cessions inter-activités
(ex: `/poulailler` cède des œufs à `/fermeauberge`).

Canal complet :
```sql
canal ENUM('ferme_directe', 'marche', 'ferme_auberge', 'boutique_en_ligne', 'interne', 'autre')
```

**Règle de cession inter-activités :** le module producteur fixe son prix de cession
sur le canal `interne`. `/fermeauberge` consomme ce prix via `/stock` sans négociation.

### Config OCR — locale à chaque module

Chaque module consommateur d'OCR gère sa propre configuration :

- `compta_config` : `OCR_SERVICE`, `OCR_MAX_LIGNES`, `OCR_TIMEOUT`
- `prix_config` : `OCR_SERVICE`, `OCR_MAX_LIGNES`, `OCR_TIMEOUT`, `PRIX_PERIODE_MOIS`

Justification : `/compta` traite des factures (précision comptable prioritaire),
`/prix` traite des étiquettes et tickets (rapidité prioritaire sur PWA mobile).
Chaque module peut utiliser un driver OCR différent selon ses besoins.

### `/stock` — option A (spécifique Peyrounet)

`/stock` est spécifique à la ferme du Peyrounet. Il n'est pas conçu pour être
réutilisable sur d'autres sites `/monpanier`.

Il consomme `/ferme/api/activites` pour le référentiel des activités.
Les canaux de vente sont des enums propres au Peyrounet.

---

## `/shared` — composants PHP partagés

### Structure

```
shared/
├── README.md                    ← index des composants disponibles
└── ocr/
    ├── SPEC-OCR-SERVICE.md      ← contrat du service, drivers supportés
    ├── OcrServiceInterface.php  ← interface stable
    ├── OcrServiceFactory.php    ← résolution du driver depuis config module
    ├── AnthropicOcrDriver.php   ← driver Anthropic (actuel)
    └── GoogleVisionOcrDriver.php ← driver Google Vision (existant dans /peyrounet)
```

**Règle absolue :** `/shared` ne contient aucune logique spécifique à un module.
Les prompts métier (étiquette, facture...) restent dans chaque module.
`/shared/ocr` encapsule uniquement l'appel HTTP à l'API tierce.

Note : `OcrServiceFactory.php` et `OcrServiceInterface.php` existent déjà dans
`/peyrounet/api/services/` — à déplacer dans `/shared/ocr/` sans modification.
`GoogleVisionOcrService.php` existe également — à déplacer et renommer en driver.

### Usage dans un module

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/shared/ocr/OcrServiceFactory.php';
$ocrService = OcrServiceFactory::create($mysqli); // lit OCR_SERVICE dans la config du module
```

---

## `/ferme` — ce qui manque

`/ferme` existe déjà. Il faut lui ajouter les routes issues de `/peyrounet`
qui concernent le référentiel métier — voir section **Cartographie des routes** ci-dessous.

### Controllers à déplacer depuis `/peyrounet`

- `ModuleController.php` → `/ferme/api/controllers/`
- `ActiviteController.php` → `/ferme/api/controllers/`

Adaptation nécessaire : noms de tables uniquement (`pey_module` → `ferme_module`,
`pey_activite` → `ferme_activite`). Aucune logique métier à modifier.

---

## Convention inter-services — `require_once` direct, jamais HTTP

**Règle absolue valable pour tous les modules sans exception.**

Les appels inter-services se font par inclusion PHP directe du controller cible,
jamais par appel HTTP interne (`curl`, `file_get_contents` vers une URL locale).

Justification : même serveur, même base MySQL, PHP 7.4 sur Hostinger.
Un appel HTTP interne ajoute de la latence, complique l'authentification
et n'apporte aucun bénéfice dans ce contexte.

### Pattern standard

```php
// Dans /prix — appel vers /compta pour créer un fournisseur
require_once $_SERVER['DOCUMENT_ROOT'] . '/compta/api/controllers/FournisseurController.php';
$fournisseurCtrl = new FournisseurController($mysqli);
$result = $fournisseurCtrl->creerFournisseurInterne(['nom' => 'Metro', 'type' => 'grossiste']);
```

### Convention sur les méthodes inter-services

Les controllers qui exposent des méthodes inter-services doivent proposer
**deux variantes** de chaque méthode concernée :

```php
// Méthode HTTP — lit php://input, répond via ResponseHelper::jsonResponse()
public function creerFournisseur(): void { ... }

// Méthode inter-service — accepte un tableau, retourne un tableau
public function creerFournisseurInterne(array $data): array { ... }
```

La méthode `Interne` contient la logique métier.
La méthode HTTP est un wrapper qui lit le body et appelle `Interne`.

Cette convention s'applique à **tous les modules** — elle sera documentée
dans `GUIDE-NOUVEAU-MODULE-v2.md`.

### Modules concernés au big bang

Les appels inter-services identifiés à ce stade :

| Appelant | Appelé | Méthode | Objet |
|----------|--------|---------|-------|
| `/prix` | `/compta` | `FournisseurController::creerFournisseurInterne()` | Créer un fournisseur |
| `/prix` | `/compta` | `FournisseurController::ocrMatchInterne()` | Résoudre libellé OCR → fournisseur_id |
| modules métier | `/compta` | `EcritureController::creerEcritureInterne()` | Enregistrer une vente |
| modules métier | `/prix` | `ProduitController::getPrixMoyenInterne()` | Calcul prix de revient |

---

## Cartographie des routes

Migration complète des routes `/peyrounet/api/` vers les nouveaux services.
Les routes en doublon dans le code actuel (`/activites` et `/config/activites`)
sont nettoyées — une seule route cible par ressource.

### `/ferme`

| Méth. | Route actuelle | Nouvelle route |
|-------|---------------|----------------|
| GET | `/peyrounet/api/config/modules` | `/ferme/api/modules` |
| POST | `/peyrounet/api/config/modules` | `/ferme/api/modules` |
| PUT | `/peyrounet/api/config/modules` | `/ferme/api/modules` |
| DELETE | `/peyrounet/api/config/modules` | `/ferme/api/modules` |
| GET | `/peyrounet/api/config/modules/ping` | `/ferme/api/modules/ping` |
| GET | `/peyrounet/api/config/activites` | `/ferme/api/activites` |
| GET | `/peyrounet/api/config/activites/simple` | `/ferme/api/activites/simple` |
| POST | `/peyrounet/api/config/activites` | `/ferme/api/activites` |
| PUT | `/peyrounet/api/config/activites` | `/ferme/api/activites` |
| DELETE | `/peyrounet/api/config/activites` | `/ferme/api/activites` |

### `/compta`

| Méth. | Route actuelle | Nouvelle route |
|-------|---------------|----------------|
| GET | `/peyrounet/api/compta/ecritures` | `/compta/api/ecritures` |
| GET | `/peyrounet/api/compta/ca` | `/compta/api/ca` |
| GET | `/peyrounet/api/compta/bilan` | `/compta/api/bilan` |
| POST | `/peyrounet/api/compta/ecriture` | `/compta/api/ecriture` |
| GET | `/peyrounet/api/tva/declaration` | `/compta/api/tva/declaration` |
| POST | `/peyrounet/api/tva/cloturer` | `/compta/api/tva/cloturer` |
| GET | `/peyrounet/api/couts-transverses` | `/compta/api/couts-transverses` |
| POST | `/peyrounet/api/couts-transverses` | `/compta/api/couts-transverses` |
| GET | `/peyrounet/api/repartition` | `/compta/api/repartition` |
| PUT | `/peyrounet/api/repartition` | `/compta/api/repartition` |
| GET | `/peyrounet/api/config/postes` | `/compta/api/postes` |
| POST | `/peyrounet/api/config/postes` | `/compta/api/postes` |
| PUT | `/peyrounet/api/config/postes` | `/compta/api/postes` |
| DELETE | `/peyrounet/api/config/postes` | `/compta/api/postes` |
| GET | `/peyrounet/api/config/caisses` | `/compta/api/caisses` |
| POST | `/peyrounet/api/config/caisses` | `/compta/api/caisses` |
| PUT | `/peyrounet/api/config/caisses` | `/compta/api/caisses` |
| DELETE | `/peyrounet/api/config/caisses` | `/compta/api/caisses` |
| GET | `/peyrounet/api/fournisseurs` | `/compta/api/fournisseurs` |
| GET | `/peyrounet/api/fournisseurs/dashboard` | `/compta/api/fournisseurs/dashboard` |
| POST | `/peyrounet/api/fournisseurs` | `/compta/api/fournisseurs` |
| PUT | `/peyrounet/api/fournisseurs` | `/compta/api/fournisseurs` |
| POST | `/peyrounet/api/fournisseurs/toggle-moyenne` | `/compta/api/fournisseurs/toggle-moyenne` |
| *(nouveau)* | — | `GET /compta/api/fournisseurs/ocr-match` |
| GET | `/peyrounet/api/documents` | `/compta/api/documents` |
| GET | `/peyrounet/api/documents/detail` | `/compta/api/documents/detail` |
| POST | `/peyrounet/api/documents/analyser` | `/compta/api/documents/analyser` |
| POST | `/peyrounet/api/documents` | `/compta/api/documents` |
| DELETE | `/peyrounet/api/documents` | `/compta/api/documents` |
| GET | `/peyrounet/api/factures` | `/compta/api/factures` |
| GET | `/peyrounet/api/factures/detail` | `/compta/api/factures/detail` |
| POST | `/peyrounet/api/factures/analyser` | `/compta/api/factures/analyser` |
| POST | `/peyrounet/api/factures` | `/compta/api/factures` |
| DELETE | `/peyrounet/api/factures` | `/compta/api/factures` |
| POST | `/peyrounet/api/document/generer` | `/compta/api/document/generer` |
| GET | `/peyrounet/api/config` | `/compta/api/config` |
| POST | `/peyrounet/api/config` | `/compta/api/config` |
| GET | `/peyrounet/api/dashboard` | `/compta/api/dashboard` |

### `/prix`

| Méth. | Route actuelle | Nouvelle route |
|-------|---------------|----------------|
| GET | `/peyrounet/api/produits` | `/prix/api/articles` |
| GET | `/peyrounet/api/produits/dashboard` | `/prix/api/articles/dashboard` |
| POST | `/peyrounet/api/produits` | `/prix/api/articles` |
| PUT | `/peyrounet/api/produits` | `/prix/api/articles` |
| DELETE | `/peyrounet/api/produits` | `/prix/api/articles` |
| GET | `/peyrounet/api/conditionnements` | `/prix/api/conditionnements` |
| POST | `/peyrounet/api/conditionnements` | `/prix/api/conditionnements` |
| PUT | `/peyrounet/api/conditionnements` | `/prix/api/conditionnements` |
| GET | `/peyrounet/api/releves` | `/prix/api/releves` |
| POST | `/peyrounet/api/releves` | `/prix/api/releves` |
| PUT | `/peyrounet/api/releves` | `/prix/api/releves` |
| DELETE | `/peyrounet/api/releves` | `/prix/api/releves` |
| POST | `/peyrounet/api/releves/valider` | `/prix/api/releves/valider` |
| GET | `/peyrounet/api/prix/moyen` | `/prix/api/moyen` |
| POST | `/peyrounet/api/prix/analyser-image` | `/prix/api/analyser-image` |
| POST | `/peyrounet/api/ocr/memoire-produit` | `/prix/api/ocr/memoire-article` |
| GET | `/peyrounet/api/inter/produits` | `/prix/api/inter/articles` |
| *(futur)* | — | `/prix/api/dashboard` |

### `/stock`

| Méth. | Route actuelle | Nouvelle route |
|-------|---------------|----------------|
| GET | `/peyrounet/api/prix/vente` | `/stock/api/tarifs` |
| GET | `/peyrounet/api/prix-vente` | `/stock/api/tarifs` *(doublon nettoyé)* |
| POST | `/peyrounet/api/prix-vente` | `/stock/api/tarifs` |

Les routes `/stock` restantes sont à définir lors de la modélisation de `/stock`.

---

## Points non encore modélisés

| Sujet | État |
|-------|------|
| Modèle de données `/stock` (articles, mouvements, réservations) | À concevoir |
| Prix de revient "production interne" (coût d'un œuf de `/poulailler`) | À concevoir |
| Canal `interne` de `stock_tarif_vente` — implémentation `/stock` | Acté, à implémenter |
| `SPEC-OCR-SERVICE.md` | À rédiger après finalisation archi |
| `GUIDE-NOUVEAU-MODULE-v2.md` | À mettre à jour — inclure convention inter-services |
| `GUIDE-MIGRATION-BIG-BANG.md` | À créer — routes avant/après par module consommateur |

---

*Documents liés :*
- *`ARCHITECTURE-GLOBALE-PEYROUNET.md` — à mettre à jour en v3*
- *`API-INTER-MODULES-PEYROUNET.md` — à migrer vers `/compta` et `/prix`*
- *`ARCHITECTURE-REFONTE-SERVICES-v1.md` — remplacé par ce document*
