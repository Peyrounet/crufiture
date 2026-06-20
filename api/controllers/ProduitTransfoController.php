<?php
/**
 * ProduitTransfoController.php — /transformation
 * CRUD des produits d'une gamme (transfo_produit).
 * @php 7.4+
 */

use helpers\ResponseHelper;

class ProduitTransfoController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ── GET /gammes/:gamme_id/produits ────────────────────────────
    public function getAllByGamme($gamme_id)
    {
        $gamme_id = (int) $gamme_id;

        $stmt = $this->mysqli->prepare(
            "SELECT p.id, p.gamme_id, p.nom, p.slug, p.stock_article_id,
                    p.note, p.actif, p.created_at,
                    sa.libelle AS stock_article_libelle,
                    COUNT(DISTINCT lp.lot_id) AS nb_lots
             FROM transfo_produit p
             LEFT JOIN stock_article sa ON sa.id = p.stock_article_id
             LEFT JOIN transfo_lot_produit lp ON lp.produit_id = p.id
             WHERE p.gamme_id = ?
             GROUP BY p.id
             ORDER BY p.nom ASC"
        );
        $stmt->bind_param('i', $gamme_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $produits = [];
        while ($row = $result->fetch_assoc()) {
            $row['id']                    = (int)   $row['id'];
            $row['gamme_id']              = (int)   $row['gamme_id'];
            $row['actif']                 = (int)   $row['actif'];
            $row['nb_lots']               = (int)   $row['nb_lots'];
            $row['stock_article_id']      = $row['stock_article_id'] !== null ? (int) $row['stock_article_id'] : null;
            $row['stock_article_libelle'] = $row['stock_article_libelle'] ?? null;
            $produits[] = $row;
        }

        echo ResponseHelper::jsonResponse('OK', 'success', $produits);
    }

    // ── POST /gammes/:gamme_id/produits ───────────────────────────
    public function create($gamme_id, $data)
    {
        $gamme_id         = (int) $gamme_id;
        $nom              = trim($data['nom']  ?? '');
        $slug             = trim($data['slug'] ?? '');
        $stock_article_id = (isset($data['stock_article_id']) && $data['stock_article_id'] !== '' && $data['stock_article_id'] !== null)
                            ? (int) $data['stock_article_id'] : null;
        $note             = (isset($data['note']) && $data['note'] !== '') ? trim($data['note']) : null;
        $actif            = 1;

        if ($nom === '' || $slug === '') {
            echo ResponseHelper::jsonResponse('Nom et slug obligatoires.', 'error', null, 400);
            return;
        }

        // Vérifier que la gamme existe
        $stmt = $this->mysqli->prepare("SELECT id FROM transfo_gamme WHERE id = ?");
        $stmt->bind_param('i', $gamme_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo ResponseHelper::jsonResponse('Gamme introuvable.', 'error', null, 404);
            return;
        }
        $stmt->close();

        $stmt = $this->mysqli->prepare(
            "INSERT INTO transfo_produit (gamme_id, nom, slug, stock_article_id, note, actif)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issisi', $gamme_id, $nom, $slug, $stock_article_id, $note, $actif);

        if (!$stmt->execute()) {
            if ($this->mysqli->errno === 1062) {
                echo ResponseHelper::jsonResponse('Ce slug existe déjà.', 'error', null, 409);
            } else {
                echo ResponseHelper::jsonResponse('Erreur BDD : ' . $this->mysqli->error, 'error', null, 500);
            }
            return;
        }

        $newId = (int) $this->mysqli->insert_id;
        echo ResponseHelper::jsonResponse('Produit créé.', 'success', ['id' => $newId], 201);
    }

    // ── PUT /gammes/:gamme_id/produits/:produit_id ────────────────
    public function update($gamme_id, $produit_id, $data)
    {
        $gamme_id         = (int) $gamme_id;
        $produit_id       = (int) $produit_id;
        $nom              = trim($data['nom']  ?? '');
        $stock_article_id = (isset($data['stock_article_id']) && $data['stock_article_id'] !== '' && $data['stock_article_id'] !== null)
                            ? (int) $data['stock_article_id'] : null;
        $note             = (isset($data['note']) && $data['note'] !== '') ? trim($data['note']) : null;
        $actif            = (int) ($data['actif'] ?? 1);

        if ($nom === '') {
            echo ResponseHelper::jsonResponse('Nom obligatoire.', 'error', null, 400);
            return;
        }

        // Le slug n'est pas modifiable (référencé dans stock_article et les bridges).
        $stmt = $this->mysqli->prepare(
            "UPDATE transfo_produit SET nom=?, stock_article_id=?, note=?, actif=?
             WHERE id=? AND gamme_id=?"
        );
        $stmt->bind_param('sissii', $nom, $stock_article_id, $note, $actif, $produit_id, $gamme_id);

        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur BDD : ' . $this->mysqli->error, 'error', null, 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            echo ResponseHelper::jsonResponse('Produit introuvable.', 'error', null, 404);
            return;
        }

        echo ResponseHelper::jsonResponse('Produit mis à jour.', 'success');
    }

    // ── DELETE /gammes/:gamme_id/produits/:produit_id ─────────────
    // Soft delete si le produit est référencé dans des lots, suppression physique sinon.
    public function delete($gamme_id, $produit_id)
    {
        $gamme_id   = (int) $gamme_id;
        $produit_id = (int) $produit_id;

        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM transfo_lot_produit WHERE produit_id = ?"
        );
        $stmt->bind_param('i', $produit_id);
        $stmt->execute();
        $nb_lots = (int) $stmt->get_result()->fetch_assoc()['nb'];
        $stmt->close();

        if ($nb_lots > 0) {
            $stmt = $this->mysqli->prepare(
                "UPDATE transfo_produit SET actif = 0 WHERE id = ? AND gamme_id = ?"
            );
            $stmt->bind_param('ii', $produit_id, $gamme_id);
            $stmt->execute();
            echo ResponseHelper::jsonResponse(
                'Produit désactivé (référencé dans des lots de production).',
                'success'
            );
        } else {
            $stmt = $this->mysqli->prepare(
                "DELETE FROM transfo_produit WHERE id = ? AND gamme_id = ?"
            );
            $stmt->bind_param('ii', $produit_id, $gamme_id);
            $stmt->execute();
            echo ResponseHelper::jsonResponse('Produit supprimé.', 'success');
        }
    }
}
