<?php
declare(strict_types=1);

/**
 * SMA-Move — Core Application Configuration & Bootstrapper
 *
 * Initializes runtime environment, error handling, security headers, and
 * global constants for the backend API.
 */

namespace SmaMove\Config;

require_once __DIR__ . '/environment.php';

// Load environment variables
Environment::load();

// Set default timezone
$timezone = env('APP_TIMEZONE', 'Asia/Kolkata');
date_default_timezone_set($timezone);

// Determine execution environment
define('APP_ENV', env('APP_ENV', 'development'));
define('APP_DEBUG', (bool)env('APP_DEBUG', false));
define('APP_NAME', env('APP_NAME', 'SMA-Move'));
define('APP_URL', env('APP_URL', 'http://localhost/sma-move-backend'));

/**
 * Global Security & Error Handling Setup
 *
 * Ensures unhandled errors and exceptions never output raw HTML or expose
 * stack traces, database credentials, or server internals to API clients.
 */
error_reporting(E_ALL);

if (APP_DEBUG && APP_ENV === 'development') {
    ini_set('display_errors', '0'); // Keep off so PHP notices don't corrupt JSON output
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

ini_set('log_errors', '1');

// Set custom error log location
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'error.log');

/**
 * Centralized Exception Handler
 */
set_exception_handler(function (\Throwable $exception): void {
    $isDev = APP_DEBUG && APP_ENV === 'development';

    // Log complete internal error trace securely
    error_log(sprintf(
        "[Uncaught Exception] %s: %s in %s:%d\nStack Trace:\n%s",
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    ));

    // Clear output buffer if any
    if (ob_get_length()) {
        ob_clean();
    }

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, sprintf("\n[Exception] %s: %s in %s:%d\n", get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        exit(1);
    }

    if (!headers_sent()) {
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, sprintf("\n[Fatal Error] %s in %s:%d\n", $error['message'], $error['file'], $error['line']));
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
        }
    }

    $response = [
        'success' => false,
        'message' => 'An internal server error occurred. Please try again later.',
        'data' => null
    ];

    if ($isDev) {
        $response['debug'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine()
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
});

/**
 * Centralized Error Handler (converts PHP errors/notices to ErrorException)
 */
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Shutdown function to catch fatal errors
 */
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) {
            ob_clean();
        }

        error_log(sprintf(
            "[Fatal Error] %s in %s:%d",
            $error['message'],
            $error['file'],
            $error['line']
        ));

        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, sprintf("\n[Fatal Error] %s in %s:%d\n", $error['message'], $error['file'], $error['line']));
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'success' => false,
            'message' => 'A fatal server error occurred.',
            'data' => null
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
});
