<?php
declare(strict_types=1);

/**
 * SMA-Move — Authentication & Account Security Service
 *
 * Implements registration, login, logout, password recovery, session tokens,
 * and account status lifecycle for all three user roles.
 */

namespace SmaMove\Services;

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/utils/token.php';
require_once dirname(__DIR__) . '/utils/rate_limiter.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/PatientProfile.php';
require_once dirname(__DIR__) . '/models/CaregiverProfile.php';
require_once dirname(__DIR__) . '/models/ProfessionalProfile.php';
require_once dirname(__DIR__) . '/models/AuthToken.php';
require_once dirname(__DIR__) . '/models/PasswordReset.php';
require_once dirname(__DIR__) . '/models/EmailVerification.php';

use SmaMove\Config\Database;
use SmaMove\Utils\TokenManager;
use SmaMove\Utils\RateLimiter;
use SmaMove\Models\User;
use SmaMove\Models\PatientProfile;
use SmaMove\Models\CaregiverProfile;
use SmaMove\Models\ProfessionalProfile;
use SmaMove\Models\AuthToken;
use SmaMove\Models\PasswordReset;
use SmaMove\Models\EmailVerification;
use Exception;
use RuntimeException;

class AuthService
{
    /**
     * Register a new user with appropriate role profile in a database transaction.
     *
     * @param array $data User registration input
     * @return array Created user details and activation status
     */
    public static function signup(array $data): array
    {
        $normalizedEmail = strtolower(trim($data['email']));
        $role = trim($data['role']);

        if (!in_array($role, ['patient', 'caregiver', 'professional'], true)) {
            throw new RuntimeException("Invalid user role: {$role}", 422);
        }

        // Check for duplicate email
        $existing = User::findByEmail($normalizedEmail);
        if ($existing !== null) {
            throw new RuntimeException("An account with this email address already exists.", 409);
        }

        // Determine activation policy
        // If explicitly set in input as active (e.g. for testing) or AUTO_ACTIVATE_USERS=true
        $autoActivate = (bool)env('AUTO_ACTIVATE_USERS', true);
        $accountStatus = $autoActivate ? 'active' : 'pending';
        $verifiedAt = $autoActivate ? date('Y-m-d H:i:s') : null;

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

            $userId = User::create([
                'full_name' => trim($data['full_name']),
                'email' => $normalizedEmail,
                'password_hash' => $passwordHash,
                'role' => $role,
                'account_status' => $accountStatus,
                'email_verified_at' => $verifiedAt,
            ]);

            // Create role-specific profile stub
            if ($role === 'patient') {
                PatientProfile::create($userId, [
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'preferred_language' => $data['preferred_language'] ?? 'English',
                    'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                    'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                    'profile_completed' => 0
                ]);
            } elseif ($role === 'caregiver') {
                CaregiverProfile::create($userId, [
                    'contact_number' => $data['contact_number'] ?? null,
                    'relationship_description' => $data['relationship_description'] ?? 'Primary Family Caregiver'
                ]);
            } elseif ($role === 'professional') {
                ProfessionalProfile::create($userId, [
                    'professional_title' => $data['professional_title'] ?? null,
                    'qualification' => $data['qualification'] ?? null,
                    'registration_number' => $data['registration_number'] ?? null,
                    'institution_name' => $data['institution_name'] ?? null
                ]);
            }

            // Generate email activation code
            $verificationCode = TokenManager::generateVerificationCode(6);
            $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours
            EmailVerification::createVerification($userId, $verificationCode, $expiresAt);

            $pdo->commit();

            $result = [
                'id' => $userId,
                'full_name' => trim($data['full_name']),
                'email' => $normalizedEmail,
                'role' => $role,
                'account_status' => $accountStatus,
                'email_verified' => $autoActivate,
            ];

            if (!$autoActivate) {
                $result['activation_notice'] = 'Account created in pending state. Submit verification code to activate.';
                // If in development mode, provide code in response for testing
                if (env('APP_DEBUG', false) && env('APP_ENV') === 'development') {
                    $result['dev_verification_code'] = $verificationCode;
                }
            }

            return $result;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Authenticate user credentials and issue cryptographically secure opaque tokens.
     */
    public static function login(
        string $email,
        string $password,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): array {
        $normalizedEmail = strtolower(trim($email));
        $ip = $ipAddress ?? RateLimiter::getClientIp();

        // Check rate limits
        if (RateLimiter::isExceeded($ip, 'login', (int)env('RATE_LIMIT_AUTH_ATTEMPTS', 5))) {
            throw new RuntimeException("Too many failed login attempts. Please try again in 15 minutes.", 429);
        }

        $user = User::findByEmail($normalizedEmail);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            RateLimiter::hit($ip, 'login');
            throw new RuntimeException("Invalid email or password.", 401);
        }

        // Account status checks
        if ($user['account_status'] === 'suspended') {
            throw new RuntimeException("Account is suspended. Please contact clinical support.", 403);
        }

        if ($user['account_status'] === 'pending') {
            throw new RuntimeException("Account is pending activation. Please verify your email before logging in.", 403);
        }

        if ($user['account_status'] !== 'active') {
            throw new RuntimeException("Account is not active.", 403);
        }

        // Clear rate limiter on successful authentication
        RateLimiter::clear($ip, 'login');

        // Generate opaque bearer access token and refresh token
        $plainAccessToken = TokenManager::generateOpaqueToken(32);
        $plainRefreshToken = TokenManager::generateOpaqueToken(32);

        $accessHash = TokenManager::hashToken($plainAccessToken);
        $refreshHash = TokenManager::hashToken($plainRefreshToken);

        $accessTtl = (int)env('ACCESS_TOKEN_TTL', 86400); // 24 hours
        $refreshTtl = (int)env('REFRESH_TOKEN_TTL', 2592000); // 30 days

        $accessExpiresAt = date('Y-m-d H:i:s', time() + $accessTtl);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + $refreshTtl);

