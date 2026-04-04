<?php
/**
 * LotController.php — /crufiture
 * Gestion complète des lots de production.
 * Workflow : preparation → en_repos → production → stock | abandonné
 * @php 7.4+
 */

use helpers\ResponseHelper;

class LotController
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ─────────────────────────────────────────────────────────────
    // Génération du numéro de lot
    // Format : YY + séquentiel 4 chiffres, ex: 260001
    // Remis à 0001 au 1er janvier de chaque année.
    // ─────────────────────────────────────────────────────────────
    private function genererNumeroLot()
    {
        $annee2  = date('y'); // ex: "26"
        $prefixe = $annee2 . '%';

        $stmt = $this->mysqli->prepare(
            "SELECT MAX(numero_lot) AS max_num FROM cruf_lot WHERE numero_lot LIKE ?"
        );
        $stmt->bind_param('s', $prefixe);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row['max_num']) {
            $seq = (int) substr($row['max_num'], 2) + 1;
        } else {
            $seq = 1;
        }

        return $annee2 . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    // ─────────────────────────────────────────────────────────────
    // Calculs Krencker
    // Retourne un tableau de tous les résultats calculés.
    // ─────────────────────────────────────────────────────────────
    private function calculerKrencker($poids_base_kg, $brix_fruit, $brix_cible, $pa_cible, $pct_fructose)
    {
        $cible_kg        = round($poids_base_kg * 100 / $pa_cible, 4);
        $total_sucre_kg  = round($cible_kg * $brix_cible / 100, 4);
        $sucre_fruit_kg  = round($brix_fruit * $poids_base_kg / 100, 4);
        $sa_kg           = round($total_sucre_kg - $sucre_fruit_kg, 4);
        $fructose_kg     = round($sa_kg * ($pct_fructose / 100), 4);
        $saccharose_kg   = round($sa_kg * (1 - $pct_fructose / 100), 4);
        $masse_totale_kg = round($poids_base_kg + $sa_kg, 4);
        $evaporation_kg  = round($masse_totale_kg - $cible_kg, 4);

        return [
            'cible_kg'        => $cible_kg,
            'sucre_fruit_kg'  => $sucre_fruit_kg,
            'sa_kg'           => $sa_kg,
            'fructose_kg'     => $fructose_kg,
            'saccharose_kg'   => $saccharose_kg,
            'masse_totale_kg' => $masse_totale_kg,
            'evaporation_kg'  => $evaporation_kg,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // GET /lots
    // Liste tous les lots avec recherche numéro + filtre saveur.
    // Tri : production > en_repos > preparation > stock > abandonné
    // ?numero=260&saveur_id=3
    // ─────────────────────────────────────────────────────────────
    public function getAll()
    {
        $where  = [];
        $params = '';
        $vals   = [];

        if (!empty($_GET['numero'])) {
            $num     = '%' . $_GET['numero'] . '%';
            $where[] = 'l.numero_lot LIKE ?';
            $params .= 's';
            $vals[]  = $num;
        }

        if (!empty($_GET['saveur_id'])) {
            $sid     = (int) $_GET['saveur_id'];
            $where[] = 'l.saveur_id = ?';
            $params .= 'i';
            $vals[]  = $sid;
        }

        $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Tri par priorité statut puis date décroissante
        $sql = "SELECT l.id, l.numero_lot, l.saveur_id, l.recette_id,
                       l.date_production, l.installation, l.statut,
                       l.poids_brut_kg, l.poids_pulpe_kg, l.poids_base_kg,
                       l.cible_kg, l.poids_reel_kg,
                       l.evaporation_kg, l.masse_totale_kg,
                       l.fructose_kg, l.saccharose_kg,
                       s.nom AS saveur_nom,
                       r.titre AS recette_titre,
                       (SELECT COUNT(*) FROM cruf_releve_evaporation rv WHERE rv.lot_id = l.id) AS nb_releves,
                       (SELECT COUNT(*) FROM cruf_controle c WHERE c.lot_id = l.id) AS nb_controles
                FROM cruf_lot l
                JOIN cruf_saveur s ON s.id = l.saveur_id
                LEFT JOIN cruf_recette r ON r.id = l.recette_id
                $whereClause
                ORDER BY
                    FIELD(l.statut, 'production', 'en_repos', 'preparation', 'stock', 'abandonné'),
                    l.date_production DESC, l.id DESC";

        if ($params) {
            $stmt = $this->mysqli->prepare($sql);
            $ref  = [&$params];
            foreach ($vals as &$v) { $ref[] = &$v; }
            call_user_func_array([$stmt, 'bind_param'], $ref);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $this->mysqli->query($sql);
        }

        $lots = [];
        while ($row = $result->fetch_assoc()) {
            $lots[] = $this->castLotRow($row);
        }

        echo ResponseHelper::jsonResponse('OK', 'success', $lots);
    }

    // ─────────────────────────────────────────────────────────────
    // GET /lots/suivi
    // Lots en_repos et production — menu suivi + PWA mobile.
    // ─────────────────────────────────────────────────────────────
    public function getSuivi()
    {
        $result = $this->mysqli->query(
            "SELECT l.id, l.numero_lot, l.date_production, l.statut,
                    l.cible_kg, l.evaporation_kg, l.masse_totale_kg,
                    l.fructose_kg, l.saccharose_kg,
                    s.nom AS saveur_nom,
                    (SELECT rv.poids_brut_kg FROM cruf_releve_evaporation rv
                     WHERE rv.lot_id = l.id ORDER BY rv.heure DESC LIMIT 1) AS dernier_poids_net,
                    (SELECT rv.heure FROM cruf_releve_evaporation rv
                     WHERE rv.lot_id = l.id ORDER BY rv.heure DESC LIMIT 1) AS dernier_releve_heure,
                    (SELECT COUNT(*) FROM cruf_releve_evaporation rv WHERE rv.lot_id = l.id) AS nb_releves
             FROM cruf_lot l
             JOIN cruf_saveur s ON s.id = l.saveur_id
             WHERE l.statut IN ('en_repos', 'production')
             ORDER BY
                 FIELD(l.statut, 'production', 'en_repos'),
                 l.date_production DESC, l.id DESC"
        );

        $lots = [];
        while ($row = $result->fetch_assoc()) {
            $row['id']               = (int) $row['id'];
            $row['cible_kg']         = $row['cible_kg']         !== null ? (float) $row['cible_kg']         : null;
            $row['evaporation_kg']   = $row['evaporation_kg']   !== null ? (float) $row['evaporation_kg']   : null;
            $row['masse_totale_kg']  = $row['masse_totale_kg']  !== null ? (float) $row['masse_totale_kg']  : null;
            $row['fructose_kg']      = $row['fructose_kg']      !== null ? (float) $row['fructose_kg']      : null;
            $row['saccharose_kg']    = $row['saccharose_kg']    !== null ? (float) $row['saccharose_kg']    : null;
            $row['dernier_poids_net']= $row['dernier_poids_net'] !== null ? (float) $row['dernier_poids_net'] : null;
            $row['nb_releves']       = (int) $row['nb_releves'];
            $lots[] = $row;
        }

        echo ResponseHelper::jsonResponse('OK', 'success', $lots);
    }

    // ─────────────────────────────────────────────────────────────
    // GET /lots/:id
    // Fiche complète : lot + fruits (avec libellé produit) + relevés + contrôles + jarres.
    // ─────────────────────────────────────────────────────────────
    public function getOne($id)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare(
            "SELECT l.*, s.nom AS saveur_nom, s.brix_cible AS saveur_brix_cible,
                    s.pa_cible AS saveur_pa_cible, s.pct_fructose AS saveur_pct_fructose,
                    r.titre AS recette_titre, r.version AS recette_version
             FROM cruf_lot l
             JOIN cruf_saveur s ON s.id = l.saveur_id
             LEFT JOIN cruf_recette r ON r.id = l.recette_id
             WHERE l.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        $lot = $this->castLotRowFull($lot);

        // Ingrédients du lot avec libellé depuis rp_produit
        $stmt2 = $this->mysqli->prepare(
            "SELECT lf.id, lf.produit_id, lf.type, lf.pct_base,
                    lf.poids_brut_kg, lf.poids_pulpe_kg, lf.poids_base_kg,
                    lf.fournisseur, lf.origine, lf.note, lf.ordre,
                    p.libelle_canonique, p.categorie
             FROM cruf_lot_fruit lf
             JOIN rp_produit p ON p.id = lf.produit_id
             WHERE lf.lot_id = ? ORDER BY lf.ordre ASC, lf.id ASC"
        );
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $r2     = $stmt2->get_result();
        $fruits = [];
        while ($row = $r2->fetch_assoc()) {
            $row['id']            = (int)   $row['id'];
            $row['produit_id']    = (int)   $row['produit_id'];
            $row['pct_base']      = $row['pct_base']      !== null ? (float) $row['pct_base']      : null;
            $row['poids_brut_kg'] = $row['poids_brut_kg'] !== null ? (float) $row['poids_brut_kg'] : null;
            $row['poids_pulpe_kg']= $row['poids_pulpe_kg']!== null ? (float) $row['poids_pulpe_kg']: null;
            $row['poids_base_kg'] = $row['poids_base_kg'] !== null ? (float) $row['poids_base_kg'] : null;
            $row['ordre']         = (int)   $row['ordre'];
            $fruits[] = $row;
        }
        $stmt2->close();
        $lot['fruits'] = $fruits;

        // Relevés d'évaporation
        $stmt3 = $this->mysqli->prepare(
            "SELECT id, heure, poids_brut_kg, reste_evap_kg, meteo, remarque, created_at
             FROM cruf_releve_evaporation WHERE lot_id = ? ORDER BY heure ASC, id ASC"
        );
        $stmt3->bind_param('i', $id);
        $stmt3->execute();
        $r3      = $stmt3->get_result();
        $releves = [];
        while ($row = $r3->fetch_assoc()) {
            $row['id']            = (int)   $row['id'];
            $row['poids_brut_kg'] = (float) $row['poids_brut_kg'];
            $row['reste_evap_kg'] = $row['reste_evap_kg'] !== null ? (float) $row['reste_evap_kg'] : null;
            $releves[] = $row;
        }
        $stmt3->close();
        $lot['releves'] = $releves;

        // Contrôles qualité
        $stmt4 = $this->mysqli->prepare(
            "SELECT id, date_controle, type_controle, brix_mesure, aw_mesure,
                    ph_mesure, aspect, remarque, created_at
             FROM cruf_controle WHERE lot_id = ? ORDER BY date_controle ASC, id ASC"
        );
        $stmt4->bind_param('i', $id);
        $stmt4->execute();
        $r4        = $stmt4->get_result();
        $controles = [];
        while ($row = $r4->fetch_assoc()) {
            $row['id']          = (int)   $row['id'];
            $row['brix_mesure'] = $row['brix_mesure'] !== null ? (float) $row['brix_mesure'] : null;
            $row['aw_mesure']   = $row['aw_mesure']   !== null ? (float) $row['aw_mesure']   : null;
            $row['ph_mesure']   = $row['ph_mesure']   !== null ? (float) $row['ph_mesure']   : null;
            $controles[] = $row;
        }
        $stmt4->close();
        $lot['controles'] = $controles;

        // Jarres
        $stmt5 = $this->mysqli->prepare(
            "SELECT id, numero, poids_initial_kg, poids_actuel_kg, note, created_at
             FROM cruf_jarre WHERE lot_id = ? ORDER BY numero ASC"
        );
        $stmt5->bind_param('i', $id);
        $stmt5->execute();
        $r5     = $stmt5->get_result();
        $jarres = [];
        while ($row = $r5->fetch_assoc()) {
            $row['id']               = (int)   $row['id'];
            $row['numero']           = (int)   $row['numero'];
            $row['poids_initial_kg'] = (float) $row['poids_initial_kg'];
            $row['poids_actuel_kg']  = $row['poids_actuel_kg'] !== null ? (float) $row['poids_actuel_kg'] : null;
            $jarres[] = $row;
        }
        $stmt5->close();
        $lot['jarres'] = $jarres;

        echo ResponseHelper::jsonResponse('OK', 'success', $lot);
    }

    // ─────────────────────────────────────────────────────────────
    // GET /lots/:id/rendements
    // Rendements historiques de la saveur pour le calcul à rebours.
    // Requiert >= 1 lot en stock avec poids_reel_kg renseigné.
    // ─────────────────────────────────────────────────────────────
    public function getRendements($id)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare("SELECT saveur_id FROM cruf_lot WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        $saveur_id = (int) $row['saveur_id'];

        $stmt2 = $this->mysqli->prepare(
            "SELECT id, numero_lot, date_production,
                    poids_brut_kg, poids_pulpe_kg, poids_base_kg, poids_reel_kg,
                    (poids_pulpe_kg / poids_brut_kg) AS rdt_brut_pulpe,
                    (poids_reel_kg  / poids_base_kg) AS rdt_pulpe_cruf
             FROM cruf_lot
             WHERE saveur_id = ?
               AND id != ?
               AND statut = 'stock'
               AND poids_reel_kg IS NOT NULL
               AND poids_brut_kg > 0
               AND poids_pulpe_kg > 0
               AND poids_base_kg > 0
             ORDER BY date_production DESC"
        );
        $stmt2->bind_param('ii', $saveur_id, $id);
        $stmt2->execute();
        $r2         = $stmt2->get_result();
        $historique = [];
        while ($row2 = $r2->fetch_assoc()) {
            $row2['id']             = (int)   $row2['id'];
            $row2['poids_brut_kg']  = (float) $row2['poids_brut_kg'];
            $row2['poids_pulpe_kg'] = (float) $row2['poids_pulpe_kg'];
            $row2['poids_base_kg']  = (float) $row2['poids_base_kg'];
            $row2['poids_reel_kg']  = (float) $row2['poids_reel_kg'];
            $row2['rdt_brut_pulpe'] = (float) $row2['rdt_brut_pulpe'];
            $row2['rdt_pulpe_cruf'] = (float) $row2['rdt_pulpe_cruf'];
            $historique[] = $row2;
        }
        $stmt2->close();

        if (empty($historique)) {
            echo ResponseHelper::jsonResponse('Pas encore de lots précédents pour cette saveur.', 'success', [
                'disponible'     => false,
                'nb_lots'        => 0,
                'rdt_brut_pulpe' => null,
                'rdt_pulpe_cruf' => null,
            ]);
            return;
        }

        $avg_rdt_brut = array_sum(array_column($historique, 'rdt_brut_pulpe')) / count($historique);
        $avg_rdt_cruf = array_sum(array_column($historique, 'rdt_pulpe_cruf')) / count($historique);

        echo ResponseHelper::jsonResponse('OK', 'success', [
            'disponible'     => true,
            'nb_lots'        => count($historique),
            'rdt_brut_pulpe' => round($avg_rdt_brut, 4),
            'rdt_pulpe_cruf' => round($avg_rdt_cruf, 4),
            'historique'     => $historique,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // POST /lots
    // Créer un lot — bloc 1 uniquement.
    // Génère le numéro, statut preparation.
    // Champs obligatoires : saveur_id, recette_id, date_production
    // Les poids et Krencker sont initialisés à 0 — mis à jour via PUT.
    // ─────────────────────────────────────────────────────────────
    public function create($data)
    {
        if (empty($data['saveur_id'])) {
            echo ResponseHelper::jsonResponse('saveur_id est obligatoire.', 'error', null, 422);
            return;
        }
        if (empty($data['recette_id'])) {
            echo ResponseHelper::jsonResponse('recette_id est obligatoire.', 'error', null, 422);
            return;
        }
        if (empty($data['date_production'])) {
            echo ResponseHelper::jsonResponse('date_production est obligatoire.', 'error', null, 422);
            return;
        }

        $numero_lot   = $this->genererNumeroLot();
        $saveur_id    = (int) $data['saveur_id'];
        $recette_id   = (int) $data['recette_id'];
        $date_prod    = $data['date_production'];
        $installation = isset($data['installation']) ? $data['installation'] : null;

        // Lire les valeurs par défaut de la saveur pour pré-remplir
        $stmt_sav = $this->mysqli->prepare(
            "SELECT brix_cible, pa_cible, pct_fructose FROM cruf_saveur WHERE id = ?"
        );
        $stmt_sav->bind_param('i', $saveur_id);
        $stmt_sav->execute();
        $saveur = $stmt_sav->get_result()->fetch_assoc();
        $stmt_sav->close();

        if (!$saveur) {
            echo ResponseHelper::jsonResponse('Saveur introuvable.', 'error', null, 404);
            return;
        }

        $brix_cible   = (float) $saveur['brix_cible'];
        $pa_cible     = (float) $saveur['pa_cible'];
        $pct_fructose = (float) $saveur['pct_fructose'];

        // À la création bloc 1, les poids et brix_fruit sont inconnus — on stocke 0
        // Ils seront mis à jour progressivement via PUT /lots/:id
        $zero = 0.0;

        $stmt = $this->mysqli->prepare(
            "INSERT INTO cruf_lot
             (numero_lot, saveur_id, recette_id, date_production, installation,
              poids_brut_kg, poids_pulpe_kg, poids_base_kg,
              brix_fruit, brix_cible, pct_fructose, pa_cible,
              statut)
             VALUES (?,?,?,?,?, ?,?,?, ?,?,?,?, 'preparation')"
        );

        $stmt->bind_param(
            'siissddddddd',
            $numero_lot, $saveur_id, $recette_id, $date_prod, $installation,
            $zero, $zero, $zero,
            $zero, $brix_cible, $pct_fructose, $pa_cible
        );

        if (!$stmt->execute()) {
            echo ResponseHelper::jsonResponse('Erreur lors de la création du lot.', 'error', null, 500);
            return;
        }

        $lot_id = $stmt->insert_id;
        $stmt->close();

        echo ResponseHelper::jsonResponse('Lot créé.', 'success', [
            'id'         => $lot_id,
            'numero_lot' => $numero_lot,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────
    // PUT /lots/:id
    // Sauvegarder la fiche (blocs 1 à 4).
    // Autorisé uniquement en statut preparation et en_repos.
    // Recalcule Krencker si les paramètres nécessaires sont présents.
    // ─────────────────────────────────────────────────────────────
    public function update($id, $data)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare(
            "SELECT statut, poids_base_kg, brix_fruit, brix_cible, pct_fructose, pa_cible,
                    poids_pulpe_kg
             FROM cruf_lot WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        if (!in_array($lot['statut'], ['preparation', 'en_repos'])) {
            echo ResponseHelper::jsonResponse('La fiche est verrouillée (statut : ' . $lot['statut'] . ').', 'error', null, 422);
            return;
        }

        $sets   = [];
        $params = '';
        $vals   = [];

        // En en_repos : seuls les paramètres Krencker sont modifiables
        if ($lot['statut'] === 'en_repos') {
            $fields_simple = [
                'brix_fruit'     => 'd',
                'brix_cible'     => 'd',
                'pct_fructose'   => 'd',
                'pa_cible'       => 'd',
                'note_production'=> 's',
            ];
        } else {
            // En preparation : tous les champs sont modifiables
            $fields_simple = [
                'saveur_id'      => 'i',
                'recette_id'     => 'i',
                'date_production'=> 's',
                'installation'   => 's',
                'brix_fruit'     => 'd',
                'brix_cible'     => 'd',
                'pct_fructose'   => 'd',
                'pa_cible'       => 'd',
                'note_production'=> 's',
            ];
        }

        foreach ($fields_simple as $f => $t) {
            if (array_key_exists($f, $data)) {
                $sets[]  = "$f = ?";
                $params .= $t;
                $vals[]  = ($data[$f] === '' ? null : $data[$f]);
            }
        }

        // Poids totaux (uniquement en preparation)
        if ($lot['statut'] === 'preparation') {
            $poids_fields = [
                'poids_brut_kg'  => 'd',
                'poids_pulpe_kg' => 'd',
                'poids_base_kg'  => 'd',
            ];
            foreach ($poids_fields as $f => $t) {
                if (array_key_exists($f, $data)) {
                    $sets[]  = "$f = ?";
                    $params .= $t;
                    $vals[]  = (float) $data[$f];
                }
            }
        }

        // Recalcul Krencker si on a les paramètres nécessaires
        $champs_krencker = ['poids_base_kg', 'brix_fruit', 'brix_cible', 'pct_fructose', 'pa_cible'];
        $has_krencker    = false;
        foreach ($champs_krencker as $k) {
            if (isset($data[$k])) { $has_krencker = true; break; }
        }

        if ($has_krencker) {
            // Fusionner avec les valeurs courantes pour les champs non fournis
            $base         = (float) (array_key_exists('poids_base_kg', $data) ? $data['poids_base_kg'] : $lot['poids_base_kg']);
            $brix_fruit   = (float) (array_key_exists('brix_fruit',    $data) ? $data['brix_fruit']    : $lot['brix_fruit']);
            $brix_cible   = (float) (array_key_exists('brix_cible',    $data) ? $data['brix_cible']    : $lot['brix_cible']);
            $pa_cible     = (float) (array_key_exists('pa_cible',      $data) ? $data['pa_cible']      : $lot['pa_cible']);
            $pct_fructose = (float) (array_key_exists('pct_fructose',  $data) ? $data['pct_fructose']  : $lot['pct_fructose']);

            // Ne calculer que si les valeurs sont cohérentes (base > 0, brix_fruit < brix_cible)
            if ($base > 0 && isset($brix_fruit) && $brix_fruit < $brix_cible) {
                $k = $this->calculerKrencker($base, $brix_fruit, $brix_cible, $pa_cible, $pct_fructose);
                $krencker_fields = ['sucre_fruit_kg','sa_kg','fructose_kg','saccharose_kg','masse_totale_kg','evaporation_kg','cible_kg'];
                foreach ($krencker_fields as $kf) {
                    $sets[]  = "$kf = ?";
                    $params .= 'd';
                    $vals[]  = $k[$kf];
                }
            }
        }

        if (!empty($sets)) {
            $params .= 'i';
            $vals[]  = $id;
            $sql     = "UPDATE cruf_lot SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt    = $this->mysqli->prepare($sql);
            $ref     = [&$params];
            foreach ($vals as &$v) { $ref[] = &$v; }
            call_user_func_array([$stmt, 'bind_param'], $ref);
            $stmt->execute();
            $stmt->close();
        }

        // Fruits (remplace tout si fournis — uniquement en preparation)
        if ($lot['statut'] === 'preparation' && isset($data['fruits']) && is_array($data['fruits'])) {
            $this->saveFruits($id, $data['fruits']);
        }

        echo ResponseHelper::jsonResponse('Lot mis à jour.', 'success', ['id' => $id]);
    }

    // ─────────────────────────────────────────────────────────────
    // PUT /lots/:id/mettre-en-repos
    // Transition preparation → en_repos.
    // Requiert : bloc 4 complet, brix_fruit renseigné,
    //            poids_base_kg <= poids_pulpe_kg, brix_fruit < brix_cible.
    // ─────────────────────────────────────────────────────────────
    public function mettreEnRepos($id)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare(
            "SELECT statut, poids_base_kg, poids_pulpe_kg, brix_fruit, brix_cible, cible_kg
             FROM cruf_lot WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        if ($lot['statut'] !== 'preparation') {
            echo ResponseHelper::jsonResponse('Seuls les lots en préparation peuvent être mis en repos.', 'error', null, 422);
            return;
        }

        if ($lot['brix_fruit'] === null || $lot['brix_fruit'] === '') {
            echo ResponseHelper::jsonResponse('Le Brix du fruit doit être renseigné.', 'error', null, 422);
            return;
        }

        if ((float) $lot['poids_base_kg'] <= 0) {
            echo ResponseHelper::jsonResponse('Les poids doivent être renseignés (bloc 2 et 3 incomplets).', 'error', null, 422);
            return;
        }

        if ((float) $lot['poids_base_kg'] > (float) $lot['poids_pulpe_kg']) {
            echo ResponseHelper::jsonResponse('poids_base_kg ne peut pas être supérieur à poids_pulpe_kg.', 'error', null, 422);
            return;
        }

        if ((float) $lot['brix_fruit'] >= (float) $lot['brix_cible']) {
            echo ResponseHelper::jsonResponse('Le Brix du fruit doit être inférieur au Brix cible.', 'error', null, 422);
            return;
        }

        $stmt2 = $this->mysqli->prepare(
            "UPDATE cruf_lot SET statut = 'en_repos' WHERE id = ?"
        );
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $stmt2->close();

        echo ResponseHelper::jsonResponse('Lot mis en repos.', 'success', ['id' => $id, 'statut' => 'en_repos']);
    }

    // ─────────────────────────────────────────────────────────────
    // PUT /lots/:id/demarrer
    // Transition en_repos → production.
    // Verrouille définitivement la fiche.
    // ─────────────────────────────────────────────────────────────
    public function demarrer($id)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare(
            "SELECT statut FROM cruf_lot WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        if ($lot['statut'] !== 'en_repos') {
            echo ResponseHelper::jsonResponse('Seuls les lots en repos peuvent être démarrés.', 'error', null, 422);
            return;
        }

        $now   = date('H:i:s');
        $stmt2 = $this->mysqli->prepare(
            "UPDATE cruf_lot SET statut = 'production', heure_debut = ? WHERE id = ?"
        );
        $stmt2->bind_param('si', $now, $id);
        $stmt2->execute();
        $stmt2->close();

        echo ResponseHelper::jsonResponse('Production démarrée.', 'success', ['id' => $id, 'statut' => 'production']);
    }

    // ─────────────────────────────────────────────────────────────
    // PUT /lots/:id/stocker
    // Transition production → stock.
    // Requiert : >= 1 relevé avec poids net <= cible_kg
    //            + >= 1 contrôle qualité
    //            + poids_reel_kg + jarres dans $data.
    // ─────────────────────────────────────────────────────────────
    public function stocker($id, $data)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare("SELECT statut, cible_kg FROM cruf_lot WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        if ($lot['statut'] !== 'production') {
            echo ResponseHelper::jsonResponse('Ce lot n\'est pas en production.', 'error', null, 422);
            return;
        }

        // Vérifier >= 1 relevé avec poids net <= cible
        $cible    = (float) $lot['cible_kg'];
        $stmt2    = $this->mysqli->prepare(
            "SELECT COUNT(*) AS n FROM cruf_releve_evaporation WHERE lot_id = ? AND poids_brut_kg <= ?"
        );
        $stmt2->bind_param('id', $id, $cible);
        $stmt2->execute();
        $cnt = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        if ((int) $cnt['n'] === 0) {
            echo ResponseHelper::jsonResponse('Aucun relevé n\'indique que le poids cible est atteint.', 'error', null, 422);
            return;
        }

        // Vérifier >= 1 contrôle qualité existant OU fourni dans $data
        $stmt_ctrl = $this->mysqli->prepare("SELECT COUNT(*) AS n FROM cruf_controle WHERE lot_id = ?");
        $stmt_ctrl->bind_param('i', $id);
        $stmt_ctrl->execute();
        $cnt_ctrl = $stmt_ctrl->get_result()->fetch_assoc();
        $stmt_ctrl->close();

        $has_controle = ((int) $cnt_ctrl['n'] > 0) || !empty($data['controle']);
        if (!$has_controle) {
            echo ResponseHelper::jsonResponse('Un contrôle qualité est obligatoire pour passer en stock.', 'error', null, 422);
            return;
        }

        if (empty($data['poids_reel_kg'])) {
            echo ResponseHelper::jsonResponse('Le poids réel mis en pot est obligatoire.', 'error', null, 422);
            return;
        }

        if (empty($data['jarres']) || !is_array($data['jarres']) || count($data['jarres']) === 0) {
            echo ResponseHelper::jsonResponse('Au moins une jarre doit être renseignée.', 'error', null, 422);
            return;
        }

        $poids_reel = (float) $data['poids_reel_kg'];
        $heure_pot  = date('H:i:s');

        $stmt3 = $this->mysqli->prepare(
            "UPDATE cruf_lot SET statut = 'stock', poids_reel_kg = ?, heure_mise_pot = ? WHERE id = ?"
        );
        $stmt3->bind_param('dsi', $poids_reel, $heure_pot, $id);
        $stmt3->execute();
        $stmt3->close();

        // Jarres (max 3)
        $num = 1;
        foreach ($data['jarres'] as $jarre) {
            if ($num > 3) break;
            $poids_init = (float) $jarre['poids_initial_kg'];
            $note_j     = isset($jarre['note']) ? $jarre['note'] : null;
            $stmtJ      = $this->mysqli->prepare(
                "INSERT INTO cruf_jarre (lot_id, numero, poids_initial_kg, poids_actuel_kg, note)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmtJ->bind_param('iidds', $id, $num, $poids_init, $poids_init, $note_j);
            $stmtJ->execute();
            $stmtJ->close();
            $num++;
        }

        // Contrôle qualité si fourni avec la mise en stock
        if (!empty($data['controle'])) {
            $this->insertControle($id, $data['controle'], 'mise_en_pot');
        }

        echo ResponseHelper::jsonResponse('Lot passé en stock.', 'success', ['id' => $id, 'statut' => 'stock']);
    }

    // ─────────────────────────────────────────────────────────────
    // PUT /lots/:id/abandonner
    // Possible depuis preparation, en_repos, production.
    // Note obligatoire.
    // ─────────────────────────────────────────────────────────────
    public function abandonner($id, $data)
    {
        $id = (int) $id;

        if (empty($data['note'])) {
            echo ResponseHelper::jsonResponse('Une note est obligatoire pour abandonner un lot.', 'error', null, 422);
            return;
        }

        $stmt = $this->mysqli->prepare("SELECT statut FROM cruf_lot WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        if ($lot['statut'] === 'stock' || $lot['statut'] === 'abandonné') {
            echo ResponseHelper::jsonResponse('Un lot en stock ou déjà abandonné ne peut pas être abandonné.', 'error', null, 422);
            return;
        }

        $note  = $data['note'];
        $stmt2 = $this->mysqli->prepare(
            "UPDATE cruf_lot SET statut = 'abandonné', note_production = ? WHERE id = ?"
        );
        $stmt2->bind_param('si', $note, $id);
        $stmt2->execute();
        $stmt2->close();

        echo ResponseHelper::jsonResponse('Lot abandonné.', 'success', ['id' => $id, 'statut' => 'abandonné']);
    }

    // ─────────────────────────────────────────────────────────────
    // POST /lots/:id/releves
    // Ajouter un relevé de pesée (statut production uniquement).
    // poids_brut_kg reçu = poids NET (tare déjà déduite côté frontend).
    // ─────────────────────────────────────────────────────────────
    public function addReleve($id, $data)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare("SELECT statut, cible_kg FROM cruf_lot WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        if ($lot['statut'] !== 'production') {
            echo ResponseHelper::jsonResponse('Les relevés ne sont possibles que sur un lot en production.', 'error', null, 422);
            return;
        }

        if (!isset($data['poids_brut_kg']) || $data['poids_brut_kg'] === '') {
            echo ResponseHelper::jsonResponse('poids_brut_kg est obligatoire.', 'error', null, 422);
            return;
        }

        $heure      = isset($data['heure'])    ? $data['heure']    : date('H:i:s');
        $poids_net  = (float) $data['poids_brut_kg']; // poids net = tare déjà déduite
        $cible      = (float) $lot['cible_kg'];
        $reste_evap = round($poids_net - $cible, 3);
        $meteo      = isset($data['meteo'])    ? $data['meteo']    : null;
        $remarque   = isset($data['remarque']) ? $data['remarque'] : null;

        $stmt2 = $this->mysqli->prepare(
            "INSERT INTO cruf_releve_evaporation (lot_id, heure, poids_brut_kg, reste_evap_kg, meteo, remarque)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt2->bind_param('isddss', $id, $heure, $poids_net, $reste_evap, $meteo, $remarque);
        $stmt2->execute();
        $releve_id = $stmt2->insert_id;
        $stmt2->close();

        echo ResponseHelper::jsonResponse('Relevé enregistré.', 'success', [
            'id'             => $releve_id,
            'poids_net'      => $poids_net,
            'reste_evap_kg'  => $reste_evap,
            'cible_atteinte' => ($poids_net <= $cible),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────
    // POST /lots/:id/controles
    // Ajouter un contrôle qualité (possible en production et stock).
    // ─────────────────────────────────────────────────────────────
    public function addControle($id, $data)
    {
        $id = (int) $id;

        $stmt = $this->mysqli->prepare("SELECT id, statut FROM cruf_lot WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lot) {
            echo ResponseHelper::jsonResponse('Lot introuvable.', 'error', null, 404);
            return;
        }

        if (!in_array($lot['statut'], ['production', 'stock'])) {
            echo ResponseHelper::jsonResponse('Les contrôles ne sont possibles qu\'en production ou en stock.', 'error', null, 422);
            return;
        }

        $this->insertControle($id, $data, 'suivi');
        echo ResponseHelper::jsonResponse('Contrôle enregistré.', 'success', ['lot_id' => $id], 201);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers privés
    // ─────────────────────────────────────────────────────────────

    // Remplace tous les fruits d'un lot par ceux fournis.
    private function saveFruits($lot_id, $fruits)
    {
        $del = $this->mysqli->prepare("DELETE FROM cruf_lot_fruit WHERE lot_id = ?");
        $del->bind_param('i', $lot_id);
        $del->execute();
        $del->close();

        $ordre = 0;
        foreach ($fruits as $f) {
            if (empty($f['produit_id'])) continue; // produit_id obligatoire

            $produit_id   = (int)    $f['produit_id'];
            $type         = isset($f['type'])         ? $f['type']         : 'additif';
            $pct_base     = isset($f['pct_base'])     && $f['pct_base']     !== '' ? (float) $f['pct_base']     : null;
            $poids_brut   = isset($f['poids_brut_kg'])&& $f['poids_brut_kg']!== '' ? (float) $f['poids_brut_kg']: null;
            $poids_pulpe  = isset($f['poids_pulpe_kg'])&&$f['poids_pulpe_kg']!=='' ? (float) $f['poids_pulpe_kg']: null;
            $poids_base   = isset($f['poids_base_kg'])&& $f['poids_base_kg']!== '' ? (float) $f['poids_base_kg']: null;
            $fournisseur  = isset($f['fournisseur'])  ? $f['fournisseur']  : null;
            $origine      = isset($f['origine'])      ? $f['origine']      : null;
            $note_f       = isset($f['note'])         ? $f['note']         : null;

            $stmt = $this->mysqli->prepare(
                "INSERT INTO cruf_lot_fruit
                 (lot_id, produit_id, type, pct_base, poids_brut_kg, poids_pulpe_kg, poids_base_kg,
                  fournisseur, origine, note, ordre)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'iisddddsssi',
                $lot_id, $produit_id, $type, $pct_base, $poids_brut, $poids_pulpe, $poids_base,
                $fournisseur, $origine, $note_f, $ordre
            );
            $stmt->execute();
            $stmt->close();
            $ordre++;
        }
    }

    private function insertControle($lot_id, $data, $type_defaut)
    {
        $date_ctrl   = isset($data['date_controle']) && $data['date_controle'] ? $data['date_controle'] : date('Y-m-d');
        $type_ctrl   = isset($data['type_controle']) ? $data['type_controle'] : $type_defaut;
        $brix_mesure = isset($data['brix_mesure']) && $data['brix_mesure'] !== '' ? (float) $data['brix_mesure'] : null;
        $aw_mesure   = isset($data['aw_mesure'])   && $data['aw_mesure']   !== '' ? (float) $data['aw_mesure']   : null;
        $ph_mesure   = isset($data['ph_mesure'])   && $data['ph_mesure']   !== '' ? (float) $data['ph_mesure']   : null;
        $aspect      = isset($data['aspect'])      ? $data['aspect']      : null;
        $remarque_c  = isset($data['remarque'])    ? $data['remarque']    : null;

        $stmt = $this->mysqli->prepare(
            "INSERT INTO cruf_controle (lot_id, date_controle, type_controle, brix_mesure, aw_mesure, ph_mesure, aspect, remarque)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issdddss', $lot_id, $date_ctrl, $type_ctrl, $brix_mesure, $aw_mesure, $ph_mesure, $aspect, $remarque_c);
        $stmt->execute();
        $stmt->close();
    }

    private function castLotRow($row)
    {
        $row['id']              = (int)   $row['id'];
        $row['saveur_id']       = (int)   $row['saveur_id'];
        $row['recette_id']      = $row['recette_id']      !== null ? (int)   $row['recette_id']      : null;
        $row['poids_brut_kg']   = (float) $row['poids_brut_kg'];
        $row['poids_pulpe_kg']  = (float) $row['poids_pulpe_kg'];
        $row['poids_base_kg']   = (float) $row['poids_base_kg'];
        $row['cible_kg']        = $row['cible_kg']        !== null ? (float) $row['cible_kg']        : null;
        $row['poids_reel_kg']   = $row['poids_reel_kg']   !== null ? (float) $row['poids_reel_kg']   : null;
        $row['evaporation_kg']  = $row['evaporation_kg']  !== null ? (float) $row['evaporation_kg']  : null;
        $row['masse_totale_kg'] = $row['masse_totale_kg'] !== null ? (float) $row['masse_totale_kg'] : null;
        $row['fructose_kg']     = isset($row['fructose_kg'])    && $row['fructose_kg']    !== null ? (float) $row['fructose_kg']    : null;
        $row['saccharose_kg']   = isset($row['saccharose_kg'])  && $row['saccharose_kg']  !== null ? (float) $row['saccharose_kg']  : null;
        $row['nb_releves']      = isset($row['nb_releves'])  ? (int) $row['nb_releves']  : 0;
        $row['nb_controles']    = isset($row['nb_controles'])? (int) $row['nb_controles'] : 0;
        return $row;
    }

    private function castLotRowFull($row)
    {
        $row = $this->castLotRow($row);
        $floats = [
            'brix_fruit','brix_cible','pct_fructose','pa_cible',
            'sucre_fruit_kg','sa_kg','fructose_kg','saccharose_kg',
            'saveur_brix_cible','saveur_pa_cible','saveur_pct_fructose',
        ];
        foreach ($floats as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = $row[$f] !== null ? (float) $row[$f] : null;
            }
        }
        if (isset($row['recette_version'])) {
            $row['recette_version'] = $row['recette_version'] !== null ? (int) $row['recette_version'] : null;
        }
        return $row;
    }
}
