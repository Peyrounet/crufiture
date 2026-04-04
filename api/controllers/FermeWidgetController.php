<?php
/**
 * FermeWidgetController.php — /crufiture
 * Fournit les KPIs au cockpit /ferme (contrat ferme-widget v1).
 * Slug doit correspondre exactement à pey_module.slug.
 * @php 7.4+
 */

use helpers\ResponseHelper;

class FermeWidgetController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function getWidget()
    {
        $kpis             = [];
        $actions_urgentes = [];

        // ── KPI 1 : nombre de lots cette année (hors abandonné) ──
        $annee = (int) date('Y');
        $stmt  = $this->mysqli->prepare(
            "SELECT COUNT(*) AS nb FROM cruf_lot
             WHERE YEAR(date_production) = ?
               AND statut != 'abandonné'"
        );
        $stmt->bind_param('i', $annee);
        $stmt->execute();
        $row   = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $kpis[] = [
            'label'   => 'Lots ' . $annee,
            'valeur'  => (int) $row['nb'],
            'unite'   => null,
            'couleur' => 'neutral',
        ];

        // ── KPI 2 : lots en production ────────────────────────────
        $r2   = $this->mysqli->query("SELECT COUNT(*) AS nb FROM cruf_lot WHERE statut = 'production'");
        $row2 = $r2->fetch_assoc();
        $nb_prod = (int) $row2['nb'];
        $kpis[] = [
            'label'   => 'En production',
            'valeur'  => $nb_prod,
            'unite'   => null,
            'couleur' => $nb_prod > 0 ? 'orange' : 'neutral',
        ];

        // ── KPI 3 : lots en repos ─────────────────────────────────
        $r3   = $this->mysqli->query("SELECT COUNT(*) AS nb FROM cruf_lot WHERE statut = 'en_repos'");
        $row3 = $r3->fetch_assoc();
        $nb_repos = (int) $row3['nb'];
        if ($nb_repos > 0) {
            $kpis[] = [
                'label'   => 'En repos',
                'valeur'  => $nb_repos,
                'unite'   => null,
                'couleur' => 'neutral',
            ];
        }

        // ── KPI 4 : stock total en jarres (kg) ────────────────────
        $r4   = $this->mysqli->query(
            "SELECT ROUND(SUM(poids_actuel_kg), 2) AS stock
             FROM cruf_jarre WHERE poids_actuel_kg > 0"
        );
        $row4  = $r4->fetch_assoc();
        $stock = $row4['stock'] !== null ? (float) $row4['stock'] : 0;
        $kpis[] = [
            'label'   => 'Stock en jarres',
            'valeur'  => $stock,
            'unite'   => 'kg',
            'couleur' => $stock < 2 ? 'orange' : 'green',
        ];

        // ── Action urgente : lots en production sans relevé depuis hier
        $hier  = date('Y-m-d', strtotime('-1 day'));
        $stmt5 = $this->mysqli->prepare(
            "SELECT l.id FROM cruf_lot l
             WHERE l.statut = 'production'
               AND (
                 SELECT MAX(rv.created_at) FROM cruf_releve_evaporation rv WHERE rv.lot_id = l.id
               ) < ?
                OR NOT EXISTS (
                 SELECT 1 FROM cruf_releve_evaporation rv WHERE rv.lot_id = l.id
               )"
        );
        $hier_dt = $hier . ' 00:00:00';
        $stmt5->bind_param('s', $hier_dt);
        $stmt5->execute();
        $r5    = $stmt5->get_result();
        $nb_sans_releve = $r5->num_rows;
        $stmt5->close();

        if ($nb_sans_releve > 0) {
            $actions_urgentes[] = [
                'label'    => $nb_sans_releve . ' lot(s) en production sans relevé depuis hier',
                'severite' => 'warning',
                'lien'     => '/crufiture/dashboard/lots',
            ];
        }

        echo ResponseHelper::jsonResponse('OK', 'success', [
            'module'           => 'crufiture',
            'libelle'          => 'Crufiture',
            'kpis'             => $kpis,
            'actions_urgentes' => $actions_urgentes,
        ]);
    }
}
