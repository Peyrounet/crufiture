-- ============================================================
-- MODULE /transformation — Gamme macération alcoolique
-- + Recettes tronc commun transfo_recette_*
-- À exécuter sur u191509486_dbboutique
-- ============================================================
--
-- CONTEXTE
-- Ce script crée :
--   1. Le tronc commun de recettes (transfo_recette_*) — utilisable par
--      toutes les gammes sauf crufiture (qui conserve cruf_recette_*)
--   2. L'extension recette macération alcoolique (mace_alcool_recette_version)
--   3. L'extension lot macération alcoolique (mace_alcool_lot)
--   4. L'ALTER transfo_lot_produit pour ajouter la colonne dlc
--
-- RÈGLES RECETTE TRONC COMMUN
--   - Ingrédients référencent stock_article_id directement (nullable, non-bloquant)
--   - Libelle stocké en clair (toujours renseigné même sans lien stock)
--   - Versioning identique au pattern fermeauberge : brouillon→en_test→validee
--   - Fork = nouvelle version (ancienne conservée pour traçabilité lots)
--   - Seules les versions "validee" sont utilisables au lancement d'un lot
--
-- RÈGLES LOT MACÉRATION ALCOOLIQUE
--   - transfo_lot_id bridge OBLIGATOIRE (contrairement à crufiture où il est NULL)
--   - Workflow : preparation→en_maceration→filtration→[assemblage]→maturation→stock
--   - Sorties (bouteilles) créées au stocker() uniquement — formats inconnus à l'avance
--   - Push /stock + push /registres obligatoires au stocker()
--   - Numéro de lot format MA[YY][NNNN] — séquentiel annuel gamme
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;


-- ── 1. ALTER transfo_lot_produit — ajout DLC ─────────────────
-- DLC = Date Limite de Consommation — renseignée au stocker()
-- Applicable à toutes les gammes (crufiture, macération, etc.)

ALTER TABLE `transfo_lot_produit`
  ADD COLUMN `dlc` date DEFAULT NULL
    COMMENT 'Date limite de consommation — renseignée au stocker()'
  AFTER `unite`;


-- ── 2. Recettes tronc commun ──────────────────────────────────

-- Identité de la recette — invariante entre les versions
CREATE TABLE IF NOT EXISTS `transfo_recette` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `gamme_id`   int(10) UNSIGNED NOT NULL,
  `nom`        varchar(200)     NOT NULL,
  `famille`    varchar(100)     DEFAULT NULL,
  `actif`      tinyint(1)       NOT NULL DEFAULT 1,
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gamme` (`gamme_id`),
  CONSTRAINT `fk_transfo_recette_gamme`
    FOREIGN KEY (`gamme_id`) REFERENCES `transfo_gamme` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Identité recette tronc commun — gamme_id, nom, famille';


-- Contenu versionné — workflow brouillon→en_test→validee
CREATE TABLE IF NOT EXISTS `transfo_recette_version` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `recette_id`       int(10) UNSIGNED NOT NULL,
  `numero`           int(10) UNSIGNED NOT NULL DEFAULT 1,
  `statut`           enum('brouillon','en_test','validee') NOT NULL DEFAULT 'brouillon',
  `notes_version`    text             DEFAULT NULL,
  `description`      text             DEFAULT NULL,
  `nb_unites`        int(10) UNSIGNED NOT NULL DEFAULT 1
                     COMMENT 'Nombre d unités produites pour les quantités indiquées',
  `unite_production` varchar(50)      DEFAULT NULL
                     COMMENT 'Ex: bouteilles 75cL, litres, bocaux 250g',
  `materiel`         text             DEFAULT NULL,
  `difficulte`       tinyint(1)       DEFAULT NULL
                     COMMENT '1=facile 2=moyen 3=difficile',
  `conservation`     varchar(200)     DEFAULT NULL,
  `created_at`       datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime         NOT NULL DEFAULT current_timestamp()
                     ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recette` (`recette_id`),
  KEY `idx_statut`  (`statut`),
  CONSTRAINT `fk_transfo_recette_version_recette`
    FOREIGN KEY (`recette_id`) REFERENCES `transfo_recette` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Version recette — versioning brouillon/en_test/validee';


