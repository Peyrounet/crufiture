-- ============================================================
-- Schéma SQL v4 — Ferme du Peyrounet
-- Généré le 6 avril 2026 depuis le dump de production
-- MariaDB 11.8.6 — utf8mb4_uca1400_ai_ci
-- ============================================================
--
-- HISTORIQUE DES VERSIONS
-- =======================
-- v1-v3 : module monolithique /peyrounet — préfixe pey_* et rp_*
-- v4     : big bang avril 2026 — éclatement en 4 services transverses
--          /ferme  → préfixe ferme_*
--          /compta → préfixe compta_*
--          /prix   → préfixe prix_*
--          /stock  → préfixe stock_*
--          Tables cruf_*, ff_*, ferme_* déjà en production inchangées
--
-- ANOMALIES IDENTIFIÉES — CORRIGÉES EN BASE LE 6 AVRIL 2026
-- ============================================================
-- 1. compta_cle_repartition et compta_tva_declaration ont des FK
--    vers compta_activite (qui n'existe pas) au lieu de ferme_activite.
--    Corriger : ALTER TABLE compta_cle_repartition
--               DROP FOREIGN KEY compta_cle_repartition_ibfk_1,
--               ADD FOREIGN KEY (activite_id) REFERENCES ferme_activite(id);
--               Idem pour compta_tva_declaration.
-- 2. compta_sequence a une PK sur (prefix) seul au lieu de (prefix, exercice).
--    Corriger : ALTER TABLE compta_sequence
--               DROP PRIMARY KEY,
--               ADD PRIMARY KEY (prefix, exercice);
-- 3. prix_releve.date_releve est de type DATE au lieu de DATETIME
--    (l'heure du ticket doit être conservée).
--    Corriger : ALTER TABLE prix_releve
--               MODIFY date_releve DATETIME NOT NULL;
--
-- ARCHITECTURE MULTI-MODULES
-- ==========================
-- Une seule base MySQL. Chaque service a accès total à ses tables.
-- Les lectures cross-service (même base) sont faites directement en SQL.
-- Pas d'utilisateurs MySQL différents — restriction applicative uniquement.
--
-- RÈGLES IMMUABLES
-- ================
-- 1. ferme_activite.regime_tva : INSERT uniquement, jamais UPDATE
--    Le régime s'applique depuis regime_depuis — historique intact.
-- 2. compta_ecriture.numero_ecriture : séquentiel par exercice, immuable
--    une fois l'écriture validée.
-- 3. compta_ecriture.periode_exercice : dérivé de date_document,
--    JAMAIS de date_saisie.
-- 4. Les écritures ne connaissent que des poste_id.
--    La caisse est la couche opérationnelle, pas comptable.
--
-- RÈGLE PARTIE DOUBLE
-- ====================
-- SUM(montant WHERE sens='debit') = SUM(montant WHERE sens='credit')
-- Vérifiée applicativement avant validation — jamais contournée.
--
-- TVA — TROIS NIVEAUX
-- ====================
-- Récupérable si :
--   ferme_activite.regime_tva ≠ 'non_assujetti'      (niveau activité)
--   ET compta_poste.tva_deductible = true             (niveau poste)
--   ET compta_document_ligne.tva_recuperable ≠ false  (niveau ligne)
--
-- TABLES FUTURES PRÉVUES (sans refonte schéma)
-- =============================================
-- compta_paiement         : règlements, lettrage relevé bancaire, impayés
-- compta_immobilisation   : tableaux d'amortissement (flag is_immobilisation prêt)
-- compta_poste_mapping    : correspondance plan comptable agricole (601, 707...)
-- stock_article           : catalogue articles produits par la ferme (v1)
-- stock_mouvement         : entrées/sorties/transferts de stock (v2)
-- stock_reservation       : réservations panier (v3)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- MODULE /ferme — Référentiel métier
-- ============================================================

-- ── MODULES ──────────────────────────────────────────────────────
-- Entités techniques (applications web). Pas des entités comptables.
-- Un module peut porter N activités économiques distinctes.

CREATE TABLE `ferme_module` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        varchar(50)  NOT NULL COMMENT 'Identifiant technique ex: foretfeerique, compta, prix',
  `libelle`     varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `actif`       tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Applications web — entité technique, pas comptable';


-- ── ACTIVITÉS ────────────────────────────────────────────────────
-- Entité économique fondamentale. Porte le régime TVA horodaté.
-- RÈGLE ABSOLUE : INSERT uniquement, jamais UPDATE sur regime_tva.
-- inclus_compta=false → suivi interne uniquement (ex: activité Perso)

CREATE TABLE `ferme_activite` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_id`     int(10) UNSIGNED DEFAULT NULL COMMENT 'Nullable — activité sans module applicatif possible',
  `libelle`       varchar(150) NOT NULL,
  `regime_tva`    enum('non_assujetti','franchise','reel_simplifie','reel_normal')
                  NOT NULL DEFAULT 'non_assujetti',
  `regime_depuis` date NOT NULL COMMENT 'Date application du régime — INSERT uniquement, jamais UPDATE',
  `type_fiscal`   enum('agricole','commercial','prestation_service','mixte')
                  NOT NULL DEFAULT 'prestation_service',
  `inclus_compta` tinyint(1) NOT NULL DEFAULT 1
                  COMMENT 'Si 0, activité suivie mais exclue du bilan professionnel',
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module_id`),
  KEY `idx_regime_depuis` (`regime_depuis`),
  CONSTRAINT `ferme_activite_ibfk_1`
    FOREIGN KEY (`module_id`) REFERENCES `ferme_module` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Entité économique. Régime TVA horodaté — INSERT only, jamais UPDATE';


-- ── ACTUALITÉS ───────────────────────────────────────────────────

CREATE TABLE `ferme_actualite` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `titre`      varchar(200)  NOT NULL,
  `contenu`    text DEFAULT NULL,
  `image_path` varchar(500)  DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin`   date DEFAULT NULL,
  `active`     tinyint(1)    DEFAULT 1,
  `created_at` datetime      DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── RACCOURCIS ────────────────────────────────────────────────────

CREATE TABLE `ferme_raccourci` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `label`      varchar(100)  NOT NULL,
  `icone`      varchar(50)   NOT NULL DEFAULT 'pi-link',
  `lien`       varchar(500)  NOT NULL,
  `couleur_bg` varchar(20)   DEFAULT '#f0fdf4',
  `ordre`      tinyint(3) UNSIGNED DEFAULT 0,
  `actif`      tinyint(1)    DEFAULT 1,
  `created_at` datetime      DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── CONFIG FERME ──────────────────────────────────────────────────

CREATE TABLE `ferme_config` (
  `cle`        varchar(100) NOT NULL,
  `valeur`     text NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- MODULE /compta — Service comptabilité
-- ============================================================

-- ── PLAN DE COMPTES ───────────────────────────────────────────────
-- Plan de comptes personnel — arbre libre, 1 niveau de sous-postes max.
-- nature : charge=cl6, recette=cl7, immobilisation=cl2, tresorerie=cl5, tiers=cl4
-- tva_deductible : pertinent uniquement pour nature=charge

CREATE TABLE `compta_poste` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `libelle`        varchar(200) NOT NULL,
  `parent_id`      int(10) UNSIGNED DEFAULT NULL COMMENT 'Null = poste racine — 1 niveau max',
  `nature`         enum('charge','recette','immobilisation','tresorerie','tiers') NOT NULL
                   COMMENT 'charge=cl6, recette=cl7, immobilisation=cl2, tresorerie=cl5, tiers=cl4',
  `inclus_bilan`   tinyint(1) NOT NULL DEFAULT 1
                   COMMENT 'Si 0, suivi interne uniquement (ex: dépenses personnelles)',
  `tva_deductible` tinyint(1) NOT NULL DEFAULT 1
                   COMMENT 'Si 0, TVA jamais récupérable — pertinent pour nature=charge uniquement',
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_nature` (`nature`),
  CONSTRAINT `compta_poste_ibfk_1`
    FOREIGN KEY (`parent_id`) REFERENCES `compta_poste` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Plan de comptes personnel — arbre libre, correspondance plan comptable en couche future';


-- ── FOURNISSEURS ─────────────────────────────────────────────────
-- Propriétaire unique de tous les tiers fournisseurs.
-- /prix délègue la création à /compta via creerFournisseurInterne().
-- inclus_moyenne : lu par /prix pour les calculs de prix moyen.

CREATE TABLE `compta_fournisseur` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`            varchar(150) NOT NULL,
  `type`           enum('grande_surface','grossiste','direct_producteur','artisan','autre')
                   NOT NULL DEFAULT 'autre',
  `inclus_moyenne` tinyint(1) NOT NULL DEFAULT 1
                   COMMENT 'Si 0, relevés enregistrés mais exclus des calculs prix moyen (/prix)',
  `adresse`        varchar(300) DEFAULT NULL,
  `ville`          varchar(100) DEFAULT NULL,
  `cp`             varchar(10)  DEFAULT NULL,
  `email`          varchar(150) DEFAULT NULL,
  `telephone`      varchar(20)  DEFAULT NULL,
  `siret`          varchar(14)  DEFAULT NULL,
  `actif`          tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Fournisseurs — propriétaire /compta, inclus_moyenne lu par /prix';


-- ── CLIENTS ───────────────────────────────────────────────────────

CREATE TABLE `compta_client` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`        varchar(150) NOT NULL,
  `prenom`     varchar(100) DEFAULT NULL,
  `adresse`    varchar(300) DEFAULT NULL,
  `ville`      varchar(100) DEFAULT NULL,
  `cp`         varchar(10)  DEFAULT NULL,
  `email`      varchar(150) DEFAULT NULL,
  `telephone`  varchar(20)  DEFAULT NULL,
  `type`       enum('particulier','professionnel','collectivite','autre')
               NOT NULL DEFAULT 'particulier',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── CAISSES ───────────────────────────────────────────────────────
-- Couche opérationnelle liée à un poste de trésorerie.
-- Les écritures utilisent poste_id, pas caisse_id.
-- activite_id=NULL → caisse partagée (ex: compte bancaire)

CREATE TABLE `compta_caisse` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `poste_id`    int(10) UNSIGNED NOT NULL COMMENT 'FK vers poste de type tresorerie',
  `activite_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Null = caisse partagée (ex: compte bancaire)',
  `libelle`     varchar(150) NOT NULL,
  `type`        enum('caisse','banque') NOT NULL DEFAULT 'caisse'
                COMMENT 'caisse=physique (marché, boutique...), banque=compte bancaire',
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `poste_id` (`poste_id`),
  KEY `idx_activite` (`activite_id`),
  CONSTRAINT `compta_caisse_ibfk_1`
    FOREIGN KEY (`poste_id`) REFERENCES `compta_poste` (`id`),
  CONSTRAINT `compta_caisse_ibfk_2`
    FOREIGN KEY (`activite_id`) REFERENCES `ferme_activite` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Couche opérationnelle de trésorerie — les écritures utilisent poste_id, pas caisse_id';


-- ── SESSIONS DE CAISSE ────────────────────────────────────────────
-- Réconciliation physique périodique.
-- La source de vérité du solde reste compta_ecriture_ligne.

CREATE TABLE `compta_session_caisse` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `caisse_id`         int(10) UNSIGNED NOT NULL,
  `date_ouverture`    datetime     NOT NULL,
  `montant_ouverture` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fond de caisse de départ',
  `date_cloture`      datetime     DEFAULT NULL,
  `montant_cloture`   decimal(10,2) DEFAULT NULL COMMENT 'Comptage physique réel en clôture',
  `montant_theorique` decimal(10,2) DEFAULT NULL COMMENT 'Calculé depuis ecriture_ligne',
  `ecart`             decimal(10,2) DEFAULT NULL COMMENT 'montant_theorique - montant_cloture',
  `note`              text DEFAULT NULL,
  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_caisse` (`caisse_id`),
  KEY `idx_ouverture` (`date_ouverture`),
  CONSTRAINT `compta_session_caisse_ibfk_1`
    FOREIGN KEY (`caisse_id`) REFERENCES `compta_caisse` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Feuille de caisse — réconciliation périodique, pas source de vérité du solde';


-- ── DOCUMENTS ─────────────────────────────────────────────────────
-- Pièce justificative. Fondement de toute écriture comptable.
-- date_document = date sur la pièce → détermine periode_exercice
-- date_saisie   = timestamp système → traçabilité uniquement

CREATE TABLE `compta_document` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`            enum('ticket','facture_achat','facture_vente','devis') NOT NULL,
  `fournisseur_id`  int(10) UNSIGNED DEFAULT NULL COMMENT 'Renseigné pour ticket et facture_achat',
  `client_id`       int(10) UNSIGNED DEFAULT NULL COMMENT 'Renseigné pour facture_vente',
  `date_document`   date     NOT NULL COMMENT 'Date sur la pièce — référence comptable et fiscale',
  `date_saisie`     datetime NOT NULL DEFAULT current_timestamp()
                    COMMENT 'Date d enregistrement système — traçabilité uniquement',
  `numero_document` varchar(50)  DEFAULT NULL COMMENT 'N° fournisseur (reçu) ou notre séquentiel (émis)',
  `scan_path`       varchar(500) DEFAULT NULL COMMENT 'Chemin vers l image scannée',
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_date_doc` (`date_document`),
  KEY `idx_fournisseur` (`fournisseur_id`),
  KEY `idx_client` (`client_id`),
  CONSTRAINT `compta_document_ibfk_1`
    FOREIGN KEY (`fournisseur_id`) REFERENCES `compta_fournisseur` (`id`),
  CONSTRAINT `compta_document_ibfk_2`
    FOREIGN KEY (`client_id`) REFERENCES `compta_client` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Pièce justificative — fondement de toute écriture comptable';


-- ── LIGNES DE DOCUMENT ────────────────────────────────────────────
-- activite_id et poste_id OPTIONNELS — affectation différée possible.
-- Une ligne peut avoir une activite_id différente des autres lignes du même document.
-- UX : activité obligatoire avant poste (poste désactivé tant qu'aucune activité choisie).
-- tva_recuperable : NULL=hérite activite+poste | false=override non-récup

CREATE TABLE `compta_document_ligne` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id`      int(10) UNSIGNED NOT NULL,
  `libelle`          varchar(300)  NOT NULL,
  `qte`              decimal(10,3) NOT NULL DEFAULT 1.000,
  `prix_unitaire_ht` decimal(10,4) NOT NULL,
  `taux_tva`         decimal(5,4)  NOT NULL DEFAULT 0.0000 COMMENT '0 | 0.055 | 0.10 | 0.20',
  `tva_recuperable`  tinyint(1) DEFAULT NULL
                     COMMENT 'NULL=hérite activite+poste | false=override non-récup',
  `is_immobilisation` tinyint(1) NOT NULL DEFAULT 0
                     COMMENT 'Bien durable — futur tableau amortissement',
  `poste_id`         int(10) UNSIGNED DEFAULT NULL COMMENT 'Nullable — affectable plus tard',
  `activite_id`      int(10) UNSIGNED DEFAULT NULL COMMENT 'Nullable — peut différer par ligne',
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_poste` (`poste_id`),
  KEY `idx_activite` (`activite_id`),
  CONSTRAINT `compta_document_ligne_ibfk_1`
    FOREIGN KEY (`document_id`) REFERENCES `compta_document` (`id`),
  CONSTRAINT `compta_document_ligne_ibfk_2`
    FOREIGN KEY (`poste_id`) REFERENCES `compta_poste` (`id`),
  CONSTRAINT `compta_document_ligne_ibfk_3`
    FOREIGN KEY (`activite_id`) REFERENCES `ferme_activite` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Détail du document — affectation analytique optionnelle à la saisie';


-- ── ÉCRITURES ─────────────────────────────────────────────────────
-- En-tête de l'écriture comptable en partie double.
-- numero_ecriture : immuable après validation.
-- periode_exercice : dérivé de date_ecriture, JAMAIS de date_saisie.

CREATE TABLE `compta_ecriture` (
  `id`                    int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id`           int(10) UNSIGNED NOT NULL,
  `date_ecriture`         date     NOT NULL COMMENT 'Date comptable = date_document en général',
  `date_comptabilisation` datetime NOT NULL DEFAULT current_timestamp(),
  `numero_ecriture`       varchar(20) NOT NULL
                          COMMENT 'Séquentiel par exercice — préfixe ECR (ou FAC pour factures fournisseurs)',
  `libelle`               varchar(300) DEFAULT NULL,
  `periode_exercice`      char(4) NOT NULL COMMENT 'Année comptable dérivée de date_ecriture — ex: 2026',
  `created_at`            datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero_exercice` (`numero_ecriture`,`periode_exercice`),
  KEY `idx_document` (`document_id`),
  KEY `idx_periode` (`periode_exercice`),
  KEY `idx_date` (`date_ecriture`),
  CONSTRAINT `compta_ecriture_ibfk_1`
    FOREIGN KEY (`document_id`) REFERENCES `compta_document` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='En-tête écriture partie double — numero_ecriture immuable après validation';


-- ── LIGNES D'ÉCRITURE ────────────────────────────────────────────
-- RÈGLE : SUM(debit) = SUM(credit) vérifiée applicativement avant tout INSERT.
-- L'activité analytique est dans compta_document_ligne.activite_id, pas ici.

CREATE TABLE `compta_ecriture_ligne` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ecriture_id` int(10) UNSIGNED NOT NULL,
  `poste_id`    int(10) UNSIGNED NOT NULL
                COMMENT 'Toujours un poste — nature détermine charge/recette/tresorerie/tiers',
  `sens`        enum('debit','credit') NOT NULL,
  `montant`     decimal(10,2) NOT NULL,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ecriture` (`ecriture_id`),
  KEY `idx_poste` (`poste_id`),
  KEY `idx_sens` (`sens`),
  CONSTRAINT `compta_ecriture_ligne_ibfk_1`
    FOREIGN KEY (`ecriture_id`) REFERENCES `compta_ecriture` (`id`),
  CONSTRAINT `compta_ecriture_ligne_ibfk_2`
    FOREIGN KEY (`poste_id`) REFERENCES `compta_poste` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Partie double — SUM(debit)=SUM(credit) vérifié applicativement avant validation';


-- ── SÉQUENCES DE NUMÉROTATION ────────────────────────────────────
-- ✅ PK sur (prefix, exercice) — corrigé le 6 avril 2026
-- Préfixes actifs : ECR (écritures), FAC (factures fournisseurs)
-- FAV réservé aux futures factures de vente

CREATE TABLE `compta_sequence` (
  `prefix`      varchar(10) NOT NULL COMMENT 'ex: ECR, FAC, FAV',
  `exercice`    char(4)     NOT NULL,
  `dernier_num` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`prefix`, `exercice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── CLÉS DE RÉPARTITION ──────────────────────────────────────────
-- Ventilation automatique des charges transverses (EDF, eau, assurance...).
-- La somme des pourcentages par type_cout doit être = 100.
-- ✅ FK vers ferme_activite — corrigé le 6 avril 2026

CREATE TABLE `compta_cle_repartition` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `activite_id`   int(10) UNSIGNED NOT NULL,
  `type_cout`     varchar(100) NOT NULL COMMENT 'ex: electricite, eau, assurance_globale',
  `pourcentage`   decimal(5,2) NOT NULL COMMENT 'Somme par type_cout = 100 — vérifié applicativement',
  `valide_depuis` date NOT NULL COMMENT 'S applique aux nouvelles écritures uniquement',
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `activite_id` (`activite_id`),
  KEY `idx_type_cout` (`type_cout`),
  KEY `idx_valide_depuis` (`valide_depuis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── DÉCLARATIONS TVA ──────────────────────────────────────────────
-- tva_a_payer : colonne générée (tva_collectee - tva_deductible).
-- ✅ FK vers ferme_activite — corrigé le 6 avril 2026

CREATE TABLE `compta_tva_declaration` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `activite_id`    int(10) UNSIGNED NOT NULL,
  `periodicite`    enum('mensuel','trimestriel','annuel') NOT NULL DEFAULT 'trimestriel',
  `periode_debut`  date NOT NULL,
  `periode_fin`    date NOT NULL,
  `tva_collectee`  decimal(10,2) NOT NULL DEFAULT 0.00,
  `tva_deductible` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tva_a_payer`    decimal(10,2) GENERATED ALWAYS AS (`tva_collectee` - `tva_deductible`) STORED,
  `statut`         enum('en_cours','cloturee','teledeclaree') NOT NULL DEFAULT 'en_cours',
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activite` (`activite_id`),
  KEY `idx_periode` (`periode_debut`,`periode_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── MÉMOIRE OCR FOURNISSEUR ───────────────────────────────────────
-- Matching libellé OCR brut → fournisseur_id connu.
-- nb_occurrences : scoring confiance — incrémenté à chaque validation.

CREATE TABLE `compta_ocr_memoire_fournisseur` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `libelle_detecte` varchar(300) NOT NULL COMMENT 'Texte brut OCR ("AUCHAN", "AUCHAN SUPERMARCHE"...)',
  `fournisseur_id`  int(10) UNSIGNED NOT NULL,
  `nb_occurrences`  int(10) UNSIGNED NOT NULL DEFAULT 1
                    COMMENT 'Scoring confiance — incrémenté à chaque validation',
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_libelle` (`libelle_detecte`(100)),
  KEY `idx_fournisseur` (`fournisseur_id`),
  CONSTRAINT `compta_ocr_memoire_fournisseur_ibfk_1`
    FOREIGN KEY (`fournisseur_id`) REFERENCES `compta_fournisseur` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mémoire OCR fournisseurs — N libellés → même fournisseur, scoring par nb_occurrences';


-- ── MÉMOIRE OCR POSTE ────────────────────────────────────────────
-- Mémorise activité + poste par produit pour pré-remplissage automatique.
-- produit_id (prix_article) est la clé principale — stable entre tickets.
-- libelle_brut = fallback si produit non encore identifié.

CREATE TABLE `compta_ocr_memoire_poste` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `produit_id`     int(10) UNSIGNED DEFAULT NULL
                   COMMENT 'Clé principale — FK vers prix_article (cross-service)',
  `libelle_brut`   varchar(300) DEFAULT NULL COMMENT 'Fallback si produit non encore identifié',
  `poste_id`       int(10) UNSIGNED NOT NULL,
  `activite_id`    int(10) UNSIGNED DEFAULT NULL
                   COMMENT 'Activité associée — FK vers ferme_activite',
  `sous_poste_id`  int(10) UNSIGNED DEFAULT NULL COMMENT 'Enfant de poste_id',
  `nb_occurrences` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_produit` (`produit_id`),
  KEY `idx_libelle` (`libelle_brut`(100)),
  KEY `idx_poste` (`poste_id`),
  KEY `idx_activite` (`activite_id`),
  KEY `sous_poste_id` (`sous_poste_id`),
  CONSTRAINT `compta_ocr_memoire_poste_ibfk_1`
    FOREIGN KEY (`produit_id`) REFERENCES `prix_article` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compta_ocr_memoire_poste_ibfk_2`
    FOREIGN KEY (`poste_id`) REFERENCES `compta_poste` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compta_ocr_memoire_poste_ibfk_3`
    FOREIGN KEY (`sous_poste_id`) REFERENCES `compta_poste` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compta_ocr_memoire_poste_ibfk_4`
    FOREIGN KEY (`activite_id`) REFERENCES `ferme_activite` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mémoire OCR postes — produit_id prioritaire, libelle_brut en fallback';


-- ── CONFIG COMPTA ─────────────────────────────────────────────────
-- Clés actives : OCR_SERVICE, OCR_MAX_LIGNES, OCR_TIMEOUT

CREATE TABLE `compta_config` (
  `cle`        varchar(50)  NOT NULL,
  `valeur`     varchar(500) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- MODULE /prix — Service relevés de prix
-- ============================================================

-- ── ARTICLES (catalogue achats fournisseurs) ──────────────────────
-- ATTENTION : prix_article ≠ stock_article
-- prix_article = ce que la ferme ACHÈTE (sucre, farine, alimentation animaux...)
-- stock_article = ce que la ferme PRODUIT (œufs, miel...) — table future dans /stock

CREATE TABLE `prix_article` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `libelle_canonique` varchar(200) NOT NULL COMMENT 'Libellé de référence unique (ex: Sucre cristal)',
  `categorie`         varchar(100) DEFAULT NULL COMMENT 'ex: Épicerie, Produits laitiers, Élevage...',
  `unite_reference`   enum('kg','L','piece','m','kWh') NOT NULL DEFAULT 'kg'
                      COMMENT 'Unité commune pour comparer tous les conditionnements',
  `actif`             tinyint(1) NOT NULL DEFAULT 1,
  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_libelle` (`libelle_canonique`),
  KEY `idx_actif` (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── CONDITIONNEMENTS ──────────────────────────────────────────────
-- Un article peut avoir N conditionnements chez N fournisseurs.
-- fournisseur_id → compta_fournisseur (cross-service, même base)

CREATE TABLE `prix_conditionnement` (
  `id`                    int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `produit_id`            int(10) UNSIGNED NOT NULL,
  `fournisseur_id`        int(10) UNSIGNED NOT NULL
                          COMMENT 'FK vers compta_fournisseur (propriété /compta)',
  `quantite`              decimal(10,3) NOT NULL COMMENT 'Qté dans l unité d achat (ex: 25 pour sac 25kg)',
  `unite_achat`           varchar(50)   NOT NULL COMMENT 'Unité d achat (ex: sac, paquet, bouteille)',
  `reference_fournisseur` varchar(150)  DEFAULT NULL,
  `actif`                 tinyint(1)    NOT NULL DEFAULT 1,
  `created_at`            datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_produit` (`produit_id`),
  KEY `idx_fournisseur` (`fournisseur_id`),
  CONSTRAINT `prix_conditionnement_ibfk_1`
    FOREIGN KEY (`produit_id`) REFERENCES `prix_article` (`id`),
  CONSTRAINT `fk_conditionnement_fournisseur`
    FOREIGN KEY (`fournisseur_id`) REFERENCES `compta_fournisseur` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── RELEVÉS DE PRIX ───────────────────────────────────────────────
-- Service de veille tarifaire — indépendant de la comptabilité.
-- statut='en_attente' : exclu des calculs prix moyen.
-- source_service + source_document_id : référence applicative (pas FK)
--   vers le compta_document source (quand issu d'une facture ou devis).
-- ✅ date_releve en DATETIME — corrigé le 6 avril 2026

CREATE TABLE `prix_releve` (
  `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conditionnement_id` int(10) UNSIGNED NOT NULL,
  `saisisseur_id`      int(10) UNSIGNED NOT NULL COMMENT 'user.id de monpanier',
  `date_releve`        datetime NOT NULL COMMENT 'DATETIME — heure du ticket conservée',
  `prix_ht`            decimal(10,4) NOT NULL,
  `taux_tva`           decimal(5,4)  NOT NULL DEFAULT 0.0550 COMMENT '0 | 0.055 | 0.10 | 0.20',
  `source`             enum('etiquette','ticket_ocr','manuel','import_compta','devis')
                       NOT NULL DEFAULT 'manuel',
  `statut`             enum('en_attente','valide','rejete') NOT NULL DEFAULT 'en_attente'
                       COMMENT 'en_attente = issu OCR, non encore validé — exclu des calculs prix moyen',
  `libelle_brut`       varchar(300) DEFAULT NULL COMMENT 'Texte brut OCR avant rattachement',
  `scan_path`          varchar(500) DEFAULT NULL COMMENT 'Chemin relatif vers la photo scannée',
  `source_service`     varchar(20)  DEFAULT NULL
                       COMMENT 'Service source du relevé — ex: compta (référence applicative, pas FK)',
  `source_document_id` int(10) UNSIGNED DEFAULT NULL
                       COMMENT 'ID du compta_document source (pas de FK — documents immuables)',
  `created_at`         datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `conditionnement_id` (`conditionnement_id`),
  KEY `idx_date` (`date_releve`),
  KEY `idx_statut` (`statut`),
  KEY `idx_source` (`source`),
  CONSTRAINT `prix_releve_ibfk_1`
    FOREIGN KEY (`conditionnement_id`) REFERENCES `prix_conditionnement` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── MÉMOIRE OCR PRODUIT ───────────────────────────────────────────
-- Matching libellé OCR → article + conditionnement.
-- Sans fournisseur_id : le sucre est le sucre quel que soit le fournisseur.

CREATE TABLE `prix_ocr_memoire_produit` (
  `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `libelle_brut`       varchar(300) NOT NULL COMMENT 'Texte brut OCR ("SUCRE CRISTAL 1KG"...)',
  `produit_id`         int(10) UNSIGNED NOT NULL,
  `conditionnement_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Nullable — précisé si connu',
  `nb_occurrences`     int(10) UNSIGNED NOT NULL DEFAULT 1,
  `created_at`         datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`         datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_libelle` (`libelle_brut`(100)),
  KEY `idx_produit` (`produit_id`),
  KEY `conditionnement_id` (`conditionnement_id`),
  CONSTRAINT `prix_ocr_memoire_produit_ibfk_1`
    FOREIGN KEY (`produit_id`) REFERENCES `prix_article` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prix_ocr_memoire_produit_ibfk_2`
    FOREIGN KEY (`conditionnement_id`) REFERENCES `prix_conditionnement` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mémoire OCR produits — sans fournisseur_id (le sucre est le sucre)';


-- ── CONFIG PRIX ───────────────────────────────────────────────────
-- Clés actives : OCR_SERVICE, OCR_MAX_LIGNES, OCR_TIMEOUT, PRIX_PERIODE_MOIS

CREATE TABLE `prix_config` (
  `cle`        varchar(50)  NOT NULL,
  `valeur`     varchar(500) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- MODULE /stock — Service stock (v0)
-- ============================================================
-- v0 : tarifs de vente uniquement (hérité de /peyrounet rp_prix_vente)
-- v1 : stock_article (référentiel articles produits par la ferme)
-- v2 : stock_mouvement (entrées/sorties/cessions)
-- v3 : stock_reservation (réservations panier)

-- ── TARIFS DE VENTE ───────────────────────────────────────────────
-- Tarifs par article et canal de vente. Upsert : clôture l'actif, insère le nouveau.
-- Canal 'interne' = cession inter-activités (ex: /poulailler → /fermeauberge)
-- Le module producteur fixe son prix sur le canal 'interne'.
-- article_id → prix_article (cross-service — même base)
-- Note : article_id renommé depuis produit_id lors du big bang (ALTER TABLE + CHANGE)

CREATE TABLE `stock_tarif_vente` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` int(10) UNSIGNED NOT NULL
               COMMENT 'FK vers prix_article (cross-service) — renommé depuis produit_id',
  `canal`      enum('ferme_directe','marche','ferme_auberge','boutique_en_ligne','interne','autre')
               NOT NULL DEFAULT 'ferme_directe'
               COMMENT 'interne = cession inter-activités — le producteur fixe son prix',
  `date_debut` date NOT NULL,
  `date_fin`   date DEFAULT NULL COMMENT 'NULL = tarif actif',
  `prix_ttc`   decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_produit_canal` (`article_id`,`canal`),
  KEY `idx_dates` (`date_debut`,`date_fin`),
  CONSTRAINT `stock_tarif_vente_ibfk_1`
    FOREIGN KEY (`article_id`) REFERENCES `prix_article` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- ANOMALIES CORRIGÉES EN BASE (6 avril 2026)
-- ============================================================
-- ✅ 1. FK compta_cle_repartition et compta_tva_declaration
--       pointent maintenant vers ferme_activite (corrigé manuellement)
-- ✅ 2. PK compta_sequence sur (prefix, exercice) — corrigé
-- ✅ 3. prix_releve.date_releve en DATETIME — corrigé
