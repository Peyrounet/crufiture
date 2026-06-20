-- ============================================================
-- MODULE /transformation — Schéma tronc commun
-- Tables transfo_* — Catalogue gammes + produits + lots
-- À exécuter sur u191509486_dbboutique
-- ============================================================
--
-- CONTEXTE
-- /crufiture évolue en /transformation — module générique multi-gammes.
-- URL Hostinger : /crufiture → /transformation (paramétré via CRUFITURE_FOLDER).
-- Les tables cruf_* existantes sont conservées intactes et reliées au tronc
-- commun via FK optionnelles (transfo_produit_id, transfo_lot_id).
--
-- LOGIQUE GÉNÉRALE
-- Une gamme = un process de transformation (pas un type de produit commercial).
-- Un produit = un produit fini, appartient à une seule gamme, lié à un stock_article.
-- Un lot = une session de production : ingrédients entrants → produits sortants.
-- Le lot ne porte pas de produit_id — les sorties sont dans transfo_lot_produit.
-- transfo_lot_produit : quantite_produite NULL = intention, renseigné = produit réel.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── GAMMES ───────────────────────────────────────────────────
-- Process de transformation — détermine le modèle de suivi du lot.
-- Chaque gamme a son extension de tables (cruf_* pour crufiture, etc.)

CREATE TABLE `transfo_gamme` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`       varchar(50)  NOT NULL,
  `libelle`    varchar(150) NOT NULL,
  `actif`      tinyint(1)   NOT NULL DEFAULT 1,
  `created_at` datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Process de transformation — détermine le modèle de lot et ses extensions';

INSERT INTO `transfo_gamme` (`slug`, `libelle`) VALUES
  ('crufiture',         'Crufiture'),
  ('jus',               'Jus de fruit'),
  ('sechage',           'Séchage'),
  ('maceration_alcool', 'Macération alcoolique'),
  ('maceration_huile',  'Macération huileuse'),
  ('distillation',      'Distillation');


-- ── PRODUITS ─────────────────────────────────────────────────
-- Produit fini issu d'une gamme — lié à un article de stock.
-- slug unique global : préfixé par la gamme (ex: crufiture-framboise, jus-pomme).
-- stock_article_id → stock_article (cross-service, même base).
-- Pour crufiture : rempli automatiquement depuis cruf_saveur (voir migration).

CREATE TABLE `transfo_produit` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `gamme_id`         int(10) UNSIGNED NOT NULL,
  `nom`              varchar(150) NOT NULL,
  `slug`             varchar(150) NOT NULL,
  `stock_article_id` int(10) UNSIGNED DEFAULT NULL
                     COMMENT 'FK vers stock_article — article vendu en sortie de production',
  `note`             text DEFAULT NULL,
  `actif`            tinyint(1) NOT NULL DEFAULT 1,
  `created_at`       datetime   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_gamme` (`gamme_id`),
  KEY `idx_stock_article` (`stock_article_id`),
  CONSTRAINT `fk_transfo_produit_gamme`
    FOREIGN KEY (`gamme_id`) REFERENCES `transfo_gamme` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Produit fini — une gamme, un stock_article, N lots possibles';


-- ── LOT — TRONC COMMUN ───────────────────────────────────────
-- Structure partagée par toutes les gammes.
-- Pas de produit_id ici — les sorties sont dans transfo_lot_produit.
-- Les spécificités de chaque gamme vivent dans des tables d'extension.

CREATE TABLE `transfo_lot` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `gamme_id`        int(10) UNSIGNED NOT NULL,
  `numero_lot`      varchar(10)  NOT NULL COMMENT 'Format YY0001 — séquentiel module',
  `date_production` date         NOT NULL,
  `statut`          enum('preparation','en_repos','production','stock','abandonné')
                    NOT NULL DEFAULT 'preparation',
  `note`            text DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_lot` (`numero_lot`),
  KEY `idx_gamme` (`gamme_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_date` (`date_production`),
  CONSTRAINT `fk_transfo_lot_gamme`
    FOREIGN KEY (`gamme_id`) REFERENCES `transfo_gamme` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Tronc commun de lot — sans produit_id, sorties dans transfo_lot_produit';


-- ── LOT → INGRÉDIENTS ────────────────────────────────────────
-- Matières premières entrant dans le lot.
-- article_id → prix_article (catalogue matières premières — cross-service).
-- Les rôles d'ingrédients (pivot/fruit/additif) sont une extension crufiture,
-- pas modélisés ici.

CREATE TABLE `transfo_lot_ingredient` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`     int(10) UNSIGNED NOT NULL,
  `article_id` int(10) UNSIGNED NOT NULL
               COMMENT 'FK vers prix_article — matière première achetée ou produite',
  `quantite`   decimal(10,3) NOT NULL,
  `unite`      varchar(20)   NOT NULL DEFAULT 'kg',
  `ordre`      tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `note`       text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lot` (`lot_id`),
  KEY `idx_article` (`article_id`),
  CONSTRAINT `fk_transfo_lot_ingredient_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `transfo_lot` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Ingrédients entrants — génériques, sans rôles (rôles = extension crufiture)';


