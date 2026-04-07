<?php
/**
 * RecetteController.php — /crufiture
 * CRUD recettes + ingrédients + étapes.
 * Une "modification" crée toujours une nouvelle version (INSERT),
 * elle ne modifie jamais une recette existante (traçabilité lots).
 * @php 7.4+
 */

use helpers\ResponseHelper;

class RecetteController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ── GET /recettes ─────────────────────────────────────────────
    // Retourne toutes les recettes actives groupées par saveur,
    // avec le nombre d'ingrédients et d'étapes pour l'affichage liste.
    public function getAll()
    {
        $result = $this->mysqli->query(
            "SELECT r.id, r.saveur_id, r.version, r.titre, r.note, r.actif, r.created_at,
                    s.nom AS saveur_nom, s.slug AS saveur_slug,
                    (SELECT COUNT(*) FROM cruf_recette_ingredient i WHERE i.recette_id = r.id) AS nb_ingredients,
                    (SELECT COUNT(*) FROM cruf_recette_etape e WHERE e.recette_id = r.id) AS nb_etapes
             FROM cruf_recette r
             JOIN cruf_saveur s ON s.id = r.saveur_id
             ORDER BY s.nom ASC, r.version ASC"
        );

        $recettes = [];
        while ($row = $result->fetch_assoc()) {
            $row['id']             = (int) $row['id'];
            $row['saveur_id']      = (int) $row['saveur_id'];
            $row['version']        = (int) $row['version'];
            $row['actif']          = (int) $row['actif'];
            $row['nb_ingredients'] = (int) $row['nb_ingredients'];
            $row['nb_etapes']      = (int) $row['nb_etapes'];
            $recettes[] = $row;
        }

        echo ResponseHelper::jsonResponse('OK', 'success', $recettes);
    }

    // ── GET /recettes/:id ─────────────────────────────────────────
    // Retourne une recette complète avec ses ingrédients et étapes.
    public function getOne($id)
    {
        $id = (int) $id;

        // Recette
        $stmt = $this->mysqli->prepare(
            "SELECT r.id, r.saveur_id, r.version, r.titre, r.note, r.actif, r.created_at,
                    s.nom AS saveur_nom, s.slug AS saveur_slug
             FROM cruf_recette r
             JOIN cruf_saveur s ON s.id = r.saveur_id
             WHERE r.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $recette = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$recette) {
            echo ResponseHelper::jsonResponse('Recette introuvable.', 'error', null, 404);
            return;
        }

        $recette['id']        = (int) $recette['id'];
        $recette['saveur_id'] = (int) $recette['saveur_id'];
        $recette['version']   = (int) $recette['version'];
        $recette['actif']     = (int) $recette['actif'];

        // Ingrédients (ordonnés) — libellé depuis rp_produit + lien stock mémorisé
        $stmt2 = $this->mysqli->prepare(
            "SELECT i.id, i.produit_id, p.libelle_canonique, p.categorie,
                    i.type, i.pct_base, i.note, i.ordre,
                    sm.stock_article_id
             FROM cruf_recette_ingredient i
             JOIN rp_produit p ON p.id = i.produit_id
             LEFT JOIN cruf_stock_memoire_ingredient sm ON sm.produit_id = i.produit_id
             WHERE i.recette_id = ?
             ORDER BY i.ordre ASC, i.id ASC"
        );
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $ingredients = [];
        while ($row = $res2->fetch_assoc()) {
            $row['id']               = (int)   $row['id'];
            $row['produit_id']       = (int)   $row['produit_id'];
            $row['pct_base']         = $row['pct_base'] !== null ? (float) $row['pct_base'] : null;
            $row['ordre']            = (int)   $row['ordre'];
            $row['stock_article_id'] = $row['stock_article_id'] !== null ? (int) $row['stock_article_id'] : null;
            $ingredients[] = $row;
        }
        $stmt2->close();

        // Étapes (ordonnées)
        $stmt3 = $this->mysqli->prepare(
            "SELECT id, ordre, contenu
             FROM cruf_recette_etape
             WHERE recette_id = ?
             ORDER BY ordre ASC, id ASC"
        );
        $stmt3->bind_param('i', $id);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        $etapes = [];
        while ($row = $res3->fetch_assoc()) {
            $row['id']    = (int) $row['id'];
            $row['ordre'] = (int) $row['ordre'];
            $etapes[] = $row;
        }
        $stmt3->close();

        $recette['ingredients'] = $ingredients;
        $recette['etapes']      = $etapes;

        echo ResponseHelper::jsonResponse('OK', 'success', $recette);
    }

    // ── POST /recettes ────────────────────────────────────────────
    // Crée une nouvelle recette (version 1 pour cette saveur,
    // ou version max+1 si une recette existe déjà pour cette saveur).
    public function create($data)
    {
        $saveur_id   = (int)   ($data['saveur_id'] ?? 0);
        $titre       = trim($data['titre'] ?? '');
        $note        = (isset($data['note']) && $data['note'] !== '') ? trim($data['note']) : null;
        $ingredients = $data['ingredients'] ?? [];
        $etapes      = $data['etapes']      ?? [];

        if ($saveur_id === 0 || $titre === '') {
            echo ResponseHelper::jsonResponse('saveur_id et titre obligatoires.', 'error', null, 400);
            return;
        }

        // Calculer la prochaine version pour cette saveur
        $stmt = $this->mysqli->prepare(
            "SELECT COALESCE(MAX(version), 0) + 1 AS next_version
             FROM cruf_recette WHERE saveur_id = ?"
        );
        $stmt->bind_param('i', $saveur_id);
        $stmt->execute();
        $version = (int) $stmt->get_result()->fetch_assoc()['next_version'];
        $stmt->close();

        $actif = 1;

        // Insérer la recette
        // instructions vide string pour compatibilité colonne NOT NULL
        $instructions = '';
        $stmt2 = $this->mysqli->prepare(
            "INSERT INTO cruf_recette (saveur_id, version, titre, instructions, note, actif)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt2->bind_param('iisssi', $saveur_id, $version, $titre, $instructions, $note, $actif);

        if (!$stmt2->execute()) {
            echo ResponseHelper::jsonResponse('Erreur BDD création recette.', 'error', null, 500);
            return;
        }

        $recette_id = (int) $this->mysqli->insert_id;
        $stmt2->close();

        // Ingrédients
        $this->_saveIngredients($recette_id, $ingredients);

        // Étapes
        $this->_saveEtapes($recette_id, $etapes);

        echo ResponseHelper::jsonResponse('Recette créée.', 'success', ['id' => $recette_id], 201);
    }

    // ── POST /recettes/:id/dupliquer ──────────────────────────────
    // Crée une nouvelle version à partir d'une recette existante.
    // La recette source est conservée intacte.
    public function dupliquer($id)
    {
        $id = (int) $id;

        // Charger la recette source
        $stmt = $this->mysqli->prepare(
            "SELECT saveur_id, titre, note FROM cruf_recette WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $source = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$source) {
            echo ResponseHelper::jsonResponse('Recette source introuvable.', 'error', null, 404);
            return;
        }

        $saveur_id = (int) $source['saveur_id'];

        // Prochaine version
        $stmt2 = $this->mysqli->prepare(
            "SELECT COALESCE(MAX(version), 0) + 1 AS next_version
             FROM cruf_recette WHERE saveur_id = ?"
        );
        $stmt2->bind_param('i', $saveur_id);
        $stmt2->execute();
        $version = (int) $stmt2->get_result()->fetch_assoc()['next_version'];
        $stmt2->close();

        $titre        = $source['titre'];
        $note         = $source['note'];
        $instructions = '';
        $actif        = 1;

        $stmt3 = $this->mysqli->prepare(
            "INSERT INTO cruf_recette (saveur_id, version, titre, instructions, note, actif)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt3->bind_param('iisssi', $saveur_id, $version, $titre, $instructions, $note, $actif);

        if (!$stmt3->execute()) {
            echo ResponseHelper::jsonResponse('Erreur BDD duplication.', 'error', null, 500);
            return;
        }

        $new_id = (int) $this->mysqli->insert_id;
        $stmt3->close();

        // Copier les ingrédients
        $stmt4 = $this->mysqli->prepare(
            "SELECT produit_id, type, pct_base, note, ordre
             FROM cruf_recette_ingredient WHERE recette_id = ? ORDER BY ordre ASC"
        );
        $stmt4->bind_param('i', $id);
        $stmt4->execute();
        $res4 = $stmt4->get_result();
        $ingredients = [];
        while ($row = $res4->fetch_assoc()) {
            $ingredients[] = [
                'produit_id' => (int) $row['produit_id'],
                'type'       => $row['type'],
                'pct_base'   => $row['pct_base'] !== null ? (float) $row['pct_base'] : null,
                'note'       => $row['note'],
            ];
        }
        $stmt4->close();
        $this->_saveIngredients($new_id, $ingredients);

        // Copier les étapes
        $stmt5 = $this->mysqli->prepare(
            "SELECT contenu, ordre FROM cruf_recette_etape WHERE recette_id = ? ORDER BY ordre ASC"
        );
        $stmt5->bind_param('i', $id);
        $stmt5->execute();
        $res5 = $stmt5->get_result();
        $etapes = [];
        while ($row = $res5->fetch_assoc()) {
            $etapes[] = ['contenu' => $row['contenu']];
        }
        $stmt5->close();
        $this->_saveEtapes($new_id, $etapes);

        echo ResponseHelper::jsonResponse(
            'Nouvelle version créée.',
            'success',
            ['id' => $new_id, 'version' => $version],
            201
        );
    }

    // ── PUT /recettes/:id ─────────────────────────────────────────
    // Met à jour titre et note uniquement (pas les ingrédients/étapes).
    // Pour modifier ingrédients/étapes → dupliquer puis éditer la nouvelle version.
    public function update($id, $data)
    {
        $id    = (int)  $id;
        $titre = trim($data['titre'] ?? '');
        $note  = (isset($data['note']) && $data['note'] !== '') ? trim($data['note']) : null;

        if ($titre === '') {
            echo ResponseHelper::jsonResponse('Titre obligatoire.', 'error', null, 400);
            return;
        }

        $stmt = $this->mysqli->prepare(
            "UPDATE cruf_recette SET titre = ?, note = ? WHERE id = ?"
        );
        $stmt->bind_param('ssi', $titre, $note, $id);

        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur BDD.', 'error', null, 500);
            return;
        }

        echo ResponseHelper::jsonResponse('Recette mise à jour.', 'success');
    }

    // ── PUT /recettes/:id/complet ─────────────────────────────────
    // Sauvegarde complète : titre + note + ingrédients + étapes.
    // Écrase les ingrédients et étapes existants (DELETE + INSERT).
    // Utilisé depuis la page d'édition complète.
    public function updateComplet($id, $data)
    {
        $id          = (int)  $id;
        $titre       = trim($data['titre'] ?? '');
        $note        = (isset($data['note']) && $data['note'] !== '') ? trim($data['note']) : null;
        $ingredients = $data['ingredients'] ?? [];
        $etapes      = $data['etapes']      ?? [];

        if ($titre === '') {
            echo ResponseHelper::jsonResponse('Titre obligatoire.', 'error', null, 400);
            return;
        }

        // Vérifier que la recette existe
        $stmt = $this->mysqli->prepare("SELECT id FROM cruf_recette WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existe) {
            echo ResponseHelper::jsonResponse('Recette introuvable.', 'error', null, 404);
            return;
        }

        // Mettre à jour titre + note
        $stmt2 = $this->mysqli->prepare(
            "UPDATE cruf_recette SET titre = ?, note = ? WHERE id = ?"
        );
        $stmt2->bind_param('ssi', $titre, $note, $id);
        $stmt2->execute();
        $stmt2->close();

        // Reconstruire ingrédients
        $del1 = $this->mysqli->prepare("DELETE FROM cruf_recette_ingredient WHERE recette_id = ?");
        $del1->bind_param('i', $id);
        $del1->execute();
        $del1->close();
        $this->_saveIngredients($id, $ingredients);

        // Reconstruire étapes
        $del2 = $this->mysqli->prepare("DELETE FROM cruf_recette_etape WHERE recette_id = ?");
        $del2->bind_param('i', $id);
        $del2->execute();
        $del2->close();
        $this->_saveEtapes($id, $etapes);

        echo ResponseHelper::jsonResponse('Recette enregistrée.', 'success');
    }

    // ── DELETE /recettes/:id ──────────────────────────────────────
    // Soft delete si des lots référencent cette recette,
    // suppression physique (+ ingrédients + étapes) sinon.
    public function delete($id)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM cruf_lot WHERE recette_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $nb = (int) $stmt->get_result()->fetch_assoc()['nb'];
        $stmt->close();

        if ($nb > 0) {
            $stmt2 = $this->mysqli->prepare(
                "UPDATE cruf_recette SET actif = 0 WHERE id = ?"
            );
            $stmt2->bind_param('i', $id);
            $stmt2->execute();
            echo ResponseHelper::jsonResponse(
                'Recette désactivée (des lots y sont rattachés).',
                'success'
            );
        } else {
            // Supprimer ingrédients et étapes d'abord (FK)
            $d1 = $this->mysqli->prepare("DELETE FROM cruf_recette_ingredient WHERE recette_id = ?");
            $d1->bind_param('i', $id);
            $d1->execute();
            $d1->close();

            $d2 = $this->mysqli->prepare("DELETE FROM cruf_recette_etape WHERE recette_id = ?");
            $d2->bind_param('i', $id);
            $d2->execute();
            $d2->close();

            $d3 = $this->mysqli->prepare("DELETE FROM cruf_recette WHERE id = ?");
            $d3->bind_param('i', $id);
            $d3->execute();
            $d3->close();

            echo ResponseHelper::jsonResponse('Recette supprimée.', 'success');
        }
    }

    // ── Helpers privés ────────────────────────────────────────────

    private function _saveIngredients($recette_id, $ingredients)
    {
        if (empty($ingredients)) return;

        $stmt = $this->mysqli->prepare(
            "INSERT INTO cruf_recette_ingredient
                (recette_id, produit_id, type, pct_base, note, ordre)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($ingredients as $idx => $ing) {
            $produit_id       = (int) ($ing['produit_id'] ?? 0);
            $type             = $ing['type']  ?? 'additif';
            $pct_base         = (isset($ing['pct_base']) && $ing['pct_base'] !== null)
                                ? (float) $ing['pct_base'] : null;
            $note             = (isset($ing['note']) && $ing['note'] !== '') ? $ing['note'] : null;
            $ordre            = (int) $idx;
            $stock_article_id = (isset($ing['stock_article_id']) && $ing['stock_article_id'] !== null)
                                ? (int) $ing['stock_article_id'] : null;

            if ($produit_id === 0) continue;

            // pct_base NULL doit passer par bind_param avec type 's' en PHP 7.4
            $pct_base_bind = ($pct_base !== null) ? (string) $pct_base : null;

            $stmt->bind_param(
                'iisssi',
                $recette_id, $produit_id, $type,
                $pct_base_bind, $note, $ordre
            );
            $stmt->execute();

            // Mémoriser la liaison produit → stock si fournie
            if ($stock_article_id !== null) {
                $stmtMem = $this->mysqli->prepare(
                    "INSERT INTO cruf_stock_memoire_ingredient (produit_id, stock_article_id)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE stock_article_id = VALUES(stock_article_id),
                                             updated_at = CURRENT_TIMESTAMP"
                );
                $stmtMem->bind_param('ii', $produit_id, $stock_article_id);
                $stmtMem->execute();
                $stmtMem->close();
            }
        }

        $stmt->close();
    }

    private function _saveEtapes($recette_id, $etapes)
    {
        if (empty($etapes)) return;

        $stmt = $this->mysqli->prepare(
            "INSERT INTO cruf_recette_etape (recette_id, ordre, contenu)
             VALUES (?, ?, ?)"
        );

        foreach ($etapes as $idx => $etape) {
            $contenu = trim($etape['contenu'] ?? '');
            $ordre   = (int) $idx;

            if ($contenu === '') continue;

            $stmt->bind_param('iis', $recette_id, $ordre, $contenu);
            $stmt->execute();
        }

        $stmt->close();
    }
}