<?php
declare(strict_types=1);

/**
 * SMA-Move — Authentication Middleware
 *
 * Enforces opaque Bearer token verification, token expiration, revocation checks,
 * and account status validation on all protected API endpoints.
 */

namespace SmaMove\Middleware;

require_once dirname(__DIR__) . '/utils/response.php';
require_once dirname(__DIR__) . '/utils/token.php';
require_once dirname(__DIR__) . '/models/AuthToken.php';

use SmaMove\Utils\Response;
use SmaMove\Utils\TokenManager;
use SmaMove\Models\AuthToken;

class AuthMiddleware
{
    /**
     * Authenticate incoming request and return the active user payload.
     *
     * @return array Authenticated user record
     */
    public static function authenticate(): array
    {
        $header = self::getAuthorizationHeader();

        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            Response::unauthorized('Authentication required. Missing or malformed Bearer token.');
        }

        $plainToken = trim(substr($header, 7));

        if ($plainToken === '') {
            Response::unauthorized('Empty authentication token provided.');
        }

        // Compute SHA-256 hash
        $tokenHash = TokenManager::hashToken($plainToken);

        // Verify token in database
        $tokenRecord = AuthToken::findValidToken($tokenHash, 'access');

        if ($tokenRecord === null) {
            Response::unauthorized('Invalid, expired, or revoked authentication token.');
        }

        // Account status checks
        $status = $tokenRecord['account_status'] ?? 'pending';

        if ($status === 'suspended') {
            Response::forbidden('Your account has been suspended. Please contact clinical support.');
        }

        if ($status === 'pending') {
            Response::forbidden('Your account is pending activation. Please complete email verification.');
        }

        if ($status !== 'active') {
            Response::forbidden('Account is not currently authorized for access.');
        }

        return [
            'id' => (int)$tokenRecord['user_id'],
            'full_name' => $tokenRecord['full_name'],
            'email' => $tokenRecord['email'],
            'role' => $tokenRecord['role'],
            'account_status' => $tokenRecord['account_status'],
            'email_verified_at' => $tokenRecord['email_verified_at'],
            'token_hash' => $tokenHash
        ];
    }

    /**
     * Retrieve the Authorization header from the request environment.
     */
    public static function getAuthorizationHeader(): ?string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $val) {
                if (strtolower($key) === 'authorization') {
                    return trim((string)$val);
                }
            }
        }

        return null;
    }
}
