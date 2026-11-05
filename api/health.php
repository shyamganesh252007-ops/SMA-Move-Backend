<?php
declare(strict_types=1);

/**
 * SMA-Move — API Health Check & Foundation Status Endpoint
 *
 * GET /api/health.php
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/cors.php';
require_once dirname(__DIR__) . '/utils/response.php';

use SmaMove\Config\Database;
use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Utils\Response;

// Handle CORS
CorsMiddleware::handle();

// Check database connection
$dbStatus = false;
$dbDetails = [];

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT CURRENT_USER() AS app_user, DATABASE() AS database_name, VERSION() AS db_version");
    $dbDetails = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $dbStatus = true;
} catch (\Throwable $e) {
    $dbStatus = false;
}

if ($dbStatus) {
    Response::success([
        'status' => 'operational',
        'api_name' => APP_NAME,
        'environment' => APP_ENV,
        'timestamp' => date('c'),
        'database' => [
            'connected' => true,
            'database' => $dbDetails['database_name'] ?? 'sma_move',
            'user' => explode('@', (string)($dbDetails['app_user'] ?? ''))[0]
        ]
    ], 'SMA-Move backend foundation is operational.');
} else {
    Response::serverError('Database connection unavailable.');
}
