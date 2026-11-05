<?php
declare(strict_types=1);

/**
 * SMA-Move — Start MoveTrack Session Endpoint
 *
 * POST /api/movetrack/start_session.php
 *
 * Initializes an exploratory movement analysis session.
 * STRICT PRIVACY: Enforces active camera tracking consent.
 * NO raw video or camera frames are uploaded or saved.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/models/MoveTrackSession.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Models\MoveTrackSession;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

$user = AuthMiddleware::authenticate();

if ($user['role'] !== 'patient') {
    Response::forbidden('Only patients can initiate MoveTrack sessions.');
}

$input = RequestValidator::validate([]);

try {
    $session = MoveTrackSession::startSession((int)$user['id'], $input);
    Response::created($session, 'MoveTrack session initialized.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Start MoveTrack Session Error] " . $e->getMessage());
    Response::serverError('Unable to start MoveTrack session.');
}