-- Ingrédients — stock_article_id nullable (non-bloquant), libelle toujours renseigné
CREATE TABLE IF NOT EXISTS `transfo_recette_ingredient` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`       int(10) UNSIGNED NOT NULL,
  `stock_article_id` int(10) UNSIGNED DEFAULT NULL
                     COMMENT 'FK vers stock_article — nullable, non-bloquant',
  `libelle`          varchar(200)     NOT NULL
                     COMMENT 'Nom libre — toujours renseigné même sans lien stock',
  `quantite`         decimal(10,3)    NOT NULL,
  `coeff_perte`      decimal(5,3)     NOT NULL DEFAULT 1.000,
  `unite`            varchar(50)      NOT NULL,
  `note`             varchar(200)     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_version`       (`version_id`),
  KEY `idx_stock_article` (`stock_article_id`),
  CONSTRAINT `fk_transfo_recette_ingredient_version`
    FOREIGN KEY (`version_id`) REFERENCES `transfo_recette_version` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Ingrédients recette — stock_article_id nullable, libelle direct';


-- Phases du protocole — regroupent les étapes
CREATE TABLE IF NOT EXISTS `transfo_recette_phase` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`  int(10) UNSIGNED NOT NULL,
  `ordre`       int(10) UNSIGNED NOT NULL DEFAULT 0,
  `temporalite` varchar(100)     DEFAULT NULL
                COMMENT 'Ex: J+0, Semaine 2, Après filtration',
  `label`       varchar(200)     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_version` (`version_id`),
  CONSTRAINT `fk_transfo_recette_phase_version`
    FOREIGN KEY (`version_id`) REFERENCES `transfo_recette_version` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Phases du protocole — regroupement d étapes';


