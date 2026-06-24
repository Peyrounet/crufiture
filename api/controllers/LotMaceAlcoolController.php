<?php
/**
 * LotMaceAlcoolController.php — Lots macération alcoolique
 *
 * Workflow : preparation → en_maceration → filtration → [assemblage] → maturation → stock
 *            Tout statut → abandonne
 *
 * transfo_lot       = tronc commun (numero_lot MA[YY][NNNN], statut simplifié)
 * mace_alcool_lot   = extension (statut détaillé, horodatages, durées copiées)
 * transfo_lot_ingredient = matières premières entrant dans le lot
 * transfo_lot_produit    = bouteilles produites — créées au stocker() uniquement
 * transfo_controle       = mesures qualité tout au long du workflow
 *
 * @php 7.4+ (Hostinger)
 */
use helpers\ResponseHelper;

class LotMaceAlcoolController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ── GET /mace-alcool/lots ──────────────────────────────────────
    // Liste tous les lots macération, triés par statut actif en tête
    // Filtres : ?statut= &q=
    public function getAll()
    {
        $statut = $_GET['statut'] ?? '';
        $q      = trim($_GET['q'] ?? '');

        $where  = ['1=1'];
        $types  = '';
        $params = [];

        if ($statut !== '') {
            $where[]  = 'ml.statut = ?';
            $types   .= 's';
            $params[] = $statut;
        }
        if ($q !== '') {
            $like     = '%' . $q . '%';
            $where[]  = '(tl.numero_lot LIKE ? OR tr.nom LIKE ?)';
            $types   .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "
            SELECT
                ml.id, ml.statut, ml.lot_test,
                ml.date_debut_maceration, ml.duree_maceration_cible_j,
                ml.date_filtration,
                ml.avec_assemblage,
                ml.date_debut_maturation, ml.duree_maturation_cible_j,
                ml.created_at, ml.updated_at,
                tl.id          AS transfo_lot_id,
                tl.numero_lot,
                tl.date_production,
                tl.note,
                rv.id          AS version_id,
                rv.numero      AS version_numero,
                rv.statut      AS version_statut,
                tr.id          AS recette_id,
                tr.nom         AS recette_nom,
                DATEDIFF(NOW(), ml.date_debut_maceration) >= ml.duree_maceration_cible_j
                    AND ml.statut = 'en_maceration' AS alerte_maceration,
                DATEDIFF(NOW(), ml.date_debut_maturation) >= ml.duree_maturation_cible_j
                    AND ml.statut = 'maturation' AS alerte_maturation,
                (SELECT COUNT(*) FROM transfo_controle tc WHERE tc.lot_id = tl.id) AS nb_controles,
                (SELECT COUNT(*) FROM transfo_lot_produit tp WHERE tp.lot_id = tl.id) AS nb_bouteilles
            FROM mace_alcool_lot ml
            JOIN transfo_lot tl          ON tl.id  = ml.transfo_lot_id
            JOIN transfo_recette_version rv ON rv.id = ml.recette_version_id
            JOIN transfo_recette tr      ON tr.id  = rv.recette_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                FIELD(ml.statut, 'en_maceration', 'maturation', 'filtration', 'assemblage', 'preparation', 'stock', 'abandonne'),
                tl.date_production DESC, ml.id DESC";

        $stmt = $this->mysqli->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $lots = [];
        foreach ($rows as $row) {
            $lots[] = $this->castLotRow($row);
        }

        echo ResponseHelper::jsonResponse('OK', 'success', $lots, 200);
    }

    // ── GET /mace-alcool/lots/:id — fiche complète ─────────────────
    public function getOne($id)
    {
        $id = (int)$id;

        $stmt = $this->mysqli->prepare(
            "SELECT
                ml.*, ml.id AS mace_lot_id,
                tl.id AS transfo_lot_id, tl.numero_lot, tl.date_production, tl.note,
                rv.id AS version_id, rv.numero AS version_numero, rv.statut AS version_statut,
                rv.nb_unites, rv.unite_production, rv.description AS version_description,
                tr.id AS recette_id, tr.nom AS recette_nom, tr.famille AS recette_famille,
                mav.abv_cible_pct, mav.brix_cible,
                DATEDIFF(NOW(), ml.date_debut_maceration) AS jours_maceration,
                DATEDIFF(NOW(), ml.date_debut_maturation) AS jours_maturation,
                DATEDIFF(NOW(), ml.date_debut_maceration) >= ml.duree_maceration_cible_j
                    AND ml.statut = 'en_maceration' AS alerte_maceration,
                DATEDIFF(NOW(), ml.date_debut_maturation) >= ml.duree_maturation_cible_j
                    AND ml.statut = 'maturation' AS alerte_maturation
             FROM mace_alcool_lot ml
             JOIN transfo_lot tl             ON tl.id  = ml.transfo_lot_id
             JOIN transfo_recette_version rv  ON rv.id  = ml.recette_version_id
             JOIN transfo_recette tr          ON tr.id  = rv.recette_id
             LEFT JOIN mace_alcool_recette_version mav ON mav.transfo_recette_version_id = rv.id
             WHERE ml.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        $lot = $this->castLotRow($lot);

        // Ingrédients du lot
        $stmt = $this->mysqli->prepare(
            "SELECT ti.id, ti.article_id, ti.quantite, ti.unite, ti.ordre, ti.note, ti.created_at,
                    sa.libelle AS article_libelle
             FROM transfo_lot_ingredient ti
             LEFT JOIN stock_article sa ON sa.id = ti.article_id
             WHERE ti.lot_id = ?
             ORDER BY ti.ordre ASC, ti.id ASC"
        );
        $stmt->bind_param('i', $lot['transfo_lot_id']);
        $stmt->execute();
        $ingredients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($ingredients as &$ing) {
            $ing['id']         = (int)$ing['id'];
            $ing['article_id'] = (int)$ing['article_id'];
            $ing['quantite']   = (float)$ing['quantite'];
            $ing['ordre']      = (int)$ing['ordre'];
        }

        // Contrôles qualité
        $stmt = $this->mysqli->prepare(
            "SELECT id, date_controle, type_controle, brix_mesure, aw_mesure,
                    ph_mesure, abv_mesure, aspect, remarque, created_at
             FROM transfo_controle
             WHERE lot_id = ?
             ORDER BY date_controle ASC, id ASC"
        );
        $stmt->bind_param('i', $lot['transfo_lot_id']);
        $stmt->execute();
        $controles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($controles as &$c) {
            $c['id']          = (int)$c['id'];
            $c['brix_mesure'] = $c['brix_mesure'] !== null ? (float)$c['brix_mesure'] : null;
            $c['aw_mesure']   = $c['aw_mesure']   !== null ? (float)$c['aw_mesure']   : null;
            $c['ph_mesure']   = $c['ph_mesure']   !== null ? (float)$c['ph_mesure']   : null;
            $c['abv_mesure']  = $c['abv_mesure']  !== null ? (float)$c['abv_mesure']  : null;
        }

        // Bouteilles produites (renseignées au stocker uniquement)
        $stmt = $this->mysqli->prepare(
            "SELECT tp.id, tp.stock_article_id, tp.libelle AS produit_nom,
                    tp.quantite_produite, tp.unite, tp.dlc, tp.created_at
             FROM transfo_lot_produit tp
             WHERE tp.lot_id = ?
             ORDER BY tp.id ASC"
        );
        $stmt->bind_param('i', $lot['transfo_lot_id']);
        $stmt->execute();
        $bouteilles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($bouteilles as &$b) {
            $b['id']                = (int)$b['id'];
            $b['stock_article_id']  = $b['stock_article_id'] !== null ? (int)$b['stock_article_id'] : null;
            $b['quantite_produite'] = $b['quantite_produite'] !== null ? (float)$b['quantite_produite'] : null;
        }

        $lot['ingredients'] = $ingredients;
        $lot['controles']   = $controles;
        $lot['produits']    = $bouteilles;

        echo ResponseHelper::jsonResponse('OK', 'success', $lot, 200);
    }

    // ── POST /mace-alcool/lots — créer un lot ──────────────────────
    // Body : recette_version_id, ingredients[]
    public function create($data)
    {
        $recette_version_id = (int)($data['recette_version_id'] ?? 0);
        $date_production    = isset($data['date_production']) && $data['date_production'] !== '' ? $data['date_production'] : date('Y-m-d');
        $note               = isset($data['note']) && $data['note'] !== '' ? trim($data['note']) : null;

        if ($recette_version_id <= 0) {
            echo ResponseHelper::jsonResponse('recette_version_id requis.', 'error', null, 400);
            return;
        }

        // Vérifier que la version est validée
        $stmt = $this->mysqli->prepare(
            "SELECT rv.id, rv.statut, rv.recette_id,
                    mav.duree_maceration_cible_j, mav.duree_maturation_cible_j, mav.avec_assemblage
             FROM transfo_recette_version rv
             LEFT JOIN mace_alcool_recette_version mav ON mav.transfo_recette_version_id = rv.id
             WHERE rv.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $recette_version_id);
        $stmt->execute();
        $rv = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rv) {
            echo ResponseHelper::jsonResponse('Version de recette introuvable.', 'error', null, 404);
            return;
        }
        if (!in_array($rv['statut'], ['validee', 'en_test'])) {
            echo ResponseHelper::jsonResponse('Seule une version "validée" ou "en test" peut démarrer un lot.', 'error', null, 422);
            return;
        }
        // Lot de test si la version est en_test (immuable une fois créé)
        $lot_test = (int)($rv['statut'] === 'en_test');

        // Copier les paramètres depuis la recette (immuables sur le lot)
        $duree_mac       = $rv['duree_maceration_cible_j'] !== null ? (int)$rv['duree_maceration_cible_j'] : null;
        $duree_mat       = $rv['duree_maturation_cible_j'] !== null ? (int)$rv['duree_maturation_cible_j'] : null;
        $avec_assemblage = (int)($rv['avec_assemblage'] ?? 0);

        $this->mysqli->begin_transaction();

        try {
            // Gamme macération alcoolique
            $stmtG = $this->mysqli->prepare("SELECT id FROM transfo_gamme WHERE slug = 'maceration_alcool' LIMIT 1");
            $stmtG->execute();
            $gamme = $stmtG->get_result()->fetch_assoc();
            $stmtG->close();

            if (!$gamme) throw new Exception('Gamme maceration_alcool introuvable.');
            $gamme_id = (int)$gamme['id'];

            // Numéro de lot MA[YY][NNNN]
            $numero_lot = $this->genererNumeroLot();

            // Créer transfo_lot (statut tronc commun = 'preparation')
            $stmtL = $this->mysqli->prepare(
                "INSERT INTO transfo_lot (gamme_id, numero_lot, date_production, statut, note)
                 VALUES (?, ?, ?, 'preparation', ?)"
            );
            $stmtL->bind_param('isss', $gamme_id, $numero_lot, $date_production, $note);
            if (!$stmtL->execute()) throw new Exception('Erreur SQL transfo_lot: ' . $this->mysqli->error);
            $transfo_lot_id = (int)$this->mysqli->insert_id;
            $stmtL->close();

            // Créer mace_alcool_lot
            $stmtM = $this->mysqli->prepare(
                "INSERT INTO mace_alcool_lot
                     (transfo_lot_id, recette_version_id, statut, avec_assemblage,
                      duree_maceration_cible_j, duree_maturation_cible_j, lot_test)
                 VALUES (?, ?, 'preparation', ?, ?, ?, ?)"
            );
            $stmtM->bind_param('iiiiii',
                $transfo_lot_id, $recette_version_id, $avec_assemblage, $duree_mac, $duree_mat, $lot_test
            );
            if (!$stmtM->execute()) throw new Exception('Erreur SQL mace_alcool_lot: ' . $this->mysqli->error);
            $mace_lot_id = (int)$this->mysqli->insert_id;
            $stmtM->close();

            // Ingrédients — auto-populate depuis transfo_recette_ingredient
            $stmtRI = $this->mysqli->prepare(
                "SELECT ri.stock_article_id, ri.quantite, ri.unite
                 FROM transfo_recette_ingredient ri
                 WHERE ri.version_id = ?
                 ORDER BY ri.id ASC"
            );
            $stmtRI->bind_param('i', $recette_version_id);
            $stmtRI->execute();
            $recette_ingrs = $stmtRI->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtRI->close();

            if (!empty($recette_ingrs)) {
                $stmtI = $this->mysqli->prepare(
                    "INSERT INTO transfo_lot_ingredient (lot_id, article_id, quantite, unite, ordre)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $ordre = 0;
                foreach ($recette_ingrs as $ri) {
                    if (!$ri['stock_article_id']) continue;
                    $article_id = (int)$ri['stock_article_id'];
                    $quantite   = (float)$ri['quantite'];
                    $unite      = trim($ri['unite'] ?? 'kg');
                    $stmtI->bind_param('iidsi', $transfo_lot_id, $article_id, $quantite, $unite, $ordre);
                    if (!$stmtI->execute()) throw new Exception('Erreur SQL ingrédient: ' . $this->mysqli->error);
                    $ordre++;
                }
                $stmtI->close();
            }

            $this->mysqli->commit();
            echo ResponseHelper::jsonResponse('Lot créé.', 'success', [
                'id'              => $mace_lot_id,
                'transfo_lot_id'  => $transfo_lot_id,
                'numero_lot'      => $numero_lot,
                'statut'          => 'preparation',
            ], 201);

        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── PUT /mace-alcool/lots/:id/ingredients ─────────────────────
    // Remplace tous les ingrédients du lot (statut preparation uniquement)
    public function updateIngredients($id, $data)
    {
        $id          = (int)$id;
        $ingredients = $data['ingredients'] ?? [];

        $lot = $this->chargerLot($id);
        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }
        if ($lot['statut'] !== 'preparation') {
            echo ResponseHelper::jsonResponse('Les ingrédients ne sont modifiables qu\'en statut "préparation".', 'error', null, 422);
            return;
        }

        $transfo_lot_id = (int)$lot['transfo_lot_id'];

        $this->mysqli->begin_transaction();
        try {
            $stmtD = $this->mysqli->prepare("DELETE FROM transfo_lot_ingredient WHERE lot_id = ?");
            $stmtD->bind_param('i', $transfo_lot_id);
            if (!$stmtD->execute()) throw new Exception('Erreur SQL delete: ' . $this->mysqli->error);
            $stmtD->close();

            if (!empty($ingredients)) {
                $stmtI = $this->mysqli->prepare(
                    "INSERT INTO transfo_lot_ingredient (lot_id, article_id, quantite, unite, ordre, note)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($ingredients as $idx => $ing) {
                    $article_id = (int)($ing['article_id'] ?? 0);
                    $quantite   = (float)($ing['quantite'] ?? 0);
                    $unite      = trim($ing['unite'] ?? 'kg');
                    if ($article_id <= 0 || $quantite <= 0) continue;
                    $ordre    = (int)$idx;
                    $ing_note = isset($ing['note']) && $ing['note'] !== '' ? trim($ing['note']) : null;
                    $stmtI->bind_param('iidsis', $transfo_lot_id, $article_id, $quantite, $unite, $ordre, $ing_note);
                    if (!$stmtI->execute()) throw new Exception('Erreur SQL ingrédient: ' . $this->mysqli->error);
                }
                $stmtI->close();
            }

            $this->mysqli->commit();
            echo ResponseHelper::jsonResponse('Ingrédients mis à jour.', 'success', null, 200);
        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── POST /mace-alcool/lots/:id/controles ───────────────────────
    public function addControle($id, $data)
    {
        $id = (int)$id;

        $lot = $this->chargerLot($id);
        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }
        if ($lot['statut'] === 'abandonne') {
            echo ResponseHelper::jsonResponse('Lot abandonné — aucun contrôle ne peut être ajouté.', 'error', null, 422);
            return;
        }

        $date_controle = $data['date_controle'] ?? date('Y-m-d');
        $type_controle = $data['type_controle'] ?? 'suivi';
        $brix_mesure   = isset($data['brix_mesure'])  && $data['brix_mesure']  !== '' ? (float)$data['brix_mesure']  : null;
        $aw_mesure     = isset($data['aw_mesure'])     && $data['aw_mesure']    !== '' ? (float)$data['aw_mesure']    : null;
        $ph_mesure     = isset($data['ph_mesure'])     && $data['ph_mesure']    !== '' ? (float)$data['ph_mesure']    : null;
        $abv_mesure    = isset($data['abv_mesure'])    && $data['abv_mesure']   !== '' ? (float)$data['abv_mesure']   : null;
        $aspect        = isset($data['aspect'])        && $data['aspect'] !== ''       ? trim($data['aspect'])        : null;
        $remarque      = isset($data['remarque'])      && $data['remarque'] !== ''     ? trim($data['remarque'])      : null;

        $transfo_lot_id = (int)$lot['transfo_lot_id'];

        $stmt = $this->mysqli->prepare(
            "INSERT INTO transfo_controle
                 (lot_id, date_controle, type_controle, brix_mesure, aw_mesure,
                  ph_mesure, abv_mesure, aspect, remarque)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issddddss',
            $transfo_lot_id, $date_controle, $type_controle,
            $brix_mesure, $aw_mesure, $ph_mesure, $abv_mesure, $aspect, $remarque
        );

        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur SQL: ' . $this->mysqli->error, 'error', null, 500);
            $stmt->close();
            return;
        }
        $ctrl_id = (int)$this->mysqli->insert_id;
        $stmt->close();

        echo ResponseHelper::jsonResponse('Contrôle ajouté.', 'success', ['id' => $ctrl_id], 201);
    }

    // ── PUT /mace-alcool/lots/:id/demarrer-maceration ──────────────
    // preparation → en_maceration
    public function demarrerMaceration($id, $data = [])
    {
        $id  = (int)$id;
        $lot = $this->chargerLot($id);
        if (!$lot) { echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404); return; }
        if ($lot['statut'] !== 'preparation') {
            echo ResponseHelper::jsonResponse('Le lot doit être en préparation.', 'error', null, 422);
            return;
        }

        $now            = isset($data['date']) && $data['date'] !== '' ? $data['date'] : date('Y-m-d H:i:s');
        $transfo_lot_id = (int)$lot['transfo_lot_id'];

        $this->mysqli->begin_transaction();
        try {
            $stmt = $this->mysqli->prepare(
                "UPDATE mace_alcool_lot SET statut = 'en_maceration', date_debut_maceration = ? WHERE id = ?"
            );
            $stmt->bind_param('si', $now, $id);
            if (!$stmt->execute()) throw new Exception($this->mysqli->error);
            $stmt->close();

            $this->syncStatutTransfoLot($transfo_lot_id, 'production');
            $this->mysqli->commit();
            echo ResponseHelper::jsonResponse('Macération démarrée.', 'success', ['statut' => 'en_maceration'], 200);
        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── PUT /mace-alcool/lots/:id/filtrer ──────────────────────────
    // en_maceration → filtration
    public function filtrer($id, $data = [])
    {
        $id  = (int)$id;
        $lot = $this->chargerLot($id);
        if (!$lot) { echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404); return; }
        if ($lot['statut'] !== 'en_maceration') {
            echo ResponseHelper::jsonResponse('Le lot doit être en macération.', 'error', null, 422);
            return;
        }

        $now = isset($data['date']) && $data['date'] !== '' ? $data['date'] : date('Y-m-d H:i:s');

        $stmt = $this->mysqli->prepare(
            "UPDATE mace_alcool_lot SET statut = 'filtration', date_filtration = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $now, $id);
        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur SQL: ' . $this->mysqli->error, 'error', null, 500);
            $stmt->close();
            return;
        }
        $stmt->close();

        echo ResponseHelper::jsonResponse('Filtration enregistrée.', 'success', ['statut' => 'filtration'], 200);
    }

    // ── PUT /mace-alcool/lots/:id/assembler ────────────────────────
    // filtration → assemblage (uniquement si avec_assemblage = 1)
    public function assembler($id, $data = [])
    {
        $id  = (int)$id;
        $lot = $this->chargerLot($id);
        if (!$lot) { echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404); return; }
        if ($lot['statut'] !== 'filtration') {
            echo ResponseHelper::jsonResponse('Le lot doit être en filtration.', 'error', null, 422);
            return;
        }
        if (!(int)$lot['avec_assemblage']) {
            echo ResponseHelper::jsonResponse('Cette recette n\'a pas d\'étape d\'assemblage.', 'error', null, 422);
            return;
        }

        $now = isset($data['date']) && $data['date'] !== '' ? $data['date'] : date('Y-m-d H:i:s');

        $stmt = $this->mysqli->prepare(
            "UPDATE mace_alcool_lot SET statut = 'assemblage', date_assemblage = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $now, $id);
        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur SQL: ' . $this->mysqli->error, 'error', null, 500);
            $stmt->close();
            return;
        }
        $stmt->close();

        echo ResponseHelper::jsonResponse('Assemblage démarré.', 'success', ['statut' => 'assemblage'], 200);
    }

    // ── PUT /mace-alcool/lots/:id/demarrer-maturation ──────────────
    // filtration|assemblage → maturation
    public function demarrerMaturation($id, $data = [])
    {
        $id  = (int)$id;
        $lot = $this->chargerLot($id);
        if (!$lot) { echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404); return; }
        if (!in_array($lot['statut'], ['filtration', 'assemblage'])) {
            echo ResponseHelper::jsonResponse('Le lot doit être en filtration ou assemblage.', 'error', null, 422);
            return;
        }

        $now = isset($data['date']) && $data['date'] !== '' ? $data['date'] : date('Y-m-d H:i:s');

        $stmt = $this->mysqli->prepare(
            "UPDATE mace_alcool_lot SET statut = 'maturation', date_debut_maturation = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $now, $id);
        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur SQL: ' . $this->mysqli->error, 'error', null, 500);
            $stmt->close();
            return;
        }
        $stmt->close();

        echo ResponseHelper::jsonResponse('Maturation démarrée.', 'success', ['statut' => 'maturation'], 200);
    }

    // ── PUT /mace-alcool/lots/:id/stocker ─────────────────────────
    // maturation → stock
    // Body : bouteilles[] = [{stock_article_id?, libelle, quantite, unite?, dlc?}]
    // Si stock_article_id absent → création à la volée dans stock_article
    public function stocker($id, $data)
    {
        $id  = (int)$id;
        $lot = $this->chargerLot($id);
        if (!$lot) { echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404); return; }
        if ($lot['statut'] !== 'maturation') {
            echo ResponseHelper::jsonResponse('Le lot doit être en maturation pour être stocké.', 'error', null, 422);
            return;
        }

        $bouteilles        = $data['bouteilles'] ?? [];
        $declarer_en_stock = !isset($data['declarer_en_stock']) || (bool)$data['declarer_en_stock'];

        if (empty($bouteilles)) {
            echo ResponseHelper::jsonResponse('Au moins une bouteille doit être renseignée.', 'error', null, 422);
            return;
        }

        $transfo_lot_id = (int)$lot['transfo_lot_id'];

        $this->mysqli->begin_transaction();

        try {
            $stmtB = $this->mysqli->prepare(
                "INSERT INTO transfo_lot_produit (lot_id, stock_article_id, libelle, quantite_produite, unite, dlc)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            $nb_bouteilles = 0;
            $stock_inseres = [];

            foreach ($bouteilles as $b) {
                $quantite = (float)($b['quantite'] ?? 0);
                $libelle  = isset($b['libelle']) && $b['libelle'] !== '' ? trim($b['libelle']) : null;
                $unite    = trim($b['unite'] ?? 'piece');
                $dlc      = isset($b['dlc']) && $b['dlc'] !== '' ? $b['dlc'] : null;

                if ($quantite <= 0 || !$libelle) continue;
                if (!$dlc || strtotime($dlc) <= strtotime('today')) {
                    $this->mysqli->rollback();
                    echo ResponseHelper::jsonResponse('La DLC est obligatoire et doit être supérieure à aujourd\'hui.', 'error', null, 422);
                    return;
                }

                // Résoudre stock_article_id — sélectionné ou création à la volée
                $stock_article_id = isset($b['stock_article_id']) && (int)$b['stock_article_id'] > 0
                    ? (int)$b['stock_article_id']
                    : $this->getOrCreateStockArticle($libelle, $unite);

                $stmtB->bind_param('iisdss', $transfo_lot_id, $stock_article_id, $libelle, $quantite, $unite, $dlc);
                if (!$stmtB->execute()) throw new Exception('Erreur SQL bouteille: ' . $this->mysqli->error);

                $nb_bouteilles++;
                $stock_inseres[] = ['stock_article_id' => $stock_article_id, 'quantite' => $quantite, 'unite' => $unite];
            }
            $stmtB->close();

            if ($nb_bouteilles === 0) {
                $this->mysqli->rollback();
                echo ResponseHelper::jsonResponse('Aucune bouteille valide fournie.', 'error', null, 422);
                return;
            }

            $now_stock = date('Y-m-d H:i:s');
            $stmt = $this->mysqli->prepare("UPDATE mace_alcool_lot SET statut = 'stock', date_mise_en_stock = ? WHERE id = ?");
            $stmt->bind_param('si', $now_stock, $id);
            if (!$stmt->execute()) throw new Exception($this->mysqli->error);
            $stmt->close();

            $this->syncStatutTransfoLot($transfo_lot_id, 'stock');

            $this->mysqli->commit();

            // Push /stock conditionnel — optionnel pour les lots de test
            if ($declarer_en_stock) {
                foreach ($stock_inseres as $s) {
                    $this->declarerEntreeProduitFini($transfo_lot_id, $s['stock_article_id'], $s['quantite'], $s['unite']);
                }
            }

            // Push /registres toujours — traçabilité bio obligatoire même pour un lot test
            $this->pushRegistres($transfo_lot_id, $lot['numero_lot'] ?? '', (int)($lot['lot_test'] ?? 0));

            echo ResponseHelper::jsonResponse('Lot passé en stock.', 'success', [
                'id'            => $id,
                'statut'        => 'stock',
                'nb_bouteilles' => $nb_bouteilles,
            ], 200);

        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── PUT /mace-alcool/lots/:id/abandonner ──────────────────────
    // Tout statut sauf stock → abandonne
    public function abandonner($id, $data)
    {
        $id  = (int)$id;
        $lot = $this->chargerLot($id);
        if (!$lot) { echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404); return; }
        if (in_array($lot['statut'], ['stock', 'abandonne'])) {
            echo ResponseHelper::jsonResponse('Un lot en stock ou déjà abandonné ne peut pas être abandonné.', 'error', null, 422);
            return;
        }

        $note           = isset($data['note']) && $data['note'] !== '' ? trim($data['note']) : null;
        $transfo_lot_id = (int)$lot['transfo_lot_id'];

        $this->mysqli->begin_transaction();
        try {
            $stmt = $this->mysqli->prepare(
                "UPDATE mace_alcool_lot SET statut = 'abandonne' WHERE id = ?"
            );
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception($this->mysqli->error);
            $stmt->close();

            if ($note !== null) {
                $stmtN = $this->mysqli->prepare("UPDATE transfo_lot SET note = ? WHERE id = ?");
                $stmtN->bind_param('si', $note, $transfo_lot_id);
                $stmtN->execute();
                $stmtN->close();
            }

            $this->syncStatutTransfoLot($transfo_lot_id, 'abandonné');
            $this->mysqli->commit();
            echo ResponseHelper::jsonResponse('Lot abandonné.', 'success', ['id' => $id, 'statut' => 'abandonne'], 200);
        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── Méthodes privées ──────────────────────────────────────────

    // Génère un numéro de lot format MA[YY][NNNN] — séquentiel annuel gamme macération
    private function genererNumeroLot(): string
    {
        $annee2  = date('y');
        $prefixe = 'MA' . $annee2 . '%';

        $stmt = $this->mysqli->prepare(
            "SELECT MAX(numero_lot) AS max_num FROM transfo_lot WHERE numero_lot LIKE ?"
        );
        $stmt->bind_param('s', $prefixe);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row['max_num']) {
            $seq = (int)substr($row['max_num'], 4) + 1;
        } else {
            $seq = 1;
        }

        return 'MA' . $annee2 . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    // Charge les champs essentiels du lot (mace_alcool_lot + transfo_lot)
    private function chargerLot(int $id): ?array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT ml.id, ml.statut, ml.avec_assemblage, ml.lot_test,
                    ml.duree_maceration_cible_j, ml.duree_maturation_cible_j,
                    ml.transfo_lot_id,
                    tl.numero_lot
             FROM mace_alcool_lot ml
             JOIN transfo_lot tl ON tl.id = ml.transfo_lot_id
             WHERE ml.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    // Synchronise transfo_lot.statut depuis le statut détaillé mace_alcool
    private function syncStatutTransfoLot(int $transfo_lot_id, string $statut): void
    {
        $stmt = $this->mysqli->prepare(
            "UPDATE transfo_lot SET statut = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $statut, $transfo_lot_id);
        $stmt->execute();
        $stmt->close();
    }

    // Trouve ou crée un stock_article par libellé (type produit_fini)
    private function getOrCreateStockArticle(string $libelle, string $unite): int
    {
        if ($libelle === '') throw new \Exception('Libellé obligatoire pour créer un article stock.');
        if ($unite === '')   throw new \Exception('Unité obligatoire pour créer un article stock — ne pas laisser le champ vide.');

        $stmt = $this->mysqli->prepare(
            "SELECT id FROM stock_article WHERE libelle = ? AND type = 'produit_fini' LIMIT 1"
        );
        $stmt->bind_param('s', $libelle);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) return (int)$row['id'];

        $stmt = $this->mysqli->prepare(
            "INSERT INTO stock_article (libelle, type, unite, actif, suivi_stock) VALUES (?, 'produit_fini', ?, 1, 1)"
        );
        $stmt->bind_param('ss', $libelle, $unite);
        if (!$stmt->execute()) throw new \Exception('Erreur création stock_article: ' . $this->mysqli->error);
        $id = (int)$this->mysqli->insert_id;
        $stmt->close();
        return $id;
    }

    // Push /stock — entrée produit fini (non bloquant)
    private function declarerEntreeProduitFini(int $lot_id, int $stock_article_id, float $quantite, string $unite): void
    {
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/api/controllers/StockMouvementController.php';
            $mouvCtrl = new StockMouvementController($this->mysqli);

            $result = $mouvCtrl->enregistrerMouvement([
                'article_id'     => $stock_article_id,
                'type'           => 'entree_production',
                'quantite'       => $quantite,
                'unite'          => $unite,
                'source_service' => 'maceration_alcool',
                'source_id'      => $lot_id,
            ]);

            if (isset($result['erreur'])) {
                error_log('[mace_alcool] declarerEntreeProduitFini lot_id=' . $lot_id . ' article_id=' . $stock_article_id . ' : ' . $result['erreur']);
            }

        } catch (\Throwable $e) {
            error_log('[mace_alcool] declarerEntreeProduitFini lot_id=' . $lot_id . ' : ' . $e->getMessage());
        }
    }

    // Push /registres — traçabilité bio (non bloquant)
    // Toujours appelé, même pour les lots de test — préfixe [TEST] dans le libellé
    private function pushRegistres(int $lot_id, string $numero_lot, int $lot_test = 0): void
    {
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/registres/api/controllers/RegistreController.php';
            $regCtrl = new RegistreController($this->mysqli);
            $libelle = ($lot_test ? '[TEST] ' : '') . 'Mise en stock lot ' . $numero_lot;
            $regCtrl->push([
                'source_service' => 'maceration_alcool',
                'source_id'      => $lot_id,
                'libelle'        => $libelle,
                'date_operation' => date('Y-m-d'),
            ]);
        } catch (\Throwable $e) {
            error_log('[mace_alcool] pushRegistres lot_id=' . $lot_id . ' : ' . $e->getMessage());
        }
    }

    // Cast complet d'une ligne lot
    private function castLotRow(array $row): array
    {
        $row['id']                         = (int)$row['id'];
        $row['transfo_lot_id']             = isset($row['transfo_lot_id'])             ? (int)$row['transfo_lot_id']             : null;
        $row['version_id']                 = isset($row['version_id'])                 ? (int)$row['version_id']                 : null;
        $row['recette_id']                 = isset($row['recette_id'])                 ? (int)$row['recette_id']                 : null;
        $row['avec_assemblage']            = (int)$row['avec_assemblage'];
        $row['lot_test']                   = (int)($row['lot_test'] ?? 0);
        $row['duree_maceration_cible_j']   = $row['duree_maceration_cible_j'] !== null ? (int)$row['duree_maceration_cible_j']   : null;
        $row['duree_maturation_cible_j']   = $row['duree_maturation_cible_j'] !== null ? (int)$row['duree_maturation_cible_j']   : null;
        $row['nb_controles']               = isset($row['nb_controles'])               ? (int)$row['nb_controles']               : null;
        $row['nb_bouteilles']              = isset($row['nb_bouteilles'])              ? (int)$row['nb_bouteilles']              : null;
        $row['alerte_maceration']          = isset($row['alerte_maceration'])          ? (bool)$row['alerte_maceration']         : false;
        $row['alerte_maturation']          = isset($row['alerte_maturation'])          ? (bool)$row['alerte_maturation']         : false;
        $row['jours_maceration']           = isset($row['jours_maceration'])           ? (int)$row['jours_maceration']           : null;
        $row['jours_maturation']           = isset($row['jours_maturation'])           ? (int)$row['jours_maturation']           : null;
        $row['abv_cible_pct']              = isset($row['abv_cible_pct'])  && $row['abv_cible_pct']  !== null ? (float)$row['abv_cible_pct']  : null;
        $row['brix_cible']                 = isset($row['brix_cible'])     && $row['brix_cible']     !== null ? (float)$row['brix_cible']     : null;
        return $row;
    }
}
