<?php
/**
 * routes/api.php — Router principal /crufiture
 * @php 7.4+
 */

require_once './controllers/PingController.php';
require_once './controllers/FermeWidgetController.php';
require_once './controllers/DashboardController.php';

use helpers\ResponseHelper;

$mysqli = (new Database())->getConnection();

$pingCtrl      = new PingController();
$widgetCtrl    = new FermeWidgetController($mysqli);
$dashboardCtrl = new DashboardController($mysqli);

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

        } else {
            echo ResponseHelper::jsonResponse('Route introuvable.', 'error', null, 404);
        }
        break;

    default:
        echo ResponseHelper::jsonResponse('Requête non autorisée.', 'error', null, 405);
        break;
}

$mysqli->close();
