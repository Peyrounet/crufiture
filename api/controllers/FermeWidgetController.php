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

        // ── KPI 1 : nombre de lots cette année ────────────────────
        $annee = date('Y');
        $r = $this->mysqli->query(
            "SELECT COUNT(*) AS nb FROM cruf_lot
             WHERE YEAR(date_production) = $annee
               AND statut != 'archive'"
        );
        if ($r) {
            $row = $r->fetch_assoc();
            $kpis[] = [
                'label'   => 'Lots ' . $annee,
                'valeur'  => (int) $row['nb'],
                'unite'   => null,
                'couleur' => 'neutral',
            ];
        }

        // ── KPI 2 : lots en cours de production ───────────────────
        $r2 = $this->mysqli->query(
            "SELECT COUNT(*) AS nb FROM cruf_lot
             WHERE statut = 'en_production'"
        );
        if ($r2) {
            $row2  = $r2->fetch_assoc();
            $nb_en = (int) $row2['nb'];
            $kpis[] = [
                'label'   => 'En production',
                'valeur'  => $nb_en,
                'unite'   => null,
                'couleur' => $nb_en > 0 ? 'orange' : 'neutral',
            ];
        }

        // ── KPI 3 : stock total en jarres (kg) ────────────────────
        $r3 = $this->mysqli->query(
            "SELECT ROUND(SUM(poids_actuel_kg), 2) AS stock
             FROM cruf_jarre
             WHERE poids_actuel_kg > 0"
        );
        if ($r3) {
            $row3 = $r3->fetch_assoc();
            $stock = $row3['stock'] !== null ? (float) $row3['stock'] : 0;
            $kpis[] = [
                'label'   => 'Stock en jarres',
                'valeur'  => $stock,
                'unite'   => 'kg',
                'couleur' => $stock < 2 ? 'orange' : 'green',
            ];
        }

        // ── Actions urgentes : lots en_production sans relevé récent
        $hier = date('Y-m-d', strtotime('-1 day'));
        $r4 = $this->mysqli->query(
            "SELECT COUNT(*) AS nb FROM cruf_lot
             WHERE statut = 'en_production'
               AND date_production < '$hier'"
        );
        if ($r4) {
            $row4 = $r4->fetch_assoc();
            if ((int) $row4['nb'] > 0) {
                $actions_urgentes[] = [
                    'label'    => (int) $row4['nb'] . ' lot(s) en production depuis hier ou avant',
                    'severite' => 'warning',
                    'lien'     => '/crufiture/dashboard/lots',
                ];
            }
        }

        echo ResponseHelper::jsonResponse('OK', 'success', [
            'module'           => 'crufiture',
            'libelle'          => 'Crufiture',
            'kpis'             => $kpis,
            'actions_urgentes' => $actions_urgentes,
        ]);
    }
}