        // Store token hashes
        AuthToken::createToken((int)$user['id'], $accessHash, 'access', $accessExpiresAt, $userAgent, $ip);
        AuthToken::createToken((int)$user['id'], $refreshHash, 'refresh', $refreshExpiresAt, $userAgent, $ip);

        // Fetch profile summary
        $profile = self::getRoleProfile((int)$user['id'], $user['role']);

        return [
            'token_type' => 'Bearer',
            'access_token' => $plainAccessToken,
            'refresh_token' => $plainRefreshToken,
            'expires_at' => $accessExpiresAt,
            'user' => [
                'id' => (int)$user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'account_status' => $user['account_status'],
                'email_verified_at' => $user['email_verified_at'],
                'profile' => $profile
            ]
        ];
    }

    /**
     * Revoke active token on logout.
     */
    public static function logout(string $plainToken): bool
    {
        $tokenHash = TokenManager::hashToken($plainToken);
        return AuthToken::revokeToken($tokenHash);
    }

    /**
     * Retrieve full current user profile.
     */
    public static function me(int $userId): ?array
    {
        $user = User::findById($userId);
        if ($user === null) {
            return null;
        }

        $profile = self::getRoleProfile($userId, $user['role']);

        return [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'account_status' => $user['account_status'],
            'email_verified_at' => $user['email_verified_at'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
            'profile' => $profile
        ];
    }

    /**
     * Initiate password reset request without revealing user existence.
     */
    public static function requestPasswordReset(string $email, ?string $ipAddress = null): array
    {
        $normalizedEmail = strtolower(trim($email));
        $ip = $ipAddress ?? RateLimiter::getClientIp();

        if (RateLimiter::isExceeded($ip, 'forgot_pwd', 5, 900)) {
            throw new RuntimeException("Too many reset requests. Please wait 15 minutes before retrying.", 429);
        }

        RateLimiter::hit($ip, 'forgot_pwd', 900);

        $user = User::findByEmail($normalizedEmail);
        $resetToken = null;

        if ($user !== null && $user['account_status'] !== 'suspended') {
            $resetToken = TokenManager::generateOpaqueToken(32);
            $tokenHash = TokenManager::hashToken($resetToken);
            $ttl = (int)env('PASSWORD_RESET_TTL', 3600); // 1 hour
            $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

            PasswordReset::createReset((int)$user['id'], $tokenHash, $expiresAt);

            error_log(sprintf(
                "[Password Reset Requested] User ID: %d, Email: %s (Token generated, no SMTP configured)",
                $user['id'],
                $normalizedEmail
            ));
        }

        // Generic non-enumerating response
        $response = [
            'message' => 'If an account with that email exists, password reset instructions have been generated.'
        ];

        // For local development and automated testing, provide token when debug enabled
        if ($resetToken !== null && env('APP_DEBUG', false) && env('APP_ENV') === 'development') {
            $response['dev_reset_token'] = $resetToken;
        }

        return $response;
    }

    /**
     * Complete password reset using valid reset token.
     */
    public static function resetPassword(string $plainToken, string $newPassword): bool
    {
        $tokenHash = TokenManager::hashToken($plainToken);
        $resetRecord = PasswordReset::findValidReset($tokenHash);

        if ($resetRecord === null) {
            throw new RuntimeException("Invalid or expired password reset token.", 400);
        }

        $userId = (int)$resetRecord['user_id'];
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // 1. Update user password
            User::updatePassword($userId, $newPasswordHash);

            // 2. Mark reset token as used (single-use enforcement)
            PasswordReset::markUsed($tokenHash);

            // 3. Invalidate ALL existing active authentication sessions for this user
            AuthToken::revokeAllUserTokens($userId);

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Activate / verify user account using email verification code.
     */
    public static function verifyEmail(string $email, string $code): bool
    {
        $normalizedEmail = strtolower(trim($email));
        $user = User::findByEmail($normalizedEmail);

        if ($user === null) {
            throw new RuntimeException("Invalid verification code or email.", 400);
        }

        $userId = (int)$user['id'];
        $verification = EmailVerification::findValidVerification($userId, trim($code));

        if ($verification === null) {
            throw new RuntimeException("Invalid or expired verification code.", 400);
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Mark verification record as verified
            EmailVerification::markVerified((int)$verification['id']);

            // Transition account to active
            User::updateAccountStatus($userId, 'active', date('Y-m-d H:i:s'));

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fetch role-specific profile details.
     */
    private static function getRoleProfile(int $userId, string $role): ?array
    {
        return match ($role) {
            'patient' => PatientProfile::findByUserId($userId),
            'caregiver' => CaregiverProfile::findByUserId($userId),
            'professional' => ProfessionalProfile::findByUserId($userId),
            default => null,
        };
    }
}