-- ── LOT → PRODUITS SORTANTS ──────────────────────────────────
-- Ce que le lot a produit. Rempli selon la gamme :
--   - Gammes à produit connu à l'avance (crufiture, jus, macération) :
--     ligne créée à la création du lot avec quantite_produite = NULL,
--     renseignée lors du stocker().
--   - Distillation et gammes à sorties libres :
--     lignes créées au stocker() selon ce qu'on a collecté.
-- Le nombre de lignes par lot dépend de la gamme, décidé au codage de la gamme.

CREATE TABLE `transfo_lot_produit` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`            int(10) UNSIGNED NOT NULL,
  `produit_id`        int(10) UNSIGNED NOT NULL,
  `quantite_produite` decimal(10,3) DEFAULT NULL
                      COMMENT 'NULL = intention, renseigné = produit réel confirmé',
  `unite`             varchar(20) NOT NULL DEFAULT 'kg',
  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lot` (`lot_id`),
  KEY `idx_produit` (`produit_id`),
  CONSTRAINT `fk_transfo_lot_produit_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `transfo_lot` (`id`),
  CONSTRAINT `fk_transfo_lot_produit_produit`
    FOREIGN KEY (`produit_id`) REFERENCES `transfo_produit` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Sorties du lot — 1..N produits, quantite_produite NULL avant stocker()';


-- ── CONTRÔLES QUALITÉ ────────────────────────────────────────
-- Contrôles communs à toutes les gammes.
-- Mesures optionnelles — chaque gamme utilise les colonnes pertinentes.
--   brix_mesure  : crufiture, jus, séchage
--   aw_mesure    : crufiture, séchage
--   ph_mesure    : crufiture, jus
--   abv_mesure   : macération alcoolique, distillation

CREATE TABLE `transfo_controle` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`        int(10) UNSIGNED NOT NULL,
  `date_controle` date        NOT NULL,
  `type_controle` varchar(50) NOT NULL DEFAULT 'suivi',
  `brix_mesure`   decimal(5,2)  DEFAULT NULL,
  `aw_mesure`     decimal(5,4)  DEFAULT NULL,
  `ph_mesure`     decimal(5,2)  DEFAULT NULL,
  `abv_mesure`    decimal(5,2)  DEFAULT NULL
                  COMMENT 'Degré alcool — macération alcoolique, distillation',
  `aspect`        varchar(200) DEFAULT NULL,
  `remarque`      text DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lot` (`lot_id`),
  CONSTRAINT `fk_transfo_controle_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `transfo_lot` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Contrôles qualité — mesures optionnelles selon gamme';


-- ============================================================
-- BRIDGE CRUFITURE → TRONC COMMUN
-- Les tables cruf_* sont conservées intactes.
-- On ajoute des FK optionnelles pour relier au tronc commun.
-- Migration progressive — NULL pendant la transition.
-- ============================================================

ALTER TABLE `cruf_saveur`
  ADD COLUMN `transfo_produit_id` int(10) UNSIGNED DEFAULT NULL
    COMMENT 'FK vers transfo_produit — renseigné après migration',
  ADD CONSTRAINT `fk_cruf_saveur_transfo_produit`
    FOREIGN KEY (`transfo_produit_id`) REFERENCES `transfo_produit` (`id`) ON DELETE SET NULL;

ALTER TABLE `cruf_lot`
  ADD COLUMN `transfo_lot_id` int(10) UNSIGNED DEFAULT NULL
    COMMENT 'FK vers transfo_lot — renseigné pour les nouveaux lots, NULL pour l historique',
  ADD CONSTRAINT `fk_cruf_lot_transfo_lot`
    FOREIGN KEY (`transfo_lot_id`) REFERENCES `transfo_lot` (`id`) ON DELETE SET NULL;


-- ============================================================
-- MIGRATION DES DONNÉES EXISTANTES
-- ============================================================

-- 1. Peupler transfo_produit depuis cruf_saveur
--    Slug préfixé : crufiture-framboise, crufiture-betterave...
INSERT INTO `transfo_produit` (`gamme_id`, `nom`, `slug`, `stock_article_id`, `note`, `actif`)
SELECT
  (SELECT `id` FROM `transfo_gamme` WHERE `slug` = 'crufiture'),
  s.`nom`,
  CONCAT('crufiture-', s.`slug`),
  s.`stock_article_id`,
  s.`note`,
  s.`actif`
FROM `cruf_saveur` s;

-- 2. Relier cruf_saveur → transfo_produit
UPDATE `cruf_saveur` s
JOIN `transfo_produit` p
  ON p.`slug` = CONCAT('crufiture-', s.`slug`)
 AND p.`gamme_id` = (SELECT `id` FROM `transfo_gamme` WHERE `slug` = 'crufiture')
SET s.`transfo_produit_id` = p.`id`;

-- 3. Renommer le module dans ferme_module
UPDATE `ferme_module`
SET `slug` = 'transformation', `libelle` = 'Transformations'
WHERE `slug` = 'crufiture';


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- CHANGELOG
-- ============================================================
-- juin 2026 v1 : Création tronc commun /transformation.
--   transfo_gamme, transfo_produit, transfo_lot, transfo_lot_ingredient,
--   transfo_lot_produit, transfo_controle.
--   Bridge cruf_saveur.transfo_produit_id + cruf_lot.transfo_lot_id.
--   Migration données : transfo_produit depuis cruf_saveur.
--   ferme_module : slug crufiture → transformation.
-- ============================================================
