<?php
/**
 * GammeController.php — /transformation
 * CRUD du catalogue des gammes (transfo_gamme).
 * @php 7.4+
 */

use helpers\ResponseHelper;

class GammeController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ── GET /gammes ───────────────────────────────────────────────
    // Retourne toutes les gammes avec compteurs (produits + lots actifs).
    public function getAll()
    {
        $result = $this->mysqli->query(
            "SELECT g.id, g.slug, g.libelle, g.actif, g.created_at,
                    COUNT(DISTINCT p.id)                                         AS nb_produits,
                    COUNT(DISTINCT CASE WHEN l.statut IN ('preparation','en_repos','production')
                                        THEN l.id END)                           AS nb_lots_actifs,
                    COUNT(DISTINCT l.id)                                         AS nb_lots_total
             FROM transfo_gamme g
             LEFT JOIN transfo_produit p ON p.gamme_id = g.id AND p.actif = 1
             LEFT JOIN transfo_lot     l ON l.gamme_id = g.id
             GROUP BY g.id
             ORDER BY g.id ASC"
        );

        $gammes = [];
        while ($row = $result->fetch_assoc()) {
            $row['id']             = (int) $row['id'];
            $row['actif']          = (int) $row['actif'];
            $row['nb_produits']    = (int) $row['nb_produits'];
            $row['nb_lots_actifs'] = (int) $row['nb_lots_actifs'];
            $row['nb_lots_total']  = (int) $row['nb_lots_total'];
            $gammes[] = $row;
        }

        echo ResponseHelper::jsonResponse('OK', 'success', $gammes);
    }

    // ── POST /gammes ──────────────────────────────────────────────
    public function create($data)
    {
        $slug    = trim(strtolower($data['slug']    ?? ''));
        $libelle = trim($data['libelle'] ?? '');
        $actif   = (int) ($data['actif'] ?? 1);

        if ($slug === '' || $libelle === '') {
            echo ResponseHelper::jsonResponse('Slug et libellé obligatoires.', 'error', null, 400);
            return;
        }

        if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
            echo ResponseHelper::jsonResponse(
                'Slug invalide — lettres minuscules, chiffres et underscore uniquement.',
                'error', null, 400
            );
            return;
        }

        $stmt = $this->mysqli->prepare(
            "INSERT INTO transfo_gamme (slug, libelle, actif) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('ssi', $slug, $libelle, $actif);

        if (!$stmt->execute()) {
            if ($this->mysqli->errno === 1062) {
                echo ResponseHelper::jsonResponse('Ce slug existe déjà.', 'error', null, 409);
            } else {
                echo ResponseHelper::jsonResponse('Erreur BDD : ' . $this->mysqli->error, 'error', null, 500);
            }
            return;
        }

        $newId = (int) $this->mysqli->insert_id;
        echo ResponseHelper::jsonResponse('Gamme créée.', 'success', ['id' => $newId], 201);
    }

    // ── PUT /gammes/:id ───────────────────────────────────────────
    public function update($id, $data)
    {
        $id      = (int) $id;
        $libelle = trim($data['libelle'] ?? '');
        $actif   = (int) ($data['actif'] ?? 1);

        if ($libelle === '') {
            echo ResponseHelper::jsonResponse('Libellé obligatoire.', 'error', null, 400);
            return;
        }

        // Le slug n'est pas modifiable après création (référencé dans les menus et le code frontend).
        $stmt = $this->mysqli->prepare(
            "UPDATE transfo_gamme SET libelle=?, actif=? WHERE id=?"
        );
        $stmt->bind_param('sii', $libelle, $actif, $id);

        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur BDD : ' . $this->mysqli->error, 'error', null, 500);
            return;
        }

        if ($stmt->affected_rows === 0) {
            echo ResponseHelper::jsonResponse('Gamme introuvable.', 'error', null, 404);
            return;
        }

        echo ResponseHelper::jsonResponse('Gamme mise à jour.', 'success');
    }

    // ── DELETE /gammes/:id ────────────────────────────────────────
    // Soft delete si des lots ou produits sont rattachés, suppression physique sinon.
    public function delete($id)
    {
        $id = (int) $id;

        // Compter les lots rattachés à cette gamme
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM transfo_lot WHERE gamme_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $nb_lots = (int) $stmt->get_result()->fetch_assoc()['nb'];
        $stmt->close();

        // Compter les produits rattachés à cette gamme
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM transfo_produit WHERE gamme_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $nb_produits = (int) $stmt->get_result()->fetch_assoc()['nb'];
        $stmt->close();

        if ($nb_lots > 0 || $nb_produits > 0) {
            $stmt = $this->mysqli->prepare(
                "UPDATE transfo_gamme SET actif = 0 WHERE id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            echo ResponseHelper::jsonResponse(
                'Gamme désactivée (des lots ou produits y sont rattachés).',
                'success'
            );
        } else {
            $stmt = $this->mysqli->prepare("DELETE FROM transfo_gamme WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            echo ResponseHelper::jsonResponse('Gamme supprimée.', 'success');
        }
    }
}
