<?php
/**
 * routes/api.php — Router principal /crufiture
 * @php 7.4+
 */

require_once './controllers/PingController.php';
require_once './controllers/FermeWidgetController.php';
require_once './controllers/DashboardController.php';
require_once './controllers/SaveurController.php';
require_once './controllers/RecetteController.php';

use helpers\ResponseHelper;

$mysqli = (new Database())->getConnection();

$pingCtrl      = new PingController();
$widgetCtrl    = new FermeWidgetController($mysqli);
$dashboardCtrl = new DashboardController($mysqli);
$saveurCtrl    = new SaveurController($mysqli);
$recetteCtrl   = new RecetteController($mysqli);

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

        } elseif ($uri === $api . '/saveurs') {
            $saveurCtrl->getAll();

        } elseif ($uri === $api . '/recettes') {
            $recetteCtrl->getAll();

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)$#', $uri, $m)) {
            $recetteCtrl->getOne($m[1]);

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($uri === $api . '/saveurs') {
            $saveurCtrl->create($data);

        } elseif ($uri === $api . '/recettes') {
            $recetteCtrl->create($data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)/dupliquer$#', $uri, $m)) {
            $recetteCtrl->dupliquer($m[1]);

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (preg_match('#^' . preg_quote($api, '#') . '/saveurs/(\d+)$#', $uri, $m)) {
            $saveurCtrl->update($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)/complet$#', $uri, $m)) {
            $recetteCtrl->updateComplet($m[1], $data);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)$#', $uri, $m)) {
            $recetteCtrl->update($m[1], $data);

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    case 'DELETE':
        if (preg_match('#^' . preg_quote($api, '#') . '/saveurs/(\d+)$#', $uri, $m)) {
            $saveurCtrl->delete($m[1]);

        } elseif (preg_match('#^' . preg_quote($api, '#') . '/recettes/(\d+)$#', $uri, $m)) {
            $recetteCtrl->delete($m[1]);

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    default:
        echo ResponseHelper::jsonResponse('Requête non autorisée.', 'error', null, 405);
        break;
}

$mysqli->close();
