<?php
declare(strict_types=1);

/**
 * SMA-Move — Cryptographic Token Utility
 *
 * Implements CSPRNG token generation and SHA-256 token hashing for opaque bearer tokens.
 */

namespace SmaMove\Utils;

class TokenManager
{
    /**
     * Generate an opaque, cryptographically secure random bearer token string.
     *
     * @param int $byteLength Number of random bytes (32 bytes = 64 hex chars)
     * @return string High-entropy random hex token
     */
    public static function generateOpaqueToken(int $byteLength = 32): string
    {
        return bin2hex(random_bytes($byteLength));
    }

    /**
     * Compute SHA-256 hash of a plain token string for database storage and verification.
     *
     * @param string $plainToken Plaintext bearer token
     * @return string 64-character lowercase hexadecimal SHA-256 hash
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', trim($plainToken));
    }

    /**
     * Generate an email verification / activation code.
     *
     * @param int $length Code length (default 6 digits)
     * @return string Numeric activation code
     */
    public static function generateVerificationCode(int $length = 6): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= (string)random_int(0, 9);
        }
        return $code;
    }
}
