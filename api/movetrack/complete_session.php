<?php
declare(strict_types=1);

/**
 * SMA-Move — Complete MoveTrack Session Endpoint
 *
 * POST /api/movetrack/complete_session.php
 *
 * Finalizes session metrics (duration, consistency checkpoints, quality summary).
 * Minimal metadata only.
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
    Response::forbidden('Only patients can complete their MoveTrack sessions.');
}

// Validate input with explicit server-side bounds
$rawBody = RequestValidator::getJsonBody(false);
$rules = [
    'session_uuid' => ['required', 'string'],
    'duration_seconds' => ['required', 'integer', 'min:1', 'max:86400']
];

if (array_key_exists('consistency_percentage', $rawBody)) {
    if ($rawBody['consistency_percentage'] === null) {
        Response::validationError(['consistency_percentage' => 'consistency_percentage cannot be null if provided.']);
    }
    $rules['consistency_percentage'] = ['integer', 'min:0', 'max:100'];
}

if (array_key_exists('reps_completed', $rawBody)) {
    if ($rawBody['reps_completed'] === null) {
        Response::validationError(['reps_completed' => 'reps_completed cannot be null if provided.']);
    }
    $rules['reps_completed'] = ['integer', 'min:0', 'max:1000'];
}

$input = RequestValidator::validate($rules);

$sessionUuid = (string)$input['session_uuid'];

try {
    $completedSession = MoveTrackSession::completeSession(
        $sessionUuid,
        (int)$user['id'],
        $input
    );

    Response::success($completedSession, 'MoveTrack session summary recorded successfully.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Complete MoveTrack Session Error] " . $e->getMessage());
    Response::serverError('Unable to finalize MoveTrack session.');
}