-- Étapes du protocole
CREATE TABLE IF NOT EXISTS `transfo_recette_etape` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`  int(10) UNSIGNED NOT NULL,
  `phase_id`    int(10) UNSIGNED NOT NULL,
  `ordre`       int(10) UNSIGNED NOT NULL DEFAULT 0,
  `description` text             NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_version` (`version_id`),
  KEY `idx_phase`   (`phase_id`),
  CONSTRAINT `fk_transfo_recette_etape_version`
    FOREIGN KEY (`version_id`) REFERENCES `transfo_recette_version` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transfo_recette_etape_phase`
    FOREIGN KEY (`phase_id`) REFERENCES `transfo_recette_phase` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Étapes du protocole de fabrication';


-- Points de contrôle qualité
CREATE TABLE IF NOT EXISTS `transfo_recette_controle` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`        int(10) UNSIGNED NOT NULL,
  `etape_label`       varchar(200)     DEFAULT NULL,
  `point_controle`    varchar(200)     NOT NULL,
  `valeur_cible`      varchar(200)     DEFAULT NULL,
  `action_corrective` text             DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_version` (`version_id`),
  CONSTRAINT `fk_transfo_recette_controle_version`
    FOREIGN KEY (`version_id`) REFERENCES `transfo_recette_version` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Points de contrôle qualité de la recette';


-- ── 3. Extension recette macération alcoolique ────────────────
-- Paramètres spécifiques à la gamme — relation 1:1 avec transfo_recette_version

CREATE TABLE IF NOT EXISTS `mace_alcool_recette_version` (
  `id`                          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfo_recette_version_id`  int(10) UNSIGNED NOT NULL,
  `duree_maceration_cible_j`    int(10) UNSIGNED DEFAULT NULL
                                COMMENT 'Durée cible de macération en jours',
  `duree_maturation_cible_j`    int(10) UNSIGNED DEFAULT NULL
                                COMMENT 'Durée cible de maturation en jours',
  `abv_cible_pct`               decimal(5,2)     DEFAULT NULL
                                COMMENT 'Taux d alcool cible (%vol)',
  `brix_cible`                  decimal(5,2)     DEFAULT NULL
                                COMMENT 'Brix cible — liqueurs uniquement (NULL = eau-de-vie)',
  `avec_assemblage`             tinyint(1)       NOT NULL DEFAULT 0
                                COMMENT '1 = ajout sirop (liqueur), 0 = eau-de-vie directe',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_version` (`transfo_recette_version_id`),
  CONSTRAINT `fk_mace_alcool_recette_version`
    FOREIGN KEY (`transfo_recette_version_id`)
    REFERENCES `transfo_recette_version` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Extension recette macération alcoolique — paramètres gamme-spécifiques';


-- ── 4. Extension lot macération alcoolique ────────────────────
-- Bridge transfo_lot_id OBLIGATOIRE (contrairement à cruf_lot)

CREATE TABLE IF NOT EXISTS `mace_alcool_lot` (
  `id`                       int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfo_lot_id`           int(10) UNSIGNED NOT NULL
                             COMMENT 'Bridge vers transfo_lot — obligatoire pour cette gamme',
  `recette_version_id`       int(10) UNSIGNED NOT NULL
                             COMMENT 'Version validée utilisée — immuable après création',
  `statut`                   enum(
                               'preparation',
                               'en_maceration',
                               'filtration',
                               'assemblage',
                               'maturation',
                               'stock',
                               'abandonne'
                             ) NOT NULL DEFAULT 'preparation',
  `date_debut_maceration`    datetime         DEFAULT NULL
                             COMMENT 'Horodatage passage en_maceration',
  `duree_maceration_cible_j` int(10) UNSIGNED DEFAULT NULL
                             COMMENT 'Copié depuis la recette au lancement — immuable',
  `date_filtration`          datetime         DEFAULT NULL
                             COMMENT 'Horodatage filtration — obligatoire pour traçabilité',
  `avec_assemblage`          tinyint(1)       NOT NULL DEFAULT 0
                             COMMENT 'Copié depuis la recette — détermine si étape assemblage',
  `date_debut_maturation`    datetime         DEFAULT NULL
                             COMMENT 'Horodatage passage en maturation',
  `duree_maturation_cible_j` int(10) UNSIGNED DEFAULT NULL
                             COMMENT 'Copié depuis la recette au lancement — immuable',
  `created_at`               datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`               datetime         NOT NULL DEFAULT current_timestamp()
                             ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transfo_lot` (`transfo_lot_id`),
  KEY `idx_recette_version` (`recette_version_id`),
  KEY `idx_statut`          (`statut`),
  CONSTRAINT `fk_mace_alcool_lot_transfo_lot`
    FOREIGN KEY (`transfo_lot_id`) REFERENCES `transfo_lot` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_mace_alcool_lot_recette_version`
    FOREIGN KEY (`recette_version_id`) REFERENCES `transfo_recette_version` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Extension lot macération alcoolique — workflow 6 statuts + horodatages';


SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================
-- RAPPEL — ALERTES DASHBOARD (calculées à la volée, pas de table)
-- ============================================================
--
-- Alerte fin macération :
--   SELECT ... FROM mace_alcool_lot
--   WHERE statut = 'en_maceration'
--     AND DATEDIFF(NOW(), date_debut_maceration) >= duree_maceration_cible_j
--
-- Alerte fin maturation :
--   SELECT ... FROM mace_alcool_lot
--   WHERE statut = 'maturation'
--     AND DATEDIFF(NOW(), date_debut_maturation) >= duree_maturation_cible_j
--
-- ============================================================
-- RAPPEL — STOCKER() (LotMaceAlcoolController)
-- ============================================================
--
-- 1. Saisie bouteilles produites → INSERT transfo_lot_produit (une ligne par format)
--    avec quantite_produite, unite = 'bouteille', dlc
-- 2. Push /stock par ligne : StockMouvementController::enregistrerMouvement()
--    source_service = 'maceration_alcool'
-- 3. Push /registres : RegistreController::push(...)
-- 4. UPDATE mace_alcool_lot SET statut = 'stock'
-- 5. UPDATE transfo_lot SET statut = 'stock'
--
-- ============================================================
-- CHANGELOG
-- ============================================================
-- juin 2026 v1 : Création.
--   transfo_recette, transfo_recette_version, transfo_recette_ingredient,
--   transfo_recette_phase, transfo_recette_etape, transfo_recette_controle.
--   mace_alcool_recette_version, mace_alcool_lot.
--   ALTER transfo_lot_produit ADD dlc.
-- ============================================================
