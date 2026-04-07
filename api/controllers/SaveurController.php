<?php
/**
 * SaveurController.php — /crufiture
 * CRUD du référentiel des saveurs (cruf_saveur).
 * @php 7.4+
 */

use helpers\ResponseHelper;

class SaveurController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ── GET /saveurs ──────────────────────────────────────────────
    public function getAll()
    {
        $result = $this->mysqli->query(
            "SELECT s.id, s.nom, s.slug, s.brix_cible, s.pa_cible, s.pct_fructose,
                    s.note, s.stock_article_id, s.actif, s.created_at,
                    sa.libelle AS stock_article_libelle
             FROM cruf_saveur s
             LEFT JOIN stock_article sa ON sa.id = s.stock_article_id
             ORDER BY s.nom ASC"
        );

        $saveurs = [];
        while ($row = $result->fetch_assoc()) {
            $row['id']                    = (int)   $row['id'];
            $row['actif']                 = (int)   $row['actif'];
            $row['brix_cible']            = (float) $row['brix_cible'];
            $row['pa_cible']              = (float) $row['pa_cible'];
            $row['pct_fructose']          = (float) $row['pct_fructose'];
            $row['stock_article_id']      = $row['stock_article_id'] !== null ? (int) $row['stock_article_id'] : null;
            $row['stock_article_libelle'] = $row['stock_article_libelle'] ?? null;
            $saveurs[] = $row;
        }

        echo ResponseHelper::jsonResponse('OK', 'success', $saveurs);
    }

    // ── POST /saveurs ─────────────────────────────────────────────
    public function create($data)
    {
        $nom              = trim($data['nom']  ?? '');
        $slug             = trim($data['slug'] ?? '');
        $brix_cible       = (float) ($data['brix_cible']   ?? 70);
        $pa_cible         = (float) ($data['pa_cible']     ?? 68);
        $pct_fructose     = (float) ($data['pct_fructose'] ?? 50);
        $note             = (isset($data['note']) && $data['note'] !== '') ? $data['note'] : null;
        $stock_article_id = (isset($data['stock_article_id']) && $data['stock_article_id'] !== '' && $data['stock_article_id'] !== null)
                            ? (int) $data['stock_article_id'] : null;
        $actif            = 1;

        if ($nom === '' || $slug === '') {
            echo ResponseHelper::jsonResponse('Nom et slug obligatoires.', 'error', null, 400);
            return;
        }

        $stmt = $this->mysqli->prepare(
            "INSERT INTO cruf_saveur (nom, slug, brix_cible, pa_cible, pct_fructose, note, stock_article_id, actif)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        // types : s s d d d s i i
        $stmt->bind_param('ssdddsii', $nom, $slug, $brix_cible, $pa_cible, $pct_fructose, $note, $stock_article_id, $actif);

        if (!$stmt->execute()) {
            if ($this->mysqli->errno === 1062) {
                echo ResponseHelper::jsonResponse('Ce slug existe déjà.', 'error', null, 409);
            } else {
                echo ResponseHelper::jsonResponse('Erreur BDD.', 'error', null, 500);
            }
            return;
        }

        $newId = (int) $this->mysqli->insert_id;
        echo ResponseHelper::jsonResponse('Saveur créée.', 'success', ['id' => $newId], 201);
    }

    // ── PUT /saveurs/:id ──────────────────────────────────────────
    public function update($id, $data)
    {
        $id               = (int)   $id;
        $nom              = trim($data['nom']  ?? '');
        $slug             = trim($data['slug'] ?? '');
        $brix_cible       = (float) ($data['brix_cible']   ?? 70);
        $pa_cible         = (float) ($data['pa_cible']     ?? 68);
        $pct_fructose     = (float) ($data['pct_fructose'] ?? 50);
        $note             = (isset($data['note']) && $data['note'] !== '') ? $data['note'] : null;
        $stock_article_id = (isset($data['stock_article_id']) && $data['stock_article_id'] !== '' && $data['stock_article_id'] !== null)
                            ? (int) $data['stock_article_id'] : null;
        $actif            = (int) ($data['actif'] ?? 1);

        if ($nom === '' || $slug === '') {
            echo ResponseHelper::jsonResponse('Nom et slug obligatoires.', 'error', null, 400);
            return;
        }

        $stmt = $this->mysqli->prepare(
            "UPDATE cruf_saveur
             SET nom=?, slug=?, brix_cible=?, pa_cible=?, pct_fructose=?, note=?, stock_article_id=?, actif=?
             WHERE id=?"
        );
        // types : s s d d d s i i i
        $stmt->bind_param('ssdddsiii', $nom, $slug, $brix_cible, $pa_cible, $pct_fructose, $note, $stock_article_id, $actif, $id);

        if (!$stmt->execute()) {
            if ($this->mysqli->errno === 1062) {
                echo ResponseHelper::jsonResponse('Ce slug existe déjà.', 'error', null, 409);
            } else {
                echo ResponseHelper::jsonResponse('Erreur BDD.', 'error', null, 500);
            }
            return;
        }

        echo ResponseHelper::jsonResponse('Saveur mise à jour.', 'success');
    }

    // ── DELETE /saveurs/:id ───────────────────────────────────────
    // Soft delete si des lots sont rattachés, suppression physique sinon.
    public function delete($id)
    {
        $id = (int) $id;

        // Vérifier si des lots référencent cette saveur
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM cruf_lot WHERE saveur_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $nb = (int) $stmt->get_result()->fetch_assoc()['nb'];
        $stmt->close();

        if ($nb > 0) {
            // Des lots existent → désactivation uniquement
            $stmt2 = $this->mysqli->prepare(
                "UPDATE cruf_saveur SET actif = 0 WHERE id = ?"
            );
            $stmt2->bind_param('i', $id);
            $stmt2->execute();
            echo ResponseHelper::jsonResponse(
                'Saveur désactivée (des lots y sont rattachés).',
                'success'
            );
        } else {
            // Aucun lot → suppression physique
            $stmt2 = $this->mysqli->prepare("DELETE FROM cruf_saveur WHERE id = ?");
            $stmt2->bind_param('i', $id);
            $stmt2->execute();
            echo ResponseHelper::jsonResponse('Saveur supprimée.', 'success');
        }
    }
}