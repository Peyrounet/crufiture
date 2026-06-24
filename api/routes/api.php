<?php
/**
 * routes/api.php — Router principal /crufiture
 * @php 7.4+
 */

require_once './controllers/PingController.php';
require_once './controllers/FermeWidgetController.php';
require_once './controllers/DashboardController.php';
require_once './controllers/DashboardTransfoController.php';
require_once './controllers/GammeController.php';
require_once './controllers/ProduitTransfoController.php';
require_once './controllers/SaveurController.php';
require_once './controllers/RecetteController.php';
require_once './controllers/LotController.php';
require_once './controllers/RecetteTransfoController.php';
require_once './controllers/LotMaceAlcoolController.php';

use helpers\ResponseHelper;

$mysqli = (new Database())->getConnection();

$pingCtrl             = new PingController();
$widgetCtrl           = new FermeWidgetController($mysqli);
$dashboardCtrl        = new DashboardController($mysqli);
$dashboardTransfoCtrl = new DashboardTransfoController($mysqli);
$gammeCtrl            = new GammeController($mysqli);
$produitTransfoCtrl   = new ProduitTransfoController($mysqli);
$saveurCtrl           = new SaveurController($mysqli);
$recetteCtrl          = new RecetteController($mysqli);
$lotCtrl              = new LotController($mysqli);
$recetteTransfoCtrl   = new RecetteTransfoController($mysqli);
$lotMaceAlcoolCtrl    = new LotMaceAlcoolController($mysqli);

$prefix = $_ENV['CRUFITURE_FOLDER'] ?? '/crufiture';
$api    = $prefix . '/api';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($method) {

    case 'GET':
        if ($uri === $api . '/ping') {
            $pingCtrl->ping();

        } elseif ($uri === $api . '/ferme-widget') {
            $widgetCtrl->getWidget();

        } elseif ($uri === $api . '/dashboard') {
            $dashboardCtrl->getDashboard();

        } elseif ($uri === $api . '/dashboard-transfo') {
            $dashboardTransfoCtrl->getDashboard();

        } elseif ($uri === $api . '/gammes') {
            $gammeCtrl->getAll();

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/gammes/(\d+)/produits$#', $uri, $m)) {
            $produitTransfoCtrl->getAllByGamme($m[1]);

        } elseif ($uri === $api . '/saveurs') {
            $saveurCtrl->getAll();

        } elseif ($uri === $api . '/recettes') {
            $recetteCtrl->getAll();

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)$#', $uri, $m)) {
            $recetteCtrl->getOne($m[1]);

        } elseif ($uri === $api . '/lots/suivi') {
            // Lots en_repos et production — menu suivi + PWA mobile
            $lotCtrl->getSuivi();

        } elseif ($uri === $api . '/lots') {
            $lotCtrl->getAll();

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)/rendements$#', $uri, $m)) {
            $lotCtrl->getRendements($m[1]);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)$#', $uri, $m)) {
            $lotCtrl->getOne($m[1]);

        // ── Recettes tronc commun ─────────────────────────────────
        } elseif ($uri === $api . '/recettes-transfo/version') {
            $recetteTransfoCtrl->getVersion();

        } elseif ($uri === $api . '/recettes-transfo/export-pdf') {
            $recetteTransfoCtrl->exportPdf();

        } elseif ($uri === $api . '/recettes-transfo') {
            $recetteTransfoCtrl->getRecettes();

        // ── Lots macération alcoolique ────────────────────────────
        } elseif ($uri === $api . '/mace-alcool/lots') {
            $lotMaceAlcoolCtrl->getAll();

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->getOne($m[1]);

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($uri === $api . '/gammes') {
            $gammeCtrl->create($data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/gammes/(\d+)/produits$#', $uri, $m)) {
            $produitTransfoCtrl->create($m[1], $data);

        } elseif ($uri === $api . '/saveurs') {
            $saveurCtrl->create($data);

        } elseif ($uri === $api . '/recettes') {
            $recetteCtrl->create($data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)/dupliquer$#', $uri, $m)) {
            $recetteCtrl->dupliquer($m[1]);

        } elseif ($uri === $api . '/lots') {
            $lotCtrl->create($data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)/releves$#', $uri, $m)) {
            $lotCtrl->addReleve($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)/controles$#', $uri, $m)) {
            $lotCtrl->addControle($m[1], $data);

        // ── Recettes tronc commun ─────────────────────────────────
        } elseif ($uri === $api . '/recettes-transfo') {
            $recetteTransfoCtrl->creerRecette();

        } elseif ($uri === $api . '/recettes-transfo/dupliquer') {
            $recetteTransfoCtrl->dupliquerVersion();

        // ── Lots macération alcoolique ────────────────────────────
        } elseif ($uri === $api . '/mace-alcool/lots') {
            $lotMaceAlcoolCtrl->create($data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/controles$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->addControle($m[1], $data);

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (preg_match('#^' . preg_quote($api, '#') . '/gammes/(\d+)$#', $uri, $m)) {
            $gammeCtrl->update($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/gammes/(\d+)/produits/(\d+)$#', $uri, $m)) {
            $produitTransfoCtrl->update($m[1], $m[2], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/saveurs/(\d+)$#', $uri, $m)) {
            $saveurCtrl->update($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)/complet$#', $uri, $m)) {
            $recetteCtrl->updateComplet($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)$#', $uri, $m)) {
            $recetteCtrl->update($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)/mettre-en-repos$#', $uri, $m)) {
            $lotCtrl->mettreEnRepos($m[1]);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)/demarrer$#', $uri, $m)) {
            $lotCtrl->demarrer($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)/stocker$#', $uri, $m)) {
            $lotCtrl->stocker($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)/abandonner$#', $uri, $m)) {
            $lotCtrl->abandonner($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/lots/(\d+)$#', $uri, $m)) {
            $lotCtrl->update($m[1], $data);

        // ── Recettes tronc commun ─────────────────────────────────
        } elseif ($uri === $api . '/recettes-transfo') {
            $recetteTransfoCtrl->modifierVersion();

        } elseif ($uri === $api . '/recettes-transfo/statut') {
            $recetteTransfoCtrl->changerStatut();

        // ── Lots macération alcoolique ────────────────────────────
        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/demarrer-maceration$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->demarrerMaceration($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/filtrer$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->filtrer($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/assembler$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->assembler($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/demarrer-maturation$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->demarrerMaturation($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/stocker$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->stocker($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/ingredients$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->updateIngredients($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/mace-alcool/lots/(\d+)/abandonner$#', $uri, $m)) {
            $lotMaceAlcoolCtrl->abandonner($m[1], $data);

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    case 'DELETE':
        if (preg_match('#^' . preg_quote($api, '#') . '/gammes/(\d+)/produits/(\d+)$#', $uri, $m)) {
            $produitTransfoCtrl->delete($m[1], $m[2]);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/gammes/(\d+)$#', $uri, $m)) {
            $gammeCtrl->delete($m[1]);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/saveurs/(\d+)$#', $uri, $m)) {
            $saveurCtrl->delete($m[1]);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)$#', $uri, $m)) {
            $recetteCtrl->delete($m[1]);

        // ── Recettes tronc commun ─────────────────────────────────
        } elseif ($uri === $api . '/recettes-transfo') {
            $recetteTransfoCtrl->supprimerVersion();

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    default:
        echo ResponseHelper::jsonResponse('Requête non autorisée.', 'error', null, 405);
        break;
}

$mysqli->close();
