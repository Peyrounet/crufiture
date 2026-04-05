-- ============================================================
-- Module /crufiture — Schema SQL v4
-- Base : u191509486_dbboutique (partagée Peyrounet)
-- Préfixe : cruf_*
-- Généré le : 2026-03-30
-- Mis à jour le : 2026-04-05 (v4 — PWA mobile production)
-- ============================================================


-- ------------------------------------------------------------
-- 1. SAVEURS
-- Référentiel des saveurs avec paramètres de formulation
-- par défaut. Ces valeurs pré-remplissent le formulaire
-- de création de lot — modifiables lot par lot.
-- ------------------------------------------------------------
CREATE TABLE cruf_saveur (
    id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(100)     NOT NULL,             -- ex: Betterave, Rhubarbe Fleur de Sureau
    slug            VARCHAR(100)     NOT NULL UNIQUE,      -- ex: betterave, rhubarbe-fleur-sureau
    brix_cible      DECIMAL(5,2)     NOT NULL DEFAULT 70.00,
    pa_cible        DECIMAL(5,2)     NOT NULL DEFAULT 68.00,   -- g pulpe / 100g (mention étiquette légale)
    pct_fructose    DECIMAL(5,2)     NOT NULL DEFAULT 50.00,   -- % fructose dans sucre ajouté
    note            TEXT             DEFAULT NULL,
    actif           TINYINT(1)       NOT NULL DEFAULT 1,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 2. RECETTES
-- Fiche technique de préparation, liée à une saveur.
-- Plusieurs versions peuvent coexister pour une même saveur —
-- le choix de la version est fait au moment de la création du lot.
-- Les anciennes versions sont conservées pour la traçabilité.
--
-- instructions TEXT est conservé (non supprimé) mais remplacé
-- fonctionnellement par cruf_recette_etape pour l'édition.
--
-- Usage prix de revient :
--   qté réelle = poids_base_kg × (pct_base / 100) pour les additifs
--   qté fruit  = poids_base_kg (c'est la base elle-même)
--   qté fructose/saccharose = issues des calculs du lot
-- ------------------------------------------------------------
CREATE TABLE cruf_recette (
    id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    saveur_id       INT UNSIGNED     NOT NULL,
    version         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    titre           VARCHAR(200)     NOT NULL,
    instructions    TEXT             NOT NULL,   -- conservé, remplacé fonctionnellement par cruf_recette_etape
    note            TEXT             DEFAULT NULL,
    actif           TINYINT(1)       NOT NULL DEFAULT 1,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (saveur_id) REFERENCES cruf_saveur(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 3. INGRÉDIENTS DE RECETTE
-- Tous les ingrédients d'une recette avec leur proportion.
--
-- type = 'pivot'  → fruit de référence, un seul par recette
--                   pct_base NULL
--                   les proportions des autres fruits sont
--                   exprimées en % de ce fruit
--                   qté réelle sur lot = poids_base_kg du pivot
--
-- type = 'fruit'  → autre fruit de la mixture
--                   pct_base = % du fruit pivot
--                   qté réelle sur lot = poids_base_kg_pivot × (pct_base / 100)
--
-- type = 'additif'→ ingrédient supplémentaire (fleurs, épices, jus...)
--                   pct_base = % de la base totale fruits
--                   qté réelle sur lot = poids_base_kg_total × (pct_base / 100)
--
-- Le type est géré par l'application selon la zone de saisie UI,
-- jamais exposé directement à l'utilisateur.
--
-- produit_id → FK vers rp_produit dans /peyrounet (NOT NULL)
--              Le libellé affiché est rp_produit.libelle_canonique
--              obtenu par jointure — pas de copie locale.
--
-- pct_base → NULL pour le pivot
--             % du pivot pour les autres fruits
--             % de la base totale fruits pour les additifs
--             L'unité d'affichage des poids calculés (kg ou g)
--             est déterminée à la volée selon la valeur.
-- ------------------------------------------------------------
CREATE TABLE cruf_recette_ingredient (
    id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    recette_id      INT UNSIGNED     NOT NULL,
    produit_id      INT UNSIGNED     NOT NULL,        -- FK peyrounet rp_produit — libellé par jointure
    type            ENUM('pivot','fruit','additif') NOT NULL DEFAULT 'additif',
    pct_base        DECIMAL(6,3)     DEFAULT NULL,    -- NULL = pivot ; % du pivot si fruit ; % base totale fruits si additif
    note            VARCHAR(255)     DEFAULT NULL,
    ordre           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (recette_id) REFERENCES cruf_recette(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 4. ÉTAPES DE RECETTE
-- Instructions détaillées, ordonnées et réordonnables.
-- Remplace fonctionnellement le champ instructions TEXT de
-- cruf_recette pour permettre l'édition par étape et le
-- drag-and-drop. cruf_recette.instructions est conservé.
-- ------------------------------------------------------------
CREATE TABLE cruf_recette_etape (
    id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    recette_id      INT UNSIGNED     NOT NULL,
    ordre           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    contenu         TEXT             NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recette_id) REFERENCES cruf_recette(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 5. LOTS DE PRODUCTION
-- Cœur du module. Un lot = une session de production complète.
-- Une plaque de production = un lot. Plusieurs lots peuvent
-- coexister simultanément (plusieurs plaques en parallèle).
--
-- Numéro de lot : YY + séquentiel 4 chiffres, généré par l'appli
-- à la première sauvegarde (fin bloc 1).
-- ex: 260001 = année 2026, lot n°0001
-- Remis à 0001 au 1er janvier. Jamais réutilisé.
--
-- Cycle de vie :
--   preparation → saisie en cours (4 blocs progressifs)
--   en_repos    → lot en chambre froide, prêt à démarrer
--   production  → pesées en cours (fiche verrouillée)
--   stock       → mis en jarres, contrôle qualité effectué
--   abandonné   → lot perdu (jamais supprimé)
--
-- poids_brut_kg, poids_pulpe_kg, poids_base_kg :
--   Calculés par l'appli = somme pivot + fruits uniquement.
--   Les additifs ne sont pas inclus dans ces totaux.
--   Non modifiables directement — mis à jour via cruf_lot_fruit.
-- ------------------------------------------------------------
CREATE TABLE cruf_lot (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    numero_lot          VARCHAR(10)     NOT NULL UNIQUE,  -- ex: 260001
    saveur_id           INT UNSIGNED    NOT NULL,
    recette_id          INT UNSIGNED    DEFAULT NULL,     -- obligatoire métier, nullable BDD pour compatibilité
    date_production     DATE            NOT NULL,
    installation        VARCHAR(50)     DEFAULT NULL,     -- ex: Inox, Plastique — saisi au bureau ou au démarrage PWA

    -- ── Tare du matériel ──────────────────────────────────────
    tare_kg             DECIMAL(6,3)    DEFAULT NULL,     -- poids à vide du matériel (plaque/plateau)
                                                          -- mesuré une fois au démarrage, ne change pas
                                                          -- poids net relevé = poids_brut_kg - tare_kg

    -- ── Matière première (totaux calculés depuis cruf_lot_fruit) ──
    poids_brut_kg       DECIMAL(8,3)    NOT NULL,         -- somme poids_brut_kg pivot + fruits
    poids_pulpe_kg      DECIMAL(8,3)    NOT NULL,         -- somme poids_pulpe_kg pivot + fruits
    poids_base_kg       DECIMAL(8,3)    NOT NULL,         -- somme poids_base_kg pivot + fruits = base_kg Krencker

    -- ── Paramètres de formulation (saisis en bloc 4) ──────────
    brix_fruit          DECIMAL(5,2)    NOT NULL,         -- mesuré au réfractomètre sur le mélange global
    brix_cible          DECIMAL(5,2)    NOT NULL,         -- objectif (pré-rempli depuis saveur, modifiable)
    pct_fructose        DECIMAL(5,2)    NOT NULL,         -- % fructose dans sucre ajouté (pré-rempli depuis saveur)
    pa_cible            DECIMAL(5,2)    NOT NULL,         -- g pulpe / 100g crufiture (pré-rempli depuis saveur)

    -- ── Résultats calculés Krencker (stockés pour historique) ─
    sucre_fruit_kg      DECIMAL(8,4)    DEFAULT NULL,     -- poids_base_kg × (brix_fruit / 100)
    sa_kg               DECIMAL(8,4)    DEFAULT NULL,     -- sucre à ajouter (formule Krencker)
    fructose_kg         DECIMAL(8,4)    DEFAULT NULL,     -- sa_kg × (pct_fructose / 100)
    saccharose_kg       DECIMAL(8,4)    DEFAULT NULL,     -- sa_kg × (1 - pct_fructose / 100)
    masse_totale_kg     DECIMAL(8,4)    DEFAULT NULL,     -- poids_base_kg + sa_kg (masse posée sur plateau)
    evaporation_kg      DECIMAL(8,4)    DEFAULT NULL,     -- eau à évaporer = masse_totale_kg - cible_kg (valeur clé)
    cible_kg            DECIMAL(8,4)    DEFAULT NULL,     -- crufiture théorique = poids_base_kg × 100 / pa_cible

    -- ── Production réelle ──────────────────────────────────────
    poids_reel_kg       DECIMAL(8,3)    DEFAULT NULL,     -- poids réel total = somme contenu jarres (calculé au stock)
    heure_debut         TIME            DEFAULT NULL,     -- heure de pose sur la plaque (saisi au démarrage PWA)
    heure_mise_pot      TIME            DEFAULT NULL,     -- heure de mise en jarres
    note_production     TEXT            DEFAULT NULL,

    statut              ENUM('preparation','en_repos','production','stock','abandonné')
                        NOT NULL DEFAULT 'preparation',

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (saveur_id)  REFERENCES cruf_saveur(id),
    FOREIGN KEY (recette_id) REFERENCES cruf_recette(id),
    INDEX idx_date     (date_production),
    INDEX idx_statut   (statut),
    INDEX idx_saveur   (saveur_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 6. FRUITS D'UN LOT
-- Détail de chaque ingrédient utilisé dans ce lot.
-- Traçabilité et calculs de production par ingrédient.
--
-- type = 'pivot'  → fruit de référence (1 seul par lot)
--                   poids_brut_kg : saisi (bloc 2)
--                   poids_pulpe_kg : saisi (bloc 2)
--                   poids_base_kg : pré-rempli = poids_pulpe_kg, modifiable
--                   pct_base : NULL
--
-- type = 'fruit'  → autre fruit de la mixture
--                   poids_base_kg : calculé = poids_base_kg_pivot × pct_base / 100
--                   poids_pulpe_kg : pré-rempli = poids_base_kg calculé, modifiable
--                   poids_brut_kg : saisi (traçabilité)
--
-- type = 'additif'→ épice, jus, fleur...
--                   poids_base_kg : calculé = poids_base_kg_total_fruits × pct_base / 100
--                   poids_brut_kg et poids_pulpe_kg : NULL (non pertinents)
--
-- Les totaux du lot (cruf_lot.poids_brut_kg, poids_pulpe_kg,
-- poids_base_kg) sont la somme pivot + fruits uniquement.
-- Les additifs ont leur poids_base_kg stocké ici pour traçabilité
-- mais n'entrent dans aucun total du lot.
--
-- produit_id → FK rp_produit (peyrounet)
--              libellé affiché par jointure, jamais copié localement
-- ------------------------------------------------------------
CREATE TABLE cruf_lot_fruit (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    lot_id          INT UNSIGNED    NOT NULL,
    produit_id      INT UNSIGNED    NOT NULL,        -- FK rp_produit (peyrounet) — libellé par jointure
    type            ENUM('pivot','fruit','additif') NOT NULL DEFAULT 'additif',
    pct_base        DECIMAL(6,3)    DEFAULT NULL,    -- NULL pour le pivot ; % du pivot pour fruits ; % base totale fruits pour additifs
    poids_brut_kg   DECIMAL(8,3)    DEFAULT NULL,    -- poids brut avant préparation (pivot et fruits uniquement)
    poids_pulpe_kg  DECIMAL(8,3)    DEFAULT NULL,    -- poids après préparation (pivot et fruits uniquement)
    poids_base_kg   DECIMAL(8,3)    DEFAULT NULL,    -- poids net dans la formule Krencker
    fournisseur     VARCHAR(100)    DEFAULT NULL,
    origine         VARCHAR(100)    DEFAULT NULL,
    note            VARCHAR(255)    DEFAULT NULL,
    ordre           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (lot_id) REFERENCES cruf_lot(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 7. STOCKAGE EN JARRES
-- Un lot est stocké dans N jarres (en pratique 1 à 3, sans limite applicative).
-- Workflow de pesée :
--   1. Peser la jarre à vide → tare_kg
--   2. Remplir la jarre
--   3. Peser la jarre pleine → poids_pleine_kg
--   4. Contenu = poids_pleine_kg - tare_kg → poids_initial_kg (calculé par l'appli)
--   5. Répéter jusqu'à ce que la plaque soit vide
--
-- numero : index séquentiel géré par l'appli (1, 2, 3...), sans limite.
-- poids_initial_kg = poids_pleine_kg - tare_kg (calculé par l'appli au save).
--
-- Perte = poids net dernière pesée - somme(poids_initial_kg).
-- Recalculable depuis cruf_lot.cible_kg et cruf_lot.poids_reel_kg.
--
-- Sorties par jarre (hors scope v1) : cruf_jarre.poids_actuel_kg
-- ------------------------------------------------------------
CREATE TABLE cruf_jarre (
    id               INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    lot_id           INT UNSIGNED     NOT NULL,
    numero           TINYINT UNSIGNED NOT NULL,        -- 1, 2, 3...
    tare_kg          DECIMAL(6,3)     DEFAULT NULL,    -- poids jarre à vide (pesée avant remplissage)
    poids_pleine_kg  DECIMAL(6,3)     DEFAULT NULL,    -- poids jarre pleine (pesée après remplissage)
    poids_initial_kg DECIMAL(8,3)     NOT NULL,        -- contenu = poids_pleine_kg - tare_kg (calculé par l'appli)
    poids_actuel_kg  DECIMAL(8,3)     DEFAULT NULL,    -- poids restant (mis à jour à chaque sortie — hors scope v1)
    note             VARCHAR(255)     DEFAULT NULL,
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES cruf_lot(id),
    UNIQUE KEY uk_lot_jarre (lot_id, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 8. RELEVÉS D'ÉVAPORATION
-- Suivi en temps réel pendant la production (statut production).
-- Saisis depuis le bureau ou la PWA mobile /crufiture/production.
--
-- poids_brut_kg stocke le poids NET (tare déjà déduite côté frontend avant envoi).
--   reste_evap_kg = poids_brut_kg - cible_kg du lot (calculé au save, stocké).
--   Si reste_evap_kg <= 0 : cible atteinte.
--
-- Météo structurée pour connexion future capteur Bluetooth :
--   temperature, humidite, vent_kmh : saisie manuelle ou capteur
--   ensoleillement : 0=couvert 1=voilé 2=mi-ombre 3=ensoleillé
-- ------------------------------------------------------------
CREATE TABLE cruf_releve_evaporation (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    lot_id          INT UNSIGNED    NOT NULL,
    heure           TIME            NOT NULL,
    poids_brut_kg   DECIMAL(8,3)    NOT NULL,        -- poids net plateau (tare déjà déduite côté frontend)
    reste_evap_kg   DECIMAL(8,3)    DEFAULT NULL,    -- calculé : poids_brut_kg - cible_kg. ≤ 0 = cible atteinte.
    remarque        VARCHAR(255)    DEFAULT NULL,
    temperature     DECIMAL(4,1)    DEFAULT NULL,    -- °C
    humidite        DECIMAL(4,1)    DEFAULT NULL,    -- % humidité relative
    vent_kmh        DECIMAL(5,1)    DEFAULT NULL,    -- vitesse du vent km/h
    ensoleillement  TINYINT UNSIGNED DEFAULT NULL,   -- 0=couvert 1=voilé 2=mi-ombre 3=ensoleillé
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES cruf_lot(id),
    INDEX idx_lot_heure (lot_id, heure)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 9. CONTRÔLES QUALITÉ
-- Plusieurs contrôles possibles par lot dans le temps.
-- Au moins 1 obligatoire avant le passage en stock.
-- Le premier est de type 'mise_en_pot', les suivants sont libres.
-- ------------------------------------------------------------
CREATE TABLE cruf_controle (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    lot_id          INT UNSIGNED    NOT NULL,
    date_controle   DATE            NOT NULL,
    type_controle   ENUM('mise_en_pot','suivi','autre') NOT NULL DEFAULT 'suivi',
    brix_mesure     DECIMAL(5,2)    DEFAULT NULL,    -- réfractomètre
    aw_mesure       DECIMAL(6,4)    DEFAULT NULL,    -- activité eau ex: 0.7800
    ph_mesure       DECIMAL(4,2)    DEFAULT NULL,
    aspect          VARCHAR(200)    DEFAULT NULL,    -- description visuelle/texture
    remarque        TEXT            DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES cruf_lot(id),
    INDEX idx_lot_date (lot_id, date_controle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- RÉSUMÉ DES RELATIONS
-- ============================================================
--
--  cruf_saveur (1)
--    └── cruf_recette (N)               une saveur a plusieurs versions de recette
--          ├── cruf_recette_ingredient (N)   ingrédients avec type et proportion
--          │     produit_id → rp_produit (peyrounet) NOT NULL
--          │     pivot   → fruit de référence (pct_base NULL)
--          │     fruit   → autre fruit, pct_base = % du pivot
--          │     additif → ingrédient supp., pct_base = % de la base totale fruits
--          │     libellé affiché = rp_produit.libelle_canonique (jointure)
--          └── cruf_recette_etape (N)        étapes ordonnées (drag-and-drop)
--
--  cruf_lot (N) ──────────────────→ cruf_saveur (1)
--  cruf_lot (N) ──────────────────→ cruf_recette (1) (nullable BDD, obligatoire métier)
--    ├── cruf_lot_fruit (1-N)       ingrédients du lot (pivot, fruits, additifs)
--    ├── cruf_jarre (1-N)           stockage après production
--    │     tare_kg + poids_pleine_kg → poids_initial_kg calculé par l'appli
--    ├── cruf_releve_evaporation (N) pesées pendant la production
--    │     poids net = poids_brut_kg - cruf_lot.tare_kg
--    └── cruf_controle (N)          contrôles qualité (au moins 1 avant stock)
--
-- ============================================================
-- CALCULS HORS SCHEMA (effectués par l'appli, résultats stockés dans cruf_lot)
-- ============================================================
--
--  cible_kg        = poids_base_kg × 100 / pa_cible
--  total_sucre_kg  = cible_kg × brix_cible / 100
--  sucre_fruit_kg  = brix_fruit × poids_base_kg / 100
--  sa_kg           = total_sucre_kg - sucre_fruit_kg
--  fructose_kg     = sa_kg × (pct_fructose / 100)
--  saccharose_kg   = sa_kg × (1 - pct_fructose / 100)
--  masse_totale_kg = poids_base_kg + sa_kg
--  evaporation_kg  = masse_totale_kg - cible_kg       ← valeur clé de production
--  pa_etiquette    = poids_pulpe_kg × 100 / cible_kg  ← peut dépasser pa_cible si jus retiré (légal)
--
--  Poids net relevé = poids_brut_kg (tare déjà déduite au save)
--  reste_evap_kg    = poids_brut_kg - cible_kg (≤ 0 = cible atteinte)
--
--  Jarres :
--  poids_initial_kg (par jarre) = poids_pleine_kg - tare_kg
--  poids_reel_kg (lot)          = somme(poids_initial_kg) toutes jarres
--  perte_kg                     = poids_net_derniere_pesee - poids_reel_kg
--
--  Calcul à rebours (planification — optionnel si >= 1 lot antérieur en stock) :
--    rendement_brut_pulpe  = AVG(poids_pulpe_kg / poids_brut_kg) sur lots précédents de la saveur
--    rendement_pulpe_cruf  = AVG(poids_reel_kg / poids_base_kg)  sur lots précédents de la saveur
--    poids_brut_necessaire = cible_souhaitee / rendement_pulpe_cruf / rendement_brut_pulpe
--
--  Prix de revient (POST /peyrounet/api/inter/prix-revient) :
--    - Fruits    : poids_brut_kg de chaque cruf_lot_fruit (type pivot et fruit)
--    - Fructose  : fructose_kg
--    - Saccharose: saccharose_kg
--    - Additifs  : poids_base_kg de chaque cruf_lot_fruit (type additif)
--    → total HT matière / poids_reel_kg = coût HT / kg produit
--
-- ============================================================
-- HISTORIQUE DES MODIFICATIONS
-- ============================================================
--
-- v1.0 (2026-03-30) :
--   Création initiale — saveurs, recettes, lots, jarres, relevés, contrôles
--
-- v1.1 (2026-03-31) :
--   cruf_recette_ingredient :
--     + type ENUM('pivot','fruit','additif')
--   cruf_recette_etape : nouvelle table
--
-- v1.2 (2026-03-31) :
--   cruf_recette_ingredient :
--     - libelle supprimé → rp_produit.libelle_canonique par jointure
--     - unite supprimé → affichage kg/g déterminé à la volée
--     ~ produit_id DEFAULT NULL → NOT NULL
--
-- v2.0 (2026-04-04) — workflow lots v2 :
--   cruf_lot :
--     ~ pulpe_kg → poids_pulpe_kg
--     ~ base_kg  → poids_base_kg
--     ~ statut ENUM : ajout 'en_repos', suppression anciens statuts
--       (formule, en_production, mis_en_pot, controle, archive)
--   cruf_lot_fruit :
--     - fruit VARCHAR(100) supprimé → produit_id FK rp_produit
--     ~ poids_kg → poids_base_kg
--     + produit_id INT UNSIGNED NOT NULL
--     + type ENUM('pivot','fruit','additif')
--     + pct_base DECIMAL(6,3)
--     + poids_brut_kg DECIMAL(8,3)
--     + poids_pulpe_kg DECIMAL(8,3)
--
-- v3.0 (2026-04-05) — PWA mobile production :
--   cruf_lot :
--     + tare_kg DECIMAL(6,3) — poids à vide du matériel, mesuré au démarrage
--   cruf_releve_evaporation :
--     ~ poids_brut_kg : commentaire corrigé — stocke le NET (tare déduite côté frontend)
--     - meteo VARCHAR(100) supprimé → 4 champs structurés
--     + temperature DECIMAL(4,1) — °C
--     + humidite DECIMAL(4,1) — % humidité relative
--     + vent_kmh DECIMAL(5,1) — km/h
--     + ensoleillement TINYINT UNSIGNED — 0=couvert 1=voilé 2=mi-ombre 3=ensoleillé
--   cruf_jarre :
--     + tare_kg DECIMAL(6,3) — poids jarre à vide
--     + poids_pleine_kg DECIMAL(6,3) — poids jarre remplie
--     ~ poids_initial_kg : désormais calculé = poids_pleine_kg - tare_kg
--     ~ numero : sans limite applicative (en pratique 1 à 3)
--
-- ============================================================
