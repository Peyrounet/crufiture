<?php
/**
 * index.php — Point d'entrée API Forêt Féerique
 *
 * Gère les en-têtes CORS, la détection de l'environnement (local/prod),
 * charge le socle monpanier via bootstrap.php, puis dispatche vers routes/api.php.
 * @php     7.4+ (Hostinger) — compatible PHP 7.4 à 8.x
 *          Attention : pas de constantes dans les traits (PHP 8.2+),
 *          pas de match sans default, pas d'union types (PHP 8.0+)
 */

// ── Détection environnement ───────────────────────────────────────
// En local : afficher les erreurs pour faciliter le debug
// En production : les masquer (Hostinger désactive display_errors de toute façon)
$origin = ($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://';
$origin .= $_SERVER['HTTP_HOST'];

if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ── CORS ──────────────────────────────────────────────────────────
// On reflète l'origine de la requête pour autoriser les appels
// depuis peyrounet.com (prod) et localhost (dev) sans liste blanche fixe
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
} else {
    // Fallback si HTTP_ORIGIN absent (ex: appel direct curl, Postman)
    $origin = ($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://';
    $origin .= $_SERVER['HTTP_HOST'];
}

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true"); // Nécessaire pour le cookie JWT
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json; charset=UTF-8');

// Répondre immédiatement aux requêtes preflight OPTIONS (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Socle monpanier ───────────────────────────────────────────────
// Charge : Dotenv (.env racine), Database, JWTUtils, AuthMiddleware,
//          ResponseHelper, LogHelper, User, PHPMailer/createMailer
require_once './bootstrap.php';

// ── Router ────────────────────────────────────────────────────────
require_once './routes/api.php';
