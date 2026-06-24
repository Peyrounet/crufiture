<?php
/**
 * RecetteTransfoController.php — Recettes tronc commun (transfo_recette_*)
 *
 * transfo_recette         = identité (nom, gamme, famille)
 * transfo_recette_version = contenu versionné + statut workflow
 * mace_alcool_recette_version = extension 1:1 pour la gamme macération alcoolique
 *
 * Workflow version : brouillon → en_test → validee
 * Fork : POST /recettes-transfo/dupliquer?version_id= → copie complète, numero+1, brouillon
 * Export PDF : GET /recettes-transfo/export-pdf?version_id=&format=chef|complet
 *
 * @php 7.4+ (Hostinger)
 */
use helpers\ResponseHelper;

class RecetteTransfoController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ── GET /recettes-transfo — liste groupée par recette ─────────
    // Retourne les recettes avec toutes leurs versions (dernière en tête)
    // Filtres : ?gamme_id= &statut= &q=
    public function getRecettes()
    {
        $gamme_id = isset($_GET['gamme_id']) && $_GET['gamme_id'] !== '' ? (int)$_GET['gamme_id'] : null;
        $statut   = $_GET['statut'] ?? '';
        $q        = trim($_GET['q'] ?? '');

        $where  = ['r.actif = 1'];
        $types  = '';
        $params = [];

        if ($gamme_id !== null) {
            $where[]  = 'r.gamme_id = ?';
            $types   .= 'i';
            $params[] = $gamme_id;
        }
        if ($statut !== '') {
            $where[]  = 'v.statut = ?';
            $types   .= 's';
            $params[] = $statut;
        }
        if ($q !== '') {
            $like     = '%' . $q . '%';
            $where[]  = '(r.nom LIKE ? OR v.notes_version LIKE ?)';
            $types   .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "
            SELECT
                r.id AS recette_id,
                r.gamme_id,
                r.nom,
                r.famille,
                r.actif,
                r.created_at AS recette_created_at,
                g.libelle    AS gamme_libelle,
                g.slug       AS gamme_slug,
                v.id               AS version_id,
                v.numero,
                v.statut,
                v.notes_version,
                v.nb_unites,
                v.unite_production,
                v.difficulte,
                v.created_at       AS version_created_at,
                v.updated_at,
                mav.duree_maceration_cible_j,
                mav.duree_maturation_cible_j,
                mav.abv_cible_pct,
                mav.brix_cible,
                mav.avec_assemblage,
                COUNT(DISTINCT ri.id) AS nb_ingredients,
                COUNT(DISTINCT re.id) AS nb_etapes
            FROM transfo_recette r
            JOIN transfo_gamme g ON g.id = r.gamme_id
            JOIN transfo_recette_version v ON v.recette_id = r.id
            LEFT JOIN mace_alcool_recette_version mav ON mav.transfo_recette_version_id = v.id
            LEFT JOIN transfo_recette_ingredient ri ON ri.version_id = v.id
            LEFT JOIN transfo_recette_etape re ON re.version_id = v.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY r.id, v.id
            ORDER BY r.nom ASC, v.numero DESC";

        $stmt = $this->mysqli->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $groupes = [];
        foreach ($rows as $row) {
            $rid = (int)$row['recette_id'];
            if (!isset($groupes[$rid])) {
                $groupes[$rid] = [
                    'id'            => $rid,
                    'gamme_id'      => (int)$row['gamme_id'],
                    'gamme_libelle' => $row['gamme_libelle'],
                    'gamme_slug'    => $row['gamme_slug'],
                    'nom'           => $row['nom'],
                    'famille'       => $row['famille'],
                    'actif'         => (int)$row['actif'],
                    'created_at'    => $row['recette_created_at'],
                    'versions'      => [],
                ];
            }
            $groupes[$rid]['versions'][] = [
                'id'                       => (int)$row['version_id'],
                'numero'                   => (int)$row['numero'],
                'statut'                   => $row['statut'],
                'notes_version'            => $row['notes_version'],
                'nb_unites'                => (int)$row['nb_unites'],
                'unite_production'         => $row['unite_production'],
                'difficulte'               => $row['difficulte'] !== null ? (int)$row['difficulte'] : null,
                'nb_ingredients'           => (int)$row['nb_ingredients'],
                'nb_etapes'                => (int)$row['nb_etapes'],
                'updated_at'               => $row['updated_at'],
                'mace_alcool'              => $row['avec_assemblage'] !== null ? [
                    'duree_maceration_cible_j' => $row['duree_maceration_cible_j'] !== null ? (int)$row['duree_maceration_cible_j'] : null,
                    'duree_maturation_cible_j' => $row['duree_maturation_cible_j'] !== null ? (int)$row['duree_maturation_cible_j'] : null,
                    'abv_cible_pct'            => $row['abv_cible_pct'] !== null ? (float)$row['abv_cible_pct'] : null,
                    'brix_cible'               => $row['brix_cible'] !== null ? (float)$row['brix_cible'] : null,
                    'avec_assemblage'          => (int)$row['avec_assemblage'],
                ] : null,
            ];
        }

        echo ResponseHelper::jsonResponse('OK', 'success', array_values($groupes), 200);
    }

    // ── GET /recettes-transfo/version?version_id= — détail complet ─
    public function getVersion()
    {
        $version_id = (int)($_GET['version_id'] ?? 0);

        if ($version_id <= 0) {
            echo ResponseHelper::jsonResponse('version_id invalide.', 'error', null, 400);
            return;
        }

        $stmt = $this->mysqli->prepare(
            "SELECT v.*, r.nom, r.famille, r.actif, r.gamme_id,
                    g.libelle AS gamme_libelle, g.slug AS gamme_slug,
                    mav.id AS mav_id,
                    mav.duree_maceration_cible_j, mav.duree_maturation_cible_j,
                    mav.abv_cible_pct, mav.brix_cible, mav.avec_assemblage
             FROM transfo_recette_version v
             JOIN transfo_recette r ON r.id = v.recette_id
             JOIN transfo_gamme g ON g.id = r.gamme_id
             LEFT JOIN mace_alcool_recette_version mav ON mav.transfo_recette_version_id = v.id
             WHERE v.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $version_id);
        $stmt->execute();
        $version = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$version) {
            echo ResponseHelper::jsonResponse('Version introuvable.', 'error', null, 404);
            return;
        }

        $version['id']          = (int)$version['id'];
        $version['recette_id']  = (int)$version['recette_id'];
        $version['gamme_id']    = (int)$version['gamme_id'];
        $version['numero']      = (int)$version['numero'];
        $version['nb_unites']   = (int)$version['nb_unites'];
        $version['difficulte']  = $version['difficulte'] !== null ? (int)$version['difficulte'] : null;
        $version['actif']       = (int)$version['actif'];
        $version['mace_alcool'] = $version['mav_id'] !== null ? [
            'duree_maceration_cible_j' => $version['duree_maceration_cible_j'] !== null ? (int)$version['duree_maceration_cible_j'] : null,
            'duree_maturation_cible_j' => $version['duree_maturation_cible_j'] !== null ? (int)$version['duree_maturation_cible_j'] : null,
            'abv_cible_pct'            => $version['abv_cible_pct'] !== null ? (float)$version['abv_cible_pct'] : null,
            'brix_cible'               => $version['brix_cible'] !== null ? (float)$version['brix_cible'] : null,
            'avec_assemblage'          => (int)$version['avec_assemblage'],
        ] : null;
        unset($version['mav_id'], $version['duree_maceration_cible_j'], $version['duree_maturation_cible_j'],
              $version['abv_cible_pct'], $version['brix_cible'], $version['avec_assemblage']);

        // Ingrédients
        $stmt = $this->mysqli->prepare(
            "SELECT id, stock_article_id, libelle, quantite, coeff_perte, unite, note
             FROM transfo_recette_ingredient
             WHERE version_id = ?
             ORDER BY id ASC"
        );
        $stmt->bind_param('i', $version_id);
        $stmt->execute();
        $ingredients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($ingredients as &$ing) {
            $ing['id']               = (int)$ing['id'];
            $ing['stock_article_id'] = $ing['stock_article_id'] !== null ? (int)$ing['stock_article_id'] : null;
            $ing['quantite']         = (float)$ing['quantite'];
            $ing['coeff_perte']      = (float)$ing['coeff_perte'];
        }

        // Phases + étapes groupées
        $stmt = $this->mysqli->prepare(
            "SELECT id, ordre, temporalite, label
             FROM transfo_recette_phase
             WHERE version_id = ?
             ORDER BY ordre ASC"
        );
        $stmt->bind_param('i', $version_id);
        $stmt->execute();
        $phases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmtEtape = $this->mysqli->prepare(
            "SELECT id, ordre, description
             FROM transfo_recette_etape
             WHERE version_id = ? AND phase_id = ?
             ORDER BY ordre ASC"
        );
        foreach ($phases as &$phase) {
            $phase['id']    = (int)$phase['id'];
            $phase['ordre'] = (int)$phase['ordre'];
            $pid = $phase['id'];
            $stmtEtape->bind_param('ii', $version_id, $pid);
            $stmtEtape->execute();
            $phase_etapes = $stmtEtape->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($phase_etapes as &$e) {
                $e['id']    = (int)$e['id'];
                $e['ordre'] = (int)$e['ordre'];
            }
            $phase['etapes'] = $phase_etapes;
        }
        $stmtEtape->close();

        // Points de contrôle
        $stmt = $this->mysqli->prepare(
            "SELECT id, etape_label, point_controle, valeur_cible, action_corrective
             FROM transfo_recette_controle
             WHERE version_id = ?
             ORDER BY id ASC"
        );
        $stmt->bind_param('i', $version_id);
        $stmt->execute();
        $controles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($controles as &$c) {
            $c['id'] = (int)$c['id'];
        }

        $version['ingredients'] = $ingredients;
        $version['phases']      = $phases;
        $version['controles']   = $controles;

        echo ResponseHelper::jsonResponse('OK', 'success', $version, 200);
    }

    // ── POST /recettes-transfo — créer recette + première version ──
    public function creerRecette()
    {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $gamme_id = (int)($body['gamme_id'] ?? 0);
        $nom      = trim($body['nom'] ?? '');
        $famille  = trim($body['famille'] ?? '') ?: null;

        if ($gamme_id <= 0 || $nom === '') {
            echo ResponseHelper::jsonResponse('gamme_id et nom requis.', 'error', null, 400);
            return;
        }

        $this->mysqli->begin_transaction();

        try {
            // 1. Identité recette
            $stmt = $this->mysqli->prepare(
                "INSERT INTO transfo_recette (gamme_id, nom, famille) VALUES (?, ?, ?)"
            );
            $stmt->bind_param('iss', $gamme_id, $nom, $famille);
            if (!$stmt->execute()) throw new Exception('Erreur recette: ' . $this->mysqli->error);
            $recette_id = (int)$this->mysqli->insert_id;
            $stmt->close();

            // 2. Version 1
            $version_id = $this->insererVersion($recette_id, 1, $body);

            // 3. Extension macération + sous-tables
            $this->sauvegarderMaceAlcool($version_id, $body);
            if (!empty($body['ingredients'])) $this->insererIngredients($version_id, $body['ingredients']);
            if (!empty($body['phases']))      $this->insererPhases($version_id, $body['phases']);
            if (!empty($body['controles']))   $this->insererControles($version_id, $body['controles']);

            $this->mysqli->commit();
            echo ResponseHelper::jsonResponse('Recette créée.', 'success', [
                'recette_id' => $recette_id,
                'version_id' => $version_id,
            ], 201);

        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── PUT /recettes-transfo — modifier une version existante ─────
    public function modifierVersion()
    {
        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
        $version_id = (int)($body['version_id'] ?? 0);

        if ($version_id <= 0) {
            echo ResponseHelper::jsonResponse('version_id invalide.', 'error', null, 400);
            return;
        }

        $this->mysqli->begin_transaction();

        try {
            $this->mettreAJourVersion($version_id, $body);

            // Mettre à jour l'identité recette si fournie
            if (!empty($body['recette_id'])) {
                $recette_id = (int)$body['recette_id'];
                $nom        = trim($body['nom'] ?? '');
                $famille    = trim($body['famille'] ?? '') ?: null;
                if ($nom !== '') {
                    $stmt = $this->mysqli->prepare(
                        "UPDATE transfo_recette SET nom = ?, famille = ? WHERE id = ?"
                    );
                    $stmt->bind_param('ssi', $nom, $famille, $recette_id);
                    if (!$stmt->execute()) throw new Exception('Erreur SQL recette: ' . $this->mysqli->error);
                    $stmt->close();
                }
            }

            // Extension macération (upsert via ON DUPLICATE KEY)
            $this->sauvegarderMaceAlcool($version_id, $body);

            // Reconstruire sous-tables — ordre : étapes avant phases (FK phase_id)
            foreach (['transfo_recette_ingredient', 'transfo_recette_etape', 'transfo_recette_phase', 'transfo_recette_controle'] as $table) {
                $stmt = $this->mysqli->prepare("DELETE FROM $table WHERE version_id = ?");
                $stmt->bind_param('i', $version_id);
                $stmt->execute();
                $stmt->close();
            }

            if (!empty($body['ingredients'])) $this->insererIngredients($version_id, $body['ingredients']);
            if (!empty($body['phases']))      $this->insererPhases($version_id, $body['phases']);
            if (!empty($body['controles']))   $this->insererControles($version_id, $body['controles']);

            $this->mysqli->commit();
            echo ResponseHelper::jsonResponse('Version mise à jour.', 'success', null, 200);

        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── POST /recettes-transfo/dupliquer?version_id= — fork ────────
    public function dupliquerVersion()
    {
        $version_id = (int)($_GET['version_id'] ?? 0);

        if ($version_id <= 0) {
            echo ResponseHelper::jsonResponse('version_id invalide.', 'error', null, 400);
            return;
        }

        $stmt = $this->mysqli->prepare(
            "SELECT * FROM transfo_recette_version WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $version_id);
        $stmt->execute();
        $source = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$source) {
            echo ResponseHelper::jsonResponse('Version introuvable.', 'error', null, 404);
            return;
        }

        $recette_id = (int)$source['recette_id'];

        $stmt = $this->mysqli->prepare(
            "SELECT MAX(numero) AS max_num FROM transfo_recette_version WHERE recette_id = ?"
        );
        $stmt->bind_param('i', $recette_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $nouveau_numero = (int)$row['max_num'] + 1;

        $this->mysqli->begin_transaction();

        try {
            $nouveau_version_id = $this->copierVersion($source, $nouveau_numero);

            // Copier extension mace_alcool si elle existe
            $stmtMav = $this->mysqli->prepare(
                "SELECT * FROM mace_alcool_recette_version WHERE transfo_recette_version_id = ? LIMIT 1"
            );
            $stmtMav->bind_param('i', $version_id);
            $stmtMav->execute();
            $mav = $stmtMav->get_result()->fetch_assoc();
            $stmtMav->close();

            if ($mav) {
                $this->sauvegarderMaceAlcool($nouveau_version_id, [
                    'duree_maceration_cible_j' => $mav['duree_maceration_cible_j'],
                    'duree_maturation_cible_j' => $mav['duree_maturation_cible_j'],
                    'abv_cible_pct'            => $mav['abv_cible_pct'],
                    'brix_cible'               => $mav['brix_cible'],
                    'avec_assemblage'          => (int)$mav['avec_assemblage'],
                ]);
            }

            // Copier ingrédients
            $stmt = $this->mysqli->prepare(
                "SELECT stock_article_id, libelle, quantite, coeff_perte, unite, note
                 FROM transfo_recette_ingredient WHERE version_id = ?"
            );
            $stmt->bind_param('i', $version_id);
            $stmt->execute();
            $ingredients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (!empty($ingredients)) $this->insererIngredients($nouveau_version_id, $ingredients);

            // Copier phases + étapes
            $stmt = $this->mysqli->prepare(
                "SELECT id, ordre, temporalite, label FROM transfo_recette_phase
                 WHERE version_id = ? ORDER BY ordre ASC"
            );
            $stmt->bind_param('i', $version_id);
            $stmt->execute();
            $phases_src = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (!empty($phases_src)) {
                $stmtP = $this->mysqli->prepare(
                    "INSERT INTO transfo_recette_phase (version_id, ordre, temporalite, label) VALUES (?, ?, ?, ?)"
                );
                $stmtE = $this->mysqli->prepare(
                    "INSERT INTO transfo_recette_etape (version_id, phase_id, ordre, description) VALUES (?, ?, ?, ?)"
                );
                foreach ($phases_src as $phase) {
                    $old_pid       = (int)$phase['id'];
                    $p_ordre       = (int)$phase['ordre'];
                    $p_temporalite = $phase['temporalite'];
                    $p_label       = $phase['label'];
                    $stmtP->bind_param('iiss', $nouveau_version_id, $p_ordre, $p_temporalite, $p_label);
                    if (!$stmtP->execute()) throw new Exception('Erreur SQL phase: ' . $this->mysqli->error);
                    $new_pid = (int)$this->mysqli->insert_id;

                    $stmtSrc = $this->mysqli->prepare(
                        "SELECT ordre, description FROM transfo_recette_etape
                         WHERE version_id = ? AND phase_id = ? ORDER BY ordre ASC"
                    );
                    $stmtSrc->bind_param('ii', $version_id, $old_pid);
                    $stmtSrc->execute();
                    $etapes_src = $stmtSrc->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmtSrc->close();

                    foreach ($etapes_src as $etape) {
                        $e_ordre = (int)$etape['ordre'];
                        $e_desc  = $etape['description'];
                        $stmtE->bind_param('iiis', $nouveau_version_id, $new_pid, $e_ordre, $e_desc);
                        if (!$stmtE->execute()) throw new Exception('Erreur SQL étape: ' . $this->mysqli->error);
                    }
                }
                $stmtP->close();
                $stmtE->close();
            }

            // Copier points de contrôle
            $stmt = $this->mysqli->prepare(
                "SELECT etape_label, point_controle, valeur_cible, action_corrective
                 FROM transfo_recette_controle WHERE version_id = ?"
            );
            $stmt->bind_param('i', $version_id);
            $stmt->execute();
            $controles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (!empty($controles)) $this->insererControles($nouveau_version_id, $controles);

            $this->mysqli->commit();
            echo ResponseHelper::jsonResponse('Version dupliquée.', 'success', [
                'version_id' => $nouveau_version_id,
                'numero'     => $nouveau_numero,
            ], 201);

        } catch (Exception $e) {
            $this->mysqli->rollback();
            echo ResponseHelper::jsonResponse('Erreur duplication: ' . $e->getMessage(), 'error', null, 500);
        }
    }

    // ── PUT /recettes-transfo/statut — changer le statut ───────────
    public function changerStatut()
    {
        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
        $version_id = (int)($body['version_id'] ?? 0);
        $statut     = $body['statut'] ?? '';

        if ($version_id <= 0 || !in_array($statut, ['brouillon', 'en_test', 'validee'])) {
            echo ResponseHelper::jsonResponse('Données invalides.', 'error', null, 400);
            return;
        }

        $stmt = $this->mysqli->prepare(
            "UPDATE transfo_recette_version SET statut = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $statut, $version_id);
        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur SQL: ' . $this->mysqli->error, 'error', null, 500);
            $stmt->close();
            return;
        }
        $stmt->close();

        echo ResponseHelper::jsonResponse('Statut mis à jour.', 'success', null, 200);
    }

    // ── DELETE /recettes-transfo?version_id= — supprimer une version
    public function supprimerVersion()
    {
        $version_id = (int)($_GET['version_id'] ?? 0);

        if ($version_id <= 0) {
            echo ResponseHelper::jsonResponse('version_id invalide.', 'error', null, 400);
            return;
        }

        // Bloquer si des lots utilisent cette version
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM mace_alcool_lot WHERE recette_version_id = ?"
        );
        $stmt->bind_param('i', $version_id);
        $stmt->execute();
        $nb_lots = (int)$stmt->get_result()->fetch_assoc()['nb'];
        $stmt->close();

        if ($nb_lots > 0) {
            echo ResponseHelper::jsonResponse(
                'Impossible : des lots utilisent cette version de recette.',
                'error', null, 409
            );
            return;
        }

        $stmt = $this->mysqli->prepare(
            "SELECT recette_id FROM transfo_recette_version WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $version_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo ResponseHelper::jsonResponse('Version introuvable.', 'error', null, 404);
            return;
        }

        $recette_id = (int)$row['recette_id'];

        // Vérifier que ce n'est pas la seule version
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM transfo_recette_version WHERE recette_id = ?"
        );
        $stmt->bind_param('i', $recette_id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['nb'];
        $stmt->close();

        if ($count <= 1) {
            echo ResponseHelper::jsonResponse(
                'Impossible de supprimer la seule version. Supprimez la recette entière.',
                'error', null, 409
            );
            return;
        }

        // DELETE CASCADE supprime les sous-tables et mace_alcool_recette_version
        $stmt = $this->mysqli->prepare(
            "DELETE FROM transfo_recette_version WHERE id = ?"
        );
        $stmt->bind_param('i', $version_id);
        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur SQL: ' . $this->mysqli->error, 'error', null, 500);
            $stmt->close();
            return;
        }
        $stmt->close();

        echo ResponseHelper::jsonResponse('Version supprimée.', 'success', null, 200);
    }

    // ── GET /recettes-transfo/export-pdf?version_id=&format= ───────
    public function exportPdf()
    {
        $version_id = (int)($_GET['version_id'] ?? 0);
        $format     = $_GET['format'] ?? 'chef';

        if ($version_id <= 0) {
            http_response_code(400);
            echo 'version_id invalide';
            return;
        }

        // Charger la version complète via getVersion()
        $_GET['version_id'] = $version_id;
        ob_start();
        $this->getVersion();
        $json = ob_get_clean();
        $resp = json_decode($json, true);

        if (!$resp || $resp['status'] !== 'success') {
            http_response_code(404);
            echo 'Version introuvable';
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPdfHtml($resp['details'], $format);
    }

    // ── Méthodes privées ──────────────────────────────────────────

    private function insererVersion(int $recette_id, int $numero, array $body): int
    {
        $statut           = $body['statut'] ?? 'brouillon';
        $notes_version    = isset($body['notes_version'])    && $body['notes_version'] !== ''    ? $body['notes_version']    : null;
        $description      = isset($body['description'])      && $body['description'] !== ''      ? $body['description']      : null;
        $nb_unites        = (int)($body['nb_unites'] ?? 1);
        $unite_production = isset($body['unite_production']) && $body['unite_production'] !== '' ? $body['unite_production'] : null;
        $materiel         = isset($body['materiel'])         && $body['materiel'] !== ''         ? $body['materiel']         : null;
        $difficulte       = isset($body['difficulte'])       && $body['difficulte'] !== ''       ? (int)$body['difficulte']  : null;
        $conservation     = isset($body['conservation'])     && $body['conservation'] !== ''     ? $body['conservation']     : null;

        $stmt = $this->mysqli->prepare(
            "INSERT INTO transfo_recette_version
             (recette_id, numero, statut, notes_version, description,
              nb_unites, unite_production, materiel, difficulte, conservation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iisssissis',
            $recette_id, $numero, $statut, $notes_version, $description,
            $nb_unites, $unite_production, $materiel, $difficulte, $conservation
        );

        if (!$stmt->execute()) {
            $err = $this->mysqli->error;
            $stmt->close();
            throw new Exception('Erreur SQL version: ' . $err);
        }
        $id = (int)$this->mysqli->insert_id;
        $stmt->close();
        return $id;
    }

    private function mettreAJourVersion(int $version_id, array $body): void
    {
        $notes_version    = isset($body['notes_version'])    && $body['notes_version'] !== ''    ? $body['notes_version']    : null;
        $description      = isset($body['description'])      && $body['description'] !== ''      ? $body['description']      : null;
        $nb_unites        = (int)($body['nb_unites'] ?? 1);
        $unite_production = isset($body['unite_production']) && $body['unite_production'] !== '' ? $body['unite_production'] : null;
        $materiel         = isset($body['materiel'])         && $body['materiel'] !== ''         ? $body['materiel']         : null;
        $difficulte       = isset($body['difficulte'])       && $body['difficulte'] !== ''       ? (int)$body['difficulte']  : null;
        $conservation     = isset($body['conservation'])     && $body['conservation'] !== ''     ? $body['conservation']     : null;

        $stmt = $this->mysqli->prepare(
            "UPDATE transfo_recette_version
             SET notes_version = ?, description = ?, nb_unites = ?,
                 unite_production = ?, materiel = ?, difficulte = ?, conservation = ?
             WHERE id = ?"
        );
        $stmt->bind_param(
            'ssissisi',
            $notes_version, $description, $nb_unites,
            $unite_production, $materiel, $difficulte, $conservation, $version_id
        );

        if (!$stmt->execute()) {
            $err = $this->mysqli->error;
            $stmt->close();
            throw new Exception('Erreur SQL update version: ' . $err);
        }
        $stmt->close();
    }

    private function copierVersion(array $source, int $nouveau_numero): int
    {
        $recette_id       = (int)$source['recette_id'];
        $statut           = 'brouillon';
        $notes_version    = $source['notes_version'];
        $description      = $source['description'];
        $nb_unites        = (int)$source['nb_unites'];
        $unite_production = $source['unite_production'];
        $materiel         = $source['materiel'];
        $difficulte       = $source['difficulte'] !== null ? (int)$source['difficulte'] : null;
        $conservation     = $source['conservation'];

        $stmt = $this->mysqli->prepare(
            "INSERT INTO transfo_recette_version
             (recette_id, numero, statut, notes_version, description,
              nb_unites, unite_production, materiel, difficulte, conservation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iisssissis',
            $recette_id, $nouveau_numero, $statut, $notes_version, $description,
            $nb_unites, $unite_production, $materiel, $difficulte, $conservation
        );

        if (!$stmt->execute()) {
            $err = $this->mysqli->error;
            $stmt->close();
            throw new Exception('Erreur SQL copie version: ' . $err);
        }
        $id = (int)$this->mysqli->insert_id;
        $stmt->close();
        return $id;
    }

    private function sauvegarderMaceAlcool(int $version_id, array $body): void
    {
        $duree_mac       = isset($body['duree_maceration_cible_j']) && $body['duree_maceration_cible_j'] !== '' && $body['duree_maceration_cible_j'] !== null
            ? (int)$body['duree_maceration_cible_j'] : null;
        $duree_mat       = isset($body['duree_maturation_cible_j']) && $body['duree_maturation_cible_j'] !== '' && $body['duree_maturation_cible_j'] !== null
            ? (int)$body['duree_maturation_cible_j'] : null;
        $abv             = isset($body['abv_cible_pct'])    && $body['abv_cible_pct'] !== ''    && $body['abv_cible_pct'] !== null
            ? (float)$body['abv_cible_pct'] : null;
        $brix            = isset($body['brix_cible'])       && $body['brix_cible'] !== ''       && $body['brix_cible'] !== null
            ? (float)$body['brix_cible'] : null;
        $avec_assemblage = isset($body['avec_assemblage']) ? (int)$body['avec_assemblage'] : 0;

        // Pas de données mace_alcool à sauvegarder
        if ($duree_mac === null && $duree_mat === null && $abv === null && $brix === null && !$avec_assemblage) {
            return;
        }

        $stmt = $this->mysqli->prepare(
            "INSERT INTO mace_alcool_recette_version
                 (transfo_recette_version_id, duree_maceration_cible_j, duree_maturation_cible_j,
                  abv_cible_pct, brix_cible, avec_assemblage)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 duree_maceration_cible_j = VALUES(duree_maceration_cible_j),
                 duree_maturation_cible_j = VALUES(duree_maturation_cible_j),
                 abv_cible_pct            = VALUES(abv_cible_pct),
                 brix_cible               = VALUES(brix_cible),
                 avec_assemblage          = VALUES(avec_assemblage)"
        );
        $stmt->bind_param('iiiddi',
            $version_id, $duree_mac, $duree_mat, $abv, $brix, $avec_assemblage
        );

        if (!$stmt->execute()) {
            $err = $this->mysqli->error;
            $stmt->close();
            throw new Exception('Erreur SQL mace_alcool: ' . $err);
        }
        $stmt->close();
    }

    private function insererIngredients(int $version_id, array $ingredients): void
    {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO transfo_recette_ingredient
                 (version_id, stock_article_id, libelle, quantite, coeff_perte, unite, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($ingredients as $ing) {
            $libelle  = trim($ing['libelle'] ?? '');
            $quantite = (float)($ing['quantite'] ?? 0);
            $unite    = trim($ing['unite'] ?? '');
            if ($libelle === '' || $quantite <= 0 || $unite === '') continue;

            $stock_article_id = isset($ing['stock_article_id']) && $ing['stock_article_id'] !== null
                ? (int)$ing['stock_article_id'] : null;
            $coeff_perte = (float)($ing['coeff_perte'] ?? 1.0);
            $note        = isset($ing['note']) && $ing['note'] !== '' ? trim($ing['note']) : null;

            $stmt->bind_param('iisddss',
                $version_id, $stock_article_id, $libelle, $quantite, $coeff_perte, $unite, $note
            );
            if (!$stmt->execute()) {
                $err = $this->mysqli->error;
                $stmt->close();
                throw new Exception('Erreur SQL ingrédient: ' . $err);
            }
        }
        $stmt->close();
    }

    private function insererPhases(int $version_id, array $phases): void
    {
        $stmtP = $this->mysqli->prepare(
            "INSERT INTO transfo_recette_phase (version_id, ordre, temporalite, label) VALUES (?, ?, ?, ?)"
        );
        $stmtE = $this->mysqli->prepare(
            "INSERT INTO transfo_recette_etape (version_id, phase_id, ordre, description) VALUES (?, ?, ?, ?)"
        );
        foreach ($phases as $p_idx => $phase) {
            $p_ordre       = $p_idx;
            $p_temporalite = trim($phase['temporalite'] ?? '');
            $p_label       = trim($phase['label'] ?? '');
            $stmtP->bind_param('iiss', $version_id, $p_ordre, $p_temporalite, $p_label);
            if (!$stmtP->execute()) {
                $err = $this->mysqli->error;
                $stmtP->close(); $stmtE->close();
                throw new Exception('Erreur SQL phase: ' . $err);
            }
            $phase_id = (int)$this->mysqli->insert_id;
            foreach (($phase['etapes'] ?? []) as $e_idx => $etape) {
                $e_ordre = $e_idx;
                $e_desc  = trim($etape['description'] ?? '');
                if ($e_desc === '') continue;
                $stmtE->bind_param('iiis', $version_id, $phase_id, $e_ordre, $e_desc);
                if (!$stmtE->execute()) {
                    $err = $this->mysqli->error;
                    $stmtP->close(); $stmtE->close();
                    throw new Exception('Erreur SQL étape: ' . $err);
                }
            }
        }
        $stmtP->close();
        $stmtE->close();
    }

    private function insererControles(int $version_id, array $controles): void
    {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO transfo_recette_controle
                 (version_id, etape_label, point_controle, valeur_cible, action_corrective)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($controles as $ctrl) {
            $point   = trim($ctrl['point_controle'] ?? '');
            if ($point === '') continue;
            $label   = trim($ctrl['etape_label'] ?? '');
            $valeur  = trim($ctrl['valeur_cible'] ?? '');
            $action  = trim($ctrl['action_corrective'] ?? '');
            $stmt->bind_param('issss', $version_id, $label, $point, $valeur, $action);
            if (!$stmt->execute()) {
                $err = $this->mysqli->error;
                $stmt->close();
                throw new Exception('Erreur SQL contrôle: ' . $err);
            }
        }
        $stmt->close();
    }

    private function renderPdfHtml(array $v, string $format): string
    {
        $nom        = htmlspecialchars($v['nom'] ?? '');
        $diff_map   = [1 => '★☆☆ Facile', 2 => '★★☆ Moyen', 3 => '★★★ Difficile'];
        $diff       = $diff_map[(int)($v['difficulte'] ?? 0)] ?? '';
        $statut_map = ['brouillon' => 'Brouillon', 'en_test' => 'En test', 'validee' => 'Validée'];
        $mav        = $v['mace_alcool'] ?? null;

        $html  = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">';
        $html .= '<title>Recette — ' . $nom . ' v' . (int)$v['numero'] . '</title>';
        $html .= '<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#222;margin:0;padding:20px}
h1{font-size:24px;text-align:center;margin:6px 0 2px}
.meta-header{text-align:center;font-size:9px;color:#888;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px}
.version-info{text-align:center;margin-bottom:14px}
.notes-version{display:inline-block;background:#EDE9FE;border:1px solid #7F77DD;border-radius:4px;padding:4px 12px;font-size:12px;font-weight:600;color:#4B4595}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.block{border:1px solid #ccc;padding:10px}
.block h3{font-size:10px;font-weight:bold;color:#7F77DD;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;border-bottom:1px solid #7F77DD;padding-bottom:4px}
.block p{margin:3px 0}
.section-title{font-size:12px;font-weight:bold;color:#7F77DD;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #7F77DD;padding-bottom:4px;margin:14px 0 8px}
table{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:10px}
th{background:#7F77DD;color:white;padding:5px 6px;text-align:left}
td{padding:4px 6px;border-bottom:1px solid #ddd}
tr:nth-child(even) td{background:#f5f5f5}
ol{margin:0;padding-left:20px}
ol li{margin:4px 0;line-height:1.4}
.phase-label{font-size:11px;font-weight:bold;color:#7F77DD;text-transform:uppercase;letter-spacing:0.5px;margin:10px 0 4px;padding-left:4px;border-left:3px solid #7F77DD}
.footer{margin-top:18px;border-top:1px solid #ccc;padding-top:8px;font-size:9px;color:#888;display:flex;justify-content:space-between}
.print-btn{position:fixed;top:10px;right:10px;background:#7F77DD;color:white;border:none;padding:8px 16px;cursor:pointer;border-radius:4px;font-size:12px}
@media print{.no-print{display:none}@page{margin:1.5cm}}
</style></head><body>';

        $html .= '<button class="print-btn no-print" onclick="window.print()">Imprimer / PDF</button>';
        $html .= '<div class="meta-header">FICHE RECETTE • Ferme du Peyrounet — Macération alcoolique • Document interne</div>';
        $html .= '<h1>' . $nom . '</h1>';
        $html .= '<div class="version-info">';
        $html .= 'Version <strong>v' . (int)$v['numero'] . '</strong> — ' . ($statut_map[$v['statut']] ?? $v['statut']);
        if (!empty($v['notes_version'])) {
            $html .= '<br><span class="notes-version">' . htmlspecialchars($v['notes_version']) . '</span>';
        }
        $html .= '</div>';

        $html .= '<div class="two-col">';
        $html .= '<div class="block"><h3>Informations</h3>';
        if (!empty($v['famille']))         $html .= '<p><strong>Famille :</strong> '     . htmlspecialchars($v['famille']) . '</p>';
        if (!empty($v['unite_production'])) $html .= '<p><strong>Production :</strong> ' . (int)$v['nb_unites'] . ' × ' . htmlspecialchars($v['unite_production']) . '</p>';
        if (!empty($v['conservation']))    $html .= '<p><strong>Conservation :</strong> ' . htmlspecialchars($v['conservation']) . '</p>';
        if ($diff)                         $html .= '<p><strong>Difficulté :</strong> '  . $diff . '</p>';
        $html .= '</div>';

        $html .= '<div class="block"><h3>Paramètres macération</h3>';
        if ($mav) {
            if ($mav['duree_maceration_cible_j'] !== null) $html .= '<p><strong>Macération :</strong> ' . (int)$mav['duree_maceration_cible_j'] . ' j</p>';
            if ($mav['duree_maturation_cible_j'] !== null) $html .= '<p><strong>Maturation :</strong> ' . (int)$mav['duree_maturation_cible_j'] . ' j</p>';
            if ($mav['abv_cible_pct'] !== null)            $html .= '<p><strong>ABV cible :</strong> '  . $mav['abv_cible_pct'] . ' %vol</p>';
            if ($mav['brix_cible'] !== null)               $html .= '<p><strong>Brix cible :</strong> ' . $mav['brix_cible'] . ' °Bx</p>';
            $html .= '<p><strong>Assemblage :</strong> ' . ($mav['avec_assemblage'] ? 'Oui (liqueur)' : 'Non (eau-de-vie)') . '</p>';
        } else {
            $html .= '<p style="color:#999;font-style:italic">Aucun paramètre renseigné.</p>';
        }
        $html .= '</div></div>';

        if (!empty($v['materiel'])) {
            $html .= '<div class="section-title">Matériel</div>';
            $html .= '<p>' . nl2br(htmlspecialchars($v['materiel'])) . '</p>';
        }

        if (!empty($v['ingredients'])) {
            $html .= '<div class="section-title">Ingrédients — pour ' . (int)$v['nb_unites'] . ' ' . htmlspecialchars($v['unite_production'] ?? 'unités') . '</div>';
            $html .= '<table><thead><tr><th>Ingrédient</th><th>Quantité</th><th>Unité</th><th>Coeff perte</th><th>Note</th></tr></thead><tbody>';
            foreach ($v['ingredients'] as $ing) {
                $html .= '<tr><td>' . htmlspecialchars($ing['libelle']) . '</td>';
                $html .= '<td>' . $ing['quantite'] . '</td>';
                $html .= '<td>' . htmlspecialchars($ing['unite']) . '</td>';
                $html .= '<td>' . number_format($ing['coeff_perte'], 2) . '</td>';
                $html .= '<td>' . htmlspecialchars($ing['note'] ?? '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if (!empty($v['phases'])) {
            $html .= '<div class="section-title">Protocole</div>';
            $num_global = 0;
            $html .= '<ol>';
            foreach ($v['phases'] as $phase) {
                $titre = trim(($phase['temporalite'] ?? '') . ($phase['temporalite'] && $phase['label'] ? ' — ' : '') . ($phase['label'] ?? ''));
                if ($titre !== '') {
                    $html .= '</ol><div class="phase-label">' . htmlspecialchars($titre) . '</div>';
                    $html .= '<ol start="' . ($num_global + 1) . '">';
                }
                foreach (($phase['etapes'] ?? []) as $e) {
                    $num_global++;
                    $html .= '<li>' . nl2br(htmlspecialchars($e['description'])) . '</li>';
                }
            }
            $html .= '</ol>';
        }

        if (!empty($v['controles'])) {
            $html .= '<div class="section-title">Points de contrôle</div>';
            $html .= '<table><thead><tr><th>Étape</th><th>Point de contrôle</th><th>Valeur cible</th><th>Action corrective</th></tr></thead><tbody>';
            foreach ($v['controles'] as $c) {
                $html .= '<tr><td>' . htmlspecialchars($c['etape_label'] ?? '') . '</td>';
                $html .= '<td>' . htmlspecialchars($c['point_controle']) . '</td>';
                $html .= '<td><strong>' . htmlspecialchars($c['valeur_cible'] ?? '') . '</strong></td>';
                $html .= '<td>' . htmlspecialchars($c['action_corrective'] ?? '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="footer">';
        $html .= '<span>Document de travail vivant — toute modification entraîne une nouvelle version.</span>';
        $html .= '<span>v' . (int)$v['numero'] . ' • ' . ($statut_map[$v['statut']] ?? '') . ' • Ferme du Peyrounet</span>';
        $html .= '</div></body></html>';

        return $html;
    }
}
