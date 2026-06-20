<?php
/**
 * DashboardTransfoController.php — /transformation
 * KPIs cross-gammes du tableau de bord global.
 * Interroge uniquement les tables transfo_* (tronc commun).
 * @php 7.4+
 */

use helpers\ResponseHelper;

class DashboardTransfoController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function getDashboard()
    {
        $annee = (int) date('Y');
        $data  = [];

        // ── KPIs globaux ──────────────────────────────────────────
        $r = $this->mysqli->query(
            "SELECT
                (SELECT COUNT(*) FROM transfo_gamme WHERE actif = 1)
                    AS gammes_actives,
                (SELECT COUNT(*) FROM transfo_lot
                 WHERE statut IN ('preparation','en_repos','production'))
                    AS lots_actifs,
                (SELECT COUNT(*) FROM transfo_lot
                 WHERE statut = 'stock' AND YEAR(date_production) = $annee)
                    AS lots_stocks_annee,
                (SELECT COUNT(*) FROM transfo_lot
                 WHERE YEAR(date_production) = $annee AND statut != 'abandonné')
                    AS lots_total_annee"
        );
        if ($r && $row = $r->fetch_assoc()) {
            $data['kpis'] = [
                'gammes_actives'   => (int) $row['gammes_actives'],
                'lots_actifs'      => (int) $row['lots_actifs'],
                'lots_stocks_annee' => (int) $row['lots_stocks_annee'],
                'lots_total_annee' => (int) $row['lots_total_annee'],
                'annee'            => $annee,
            ];
        }

        // ── Lots en cours (préparation / en_repos / production) ───
        $r2 = $this->mysqli->prepare(
            "SELECT l.id, l.numero_lot, l.date_production, l.statut,
                    g.slug AS gamme_slug, g.libelle AS gamme_libelle
             FROM transfo_lot l
             JOIN transfo_gamme g ON g.id = l.gamme_id
             WHERE l.statut IN ('preparation','en_repos','production')
             ORDER BY l.date_production DESC, l.id DESC
             LIMIT 10"
        );
        $r2->execute();
        $res = $r2->get_result();
        $lots_en_cours = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $lots_en_cours[] = $row;
        }
        $r2->close();
        $data['lots_en_cours'] = $lots_en_cours;

        // ── Dernières mises en stock ──────────────────────────────
        $r3 = $this->mysqli->prepare(
            "SELECT l.id, l.numero_lot, l.date_production,
                    g.slug AS gamme_slug, g.libelle AS gamme_libelle
             FROM transfo_lot l
             JOIN transfo_gamme g ON g.id = l.gamme_id
             WHERE l.statut = 'stock'
             ORDER BY l.date_production DESC, l.id DESC
             LIMIT 5"
        );
        $r3->execute();
        $res = $r3->get_result();
        $derniers_stocks = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $derniers_stocks[] = $row;
        }
        $r3->close();
        $data['derniers_stocks'] = $derniers_stocks;

        // ── Récap par gamme active ────────────────────────────────
        $r4 = $this->mysqli->prepare(
            "SELECT g.id, g.slug, g.libelle,
                    COUNT(DISTINCT CASE WHEN l.statut IN ('preparation','en_repos','production')
                                        THEN l.id END) AS nb_en_cours,
                    COUNT(DISTINCT CASE WHEN l.statut = 'stock'
                                         AND YEAR(l.date_production) = ?
                                        THEN l.id END) AS nb_stocks_annee,
                    COUNT(DISTINCT p.id) AS nb_produits
             FROM transfo_gamme g
             LEFT JOIN transfo_lot     l ON l.gamme_id = g.id
             LEFT JOIN transfo_produit p ON p.gamme_id = g.id AND p.actif = 1
             WHERE g.actif = 1
             GROUP BY g.id
             ORDER BY g.id ASC"
        );
        $r4->bind_param('i', $annee);
        $r4->execute();
        $res = $r4->get_result();
        $par_gamme = [];
        while ($row = $res->fetch_assoc()) {
            $row['id']              = (int) $row['id'];
            $row['nb_en_cours']     = (int) $row['nb_en_cours'];
            $row['nb_stocks_annee'] = (int) $row['nb_stocks_annee'];
            $row['nb_produits']     = (int) $row['nb_produits'];
            $par_gamme[] = $row;
        }
        $r4->close();
        $data['par_gamme'] = $par_gamme;

        echo ResponseHelper::jsonResponse('Dashboard transfo chargé.', 'success', $data);
    }
}
