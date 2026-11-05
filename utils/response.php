<?php
declare(strict_types=1);

/**
 * SMA-Move — Standard JSON API Response Utility
 *
 * Enforces uniform JSON response envelope and appropriate HTTP status codes
 * across all backend endpoints.
 */

namespace SmaMove\Utils;

class Response
{
    /**
     * Send a standardized JSON response.
     *
     * @param bool $success Operation status
     * @param string $message User-facing message
     * @param mixed $data Payload (array, object, or null)
     * @param int $statusCode HTTP response status code
     * @param array|null $errors Detailed validation errors if applicable
     * @return void
     */
    public static function json(
        bool $success,
        string $message,
        mixed $data = null,
        int $statusCode = 200,
        ?array $errors = null
    ): void {
        // Discard any lingering buffer output to guarantee clean JSON
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');

        $payload = [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ];

        if ($errors !== null && !empty($errors)) {
            $payload['errors'] = $errors;
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Return 200 OK standard success response.
     */
    public static function success(mixed $data = null, string $message = 'Operation successful', int $statusCode = 200): void
    {
        self::json(true, $message, $data, $statusCode);
    }

    /**
     * Return 201 Created standard response.
     */
    public static function created(mixed $data = null, string $message = 'Resource created successfully'): void
    {
        self::json(true, $message, $data, 201);
    }

    /**
     * Return 204 No Content response.
     */
    public static function noContent(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(204);
        exit;
    }

    /**
     * Return standard error response.
     */
    public static function error(
        string $message = 'An error occurred',
        int $statusCode = 400,
        mixed $errors = null,
        mixed $data = null
    ): void {
        $errorList = is_array($errors) ? $errors : ($errors !== null ? [$errors] : null);
        self::json(false, $message, $data, $statusCode, $errorList);
    }

    /**
     * Return 400 Bad Request error.
     */
    public static function badRequest(string $message = 'Invalid request parameters', ?array $errors = null): void
    {
        self::json(false, $message, null, 400, $errors);
    }

    /**
     * Return 401 Unauthorized error.
     */
    public static function unauthorized(string $message = 'Authentication required'): void
    {
        self::json(false, $message, null, 401);
    }

    /**
     * Return 403 Forbidden error.
     */
    public static function forbidden(string $message = 'Access denied for this resource'): void
    {
        self::json(false, $message, null, 403);
    }

    /**
     * Return 404 Not Found error.
     */
    public static function notFound(string $message = 'Requested resource not found'): void
    {
        self::json(false, $message, null, 404);
    }

    /**
     * Return 405 Method Not Allowed error.
     */
    public static function methodNotAllowed(string $message = 'HTTP method not allowed'): void
    {
        self::json(false, $message, null, 405);
    }

    /**
     * Return 409 Conflict error.
     */
    public static function conflict(string $message = 'A resource conflict occurred'): void
    {
        self::json(false, $message, null, 409);
    }

    /**
     * Return 422 Unprocessable Entity (Validation Error).
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): void
    {
        self::json(false, $message, null, 422, $errors);
    }

    /**
     * Return 429 Too Many Requests error.
     */
    public static function tooManyRequests(string $message = 'Rate limit exceeded. Please wait before retrying.'): void
    {
        self::json(false, $message, null, 429);
    }

    /**
     * Return 500 Internal Server Error.
     */
    public static function serverError(string $message = 'An unexpected server error occurred'): void
    {
        self::json(false, $message, null, 500);
    }
}
