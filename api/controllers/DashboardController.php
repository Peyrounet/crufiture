<?php
/**
 * DashboardController.php — /crufiture
 * KPIs du tableau de bord admin.
 * @php 7.4+
 */

use helpers\ResponseHelper;

class DashboardController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function getDashboard()
    {
        $annee = date('Y');
        $data  = [];

        // ── Lots de l'année ───────────────────────────────────────
        $r = $this->mysqli->query(
            "SELECT
                COUNT(*) AS nb_lots,
                COALESCE(SUM(poids_reel_kg), 0) AS kg_produits
             FROM cruf_lot
             WHERE YEAR(date_production) = $annee"
        );
        if ($r) {
            $row = $r->fetch_assoc();
            $data['annee'] = [
                'nb_lots'     => (int)   $row['nb_lots'],
                'kg_produits' => (float) $row['kg_produits'],
            ];
        }

        // ── Répartition par statut ────────────────────────────────
        $r2 = $this->mysqli->query(
            "SELECT statut, COUNT(*) AS nb
             FROM cruf_lot
             GROUP BY statut"
        );
        $statuts = [];
        if ($r2) {
            while ($row = $r2->fetch_assoc()) {
                $statuts[$row['statut']] = (int) $row['nb'];
            }
        }
        $data['statuts'] = $statuts;

        // ── Stock total en jarres ─────────────────────────────────
        $r3 = $this->mysqli->query(
            "SELECT COALESCE(ROUND(SUM(poids_actuel_kg), 3), 0) AS stock_kg
             FROM cruf_jarre"
        );
        if ($r3) {
            $row3 = $r3->fetch_assoc();
            $data['stock_kg'] = (float) $row3['stock_kg'];
        }

        // ── Derniers lots (5) ─────────────────────────────────────
        $r4 = $this->mysqli->query(
            "SELECT l.id, l.numero_lot, l.date_production, l.statut,
                    l.poids_reel_kg, s.nom AS saveur
             FROM cruf_lot l
             JOIN cruf_saveur s ON s.id = l.saveur_id
             ORDER BY l.date_production DESC, l.id DESC
             LIMIT 5"
        );
        $derniers = [];
        if ($r4) {
            while ($row = $r4->fetch_assoc()) {
                $row['id']           = (int)   $row['id'];
                $row['poids_reel_kg'] = $row['poids_reel_kg'] !== null
                    ? (float) $row['poids_reel_kg'] : null;
                $derniers[] = $row;
            }
        }
        $data['derniers_lots'] = $derniers;

        echo ResponseHelper::jsonResponse('Dashboard chargé.', 'success', $data);
    }
}
