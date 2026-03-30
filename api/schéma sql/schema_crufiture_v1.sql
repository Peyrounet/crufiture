-- ============================================================
-- Module /crufiture — Schema SQL v1
-- Base : u191509486_dbboutique (partagée Peyrounet)
-- Préfixe : cruf_*
-- Généré le : 2026-03-30
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
-- Contient les instructions texte + la liste de TOUS les
-- ingrédients avec leur proportion relative à la base.
--
-- Le fruit principal est listé comme ingrédient sans pct_base
-- (pct_base NULL = c'est LA base, le pivot des calculs).
-- Les additifs (fleurs, épices, jus citron...) ont un pct_base.
-- Fructose et saccharose sont implicites (calculés par formulation).
--
-- Usage prix de revient :
--   qté réelle = base_kg × (pct_base / 100) pour les additifs
--   qté fruit  = base_kg (c'est la base elle-même)
--   qté fructose/saccharose = issues des calculs du lot
-- ------------------------------------------------------------
CREATE TABLE cruf_recette (
    id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    saveur_id       INT UNSIGNED     NOT NULL,
    version         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    titre           VARCHAR(200)     NOT NULL,
    instructions    TEXT             NOT NULL,   -- étapes texte libre
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
-- pct_base NULL  → fruit principal (= la base, pivot des calculs)
--                  qté réelle sur lot = base_kg
-- pct_base > 0   → additif (fleurs, épices, jus citron...)
--                  qté réelle sur lot = base_kg × (pct_base / 100)
--
-- produit_id → FK vers rp_produit dans /peyrounet
--              pour le calcul du prix de revient matière.
--              Nullable si le produit n'est pas encore référencé.
-- ------------------------------------------------------------
CREATE TABLE cruf_recette_ingredient (
    id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    recette_id      INT UNSIGNED     NOT NULL,
    libelle         VARCHAR(100)     NOT NULL,        -- ex: Rhubarbe, Fleurs de sureau, Jus de citron
    produit_id      INT UNSIGNED     DEFAULT NULL,    -- FK peyrounet rp_produit (nullable)
    pct_base        DECIMAL(6,3)     DEFAULT NULL,    -- NULL = fruit principal ; sinon % de base_kg
    unite           VARCHAR(20)      NOT NULL,        -- kg, g, L, cl, pièce...
    note            VARCHAR(255)     DEFAULT NULL,
    ordre           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (recette_id) REFERENCES cruf_recette(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 4. LOTS DE PRODUCTION
-- Cœur du module. Un lot = une session de production complète.
--
-- Numéro de lot : YY + séquentiel 4 chiffres, généré par l'appli
-- ex: 250099 = année 2025, lot n°0099
--
-- Cycle de vie :
--   formule      → paramètres saisis, calculs effectués
--   en_production → évaporation en cours (plateau posé)
--   mis_en_pot   → production terminée, poids réel connu
--   controle     → au moins un contrôle qualité effectué
--   archive      → lot clôturé
-- ------------------------------------------------------------
CREATE TABLE cruf_lot (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    numero_lot          VARCHAR(10)     NOT NULL UNIQUE,  -- ex: 250099
    saveur_id           INT UNSIGNED    NOT NULL,
    recette_id          INT UNSIGNED    DEFAULT NULL,
    date_production     DATE            NOT NULL,
    installation        VARCHAR(50)     DEFAULT NULL,     -- ex: Inox, Plastique

    -- ── Matière première (mesures physiques réelles) ──────────
    poids_brut_kg       DECIMAL(8,3)    NOT NULL,         -- fruits bruts avant nettoyage
    pulpe_kg            DECIMAL(8,3)    NOT NULL,         -- après préparation/extraction
    base_kg             DECIMAL(8,3)    NOT NULL,         -- part utilisée ≤ pulpe_kg
    -- Note : pulpe_kg - base_kg = jus retiré pour densifier

    -- ── Paramètres de formulation (entrées utilisateur) ───────
    brix_fruit          DECIMAL(5,2)    NOT NULL,         -- mesuré au réfractomètre sur le mélange
    brix_cible          DECIMAL(5,2)    NOT NULL,         -- objectif (pré-rempli depuis saveur)
    pct_fructose        DECIMAL(5,2)    NOT NULL,         -- % fructose dans sucre ajouté (le "50/50")
    pa_cible            DECIMAL(5,2)    NOT NULL,         -- g pulpe / 100g crufiture (étiquette)

    -- ── Résultats calculés (stockés pour historique et PDR) ───
    -- Formules issues du procédé breveté Krencker
    sucre_fruit_kg      DECIMAL(8,4)    DEFAULT NULL,     -- base_kg × (brix_fruit/100)
    sa_kg               DECIMAL(8,4)    DEFAULT NULL,     -- saccharose ajouté total
    fructose_kg         DECIMAL(8,4)    DEFAULT NULL,     -- sa_kg × (pct_fructose/100)
    saccharose_kg       DECIMAL(8,4)    DEFAULT NULL,     -- sa_kg × (1 - pct_fructose/100)
    masse_totale_kg     DECIMAL(8,4)    DEFAULT NULL,     -- base_kg + sa_kg
    evaporation_kg      DECIMAL(8,4)    DEFAULT NULL,     -- eau à évaporer — valeur clé production
    cible_kg            DECIMAL(8,4)    DEFAULT NULL,     -- crufiture théorique = masse_totale - evaporation

    -- ── Production réelle ──────────────────────────────────────
    poids_reel_kg       DECIMAL(8,3)    DEFAULT NULL,     -- poids réel mis en pot/jarre
    heure_debut         TIME            DEFAULT NULL,
    heure_mise_pot      TIME            DEFAULT NULL,
    note_production     TEXT            DEFAULT NULL,

    statut              ENUM('formule','en_production','mis_en_pot','controle','archive')
                        NOT NULL DEFAULT 'formule',

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (saveur_id)  REFERENCES cruf_saveur(id),
    FOREIGN KEY (recette_id) REFERENCES cruf_recette(id),
    INDEX idx_date     (date_production),
    INDEX idx_statut   (statut),
    INDEX idx_saveur   (saveur_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 5. FRUITS D'UN LOT
-- Détail des fruits/légumes/fleurs utilisés dans ce lot.
-- Traçabilité et étiquetage multi-fruits.
-- Le brix est mesuré sur le mélange global (dans cruf_lot),
-- pas par fruit individuel.
-- ------------------------------------------------------------
CREATE TABLE cruf_lot_fruit (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    lot_id          INT UNSIGNED    NOT NULL,
    fruit           VARCHAR(100)    NOT NULL,        -- ex: Rhubarbe, Fraise, Betterave
    fournisseur     VARCHAR(100)    DEFAULT NULL,
    origine         VARCHAR(100)    DEFAULT NULL,
    poids_kg        DECIMAL(8,3)    DEFAULT NULL,    -- poids de CE fruit dans le lot brut
    note            VARCHAR(255)    DEFAULT NULL,
    ordre           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (lot_id) REFERENCES cruf_lot(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 6. STOCKAGE EN JARRES
-- Un lot est stocké dans 1 à 3 jarres maximum.
-- Chaque jarre a son poids de crufiture.
-- Permet le suivi des sorties par jarre (vente vrac, mise en pot).
-- ------------------------------------------------------------
CREATE TABLE cruf_jarre (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    lot_id          INT UNSIGNED    NOT NULL,
    numero          TINYINT UNSIGNED NOT NULL,       -- 1, 2 ou 3
    poids_initial_kg DECIMAL(8,3)   NOT NULL,        -- poids mis en jarre
    poids_actuel_kg DECIMAL(8,3)    DEFAULT NULL,    -- poids restant (mis à jour à chaque sortie)
    note            VARCHAR(255)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES cruf_lot(id),
    UNIQUE KEY uk_lot_jarre (lot_id, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 7. RELEVÉS D'ÉVAPORATION
-- Suivi en temps réel pendant la production.
-- Heure / poids plateau / reste à évaporer / météo.
-- Permet de suivre la progression vers la cible.
-- ------------------------------------------------------------
CREATE TABLE cruf_releve_evaporation (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    lot_id          INT UNSIGNED    NOT NULL,
    heure           TIME            NOT NULL,
    poids_brut_kg   DECIMAL(8,3)    NOT NULL,        -- poids plateau à cet instant (tare déduite)
    reste_evap_kg   DECIMAL(8,3)    DEFAULT NULL,    -- calculé : poids_brut - cible_kg
    meteo           VARCHAR(100)    DEFAULT NULL,    -- ex: Ensoleillé 28°C, Vent modéré
    remarque        VARCHAR(255)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES cruf_lot(id),
    INDEX idx_lot_heure (lot_id, heure)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 8. CONTRÔLES QUALITÉ
-- Plusieurs contrôles possibles par lot dans le temps.
-- Le premier est effectué à la mise en pot (brix atteint ?).
-- Les suivants sont libres (J+7, J+30, par envie...).
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
--    └── cruf_recette (N)          une saveur peut avoir plusieurs versions de recette
--          └── cruf_recette_ingredient (N)  ingrédients avec % de base (NULL = fruit pivot)
--
--  cruf_lot (N) ──────────────────→ cruf_saveur (1)
--  cruf_lot (N) ──────────────────→ cruf_recette (1)  (nullable)
--    ├── cruf_lot_fruit (1-N)       fruits/légumes du lot avec traçabilité
--    ├── cruf_jarre (1-3)           stockage (max 3 jarres par lot)
--    ├── cruf_releve_evaporation (N) suivi temps réel évaporation
--    └── cruf_controle (N)          contrôles qualité dans le temps
--
-- ============================================================
-- CALCULS HORS SCHEMA (effectués par l'appli, stockés dans cruf_lot)
-- ============================================================
--
--  sucre_fruit_kg  = base_kg × (brix_fruit / 100)
--  sa_kg           = [formule Krencker — brix_fruit, brix_cible, base_kg, pct_fructose]
--  fructose_kg     = sa_kg × (pct_fructose / 100)
--  saccharose_kg   = sa_kg × (1 - pct_fructose / 100)
--  masse_totale_kg = base_kg + sa_kg
--  evaporation_kg  = masse_totale_kg - cible_kg
--  cible_kg        = [formule Krencker — valeur clé]
--
--  Calcul à rebours (planification) :
--    Disponible dès le 2ème lot de la même saveur.
--    rendement_brut_pulpe = AVG(pulpe_kg / poids_brut_kg) sur lots précédents
--    rendement_pulpe_cruf = AVG(poids_reel_kg / base_kg) sur lots précédents
--    → poids_brut_nécessaire = cible_souhaitée / rendement_pulpe_cruf / rendement_brut_pulpe
--
--  Prix de revient (POST /peyrounet/api/inter/prix-revient) :
--    - Fruits    : poids_brut_kg (ou base_kg selon référencement)
--    - Fructose  : fructose_kg
--    - Saccharose: saccharose_kg
--    - Additifs  : base_kg × (pct_base / 100) pour chaque cruf_recette_ingredient
--    → total HT matière / poids_reel_kg = coût HT / kg produit
--    → + emballage selon conditionnement (vrac, pot 100g, pot 300g)
--
-- ============================================================
