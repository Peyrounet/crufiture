<?php
/**
 * bootstrap.php — Module /crufiture
 * Copie de foretfeerique/api/bootstrap.php — logs adaptés.
 * @php 7.4+
 */

$public_html = dirname(__FILE__, 3);

$monpanier = getenv('MONPANIER_API_PATH')
    ?: $public_html . '/monpanier/api';

if (!file_exists($monpanier . '/config/database.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'status'      => 'error',
        'message'     => 'Le socle monpanier est introuvable. Vérifiez MONPANIER_API_PATH.',
        'path_tried'  => $monpanier,
        'public_html' => $public_html,
        '__file__'    => __FILE__,
    ]);
    exit;
}

require_once $monpanier . '/helpers/LogHelper.php';
helpers\LogHelper::addLog("Crufiture bootstrap : chargement du socle : $monpanier");

require_once $monpanier . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__FILE__, 3));
$dotenv->load();

require_once $monpanier . '/config/database.php';
require_once $monpanier . '/helpers/ResponseHelper.php';
require_once $monpanier . '/utils/JWTUtils.php';
require_once $monpanier . '/utils/AuthMiddleware.php';
require_once $monpanier . '/models/User.php';

if (file_exists($monpanier . '/config/smtp.php')) {
    require_once $monpanier . '/config/smtp.php';
}
if (file_exists($monpanier . '/services/MailService.php')) {
    require_once $monpanier . '/services/MailService.php';
}

helpers\LogHelper::addLog("Crufiture bootstrap chargé avec succès.");
