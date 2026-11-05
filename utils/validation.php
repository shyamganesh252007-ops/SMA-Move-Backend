<?php
declare(strict_types=1);

/**
 * SMA-Move — Request Validation & Sanitization Utility
 *
 * Provides comprehensive data validation and sanitization for healthcare API requests.
 */

namespace SmaMove\Utils;

class Validation
{
    /**
     * Validate an associative data array against declarative rules.
     *
     * Example rules:
     * [
     *   'email' => ['required', 'email'],
     *   'password' => ['required', 'password_strength'],
     *   'role' => ['required', 'enum:patient,caregiver,professional'],
     *   'full_name' => ['required', 'min:2', 'max:150']
     * ]
     *
     * @param array $data Input data
     * @param array $rules Validation rules
     * @return array [bool $isValid, array $errors]
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $ruleName = $rule;
                $ruleParam = null;

                if (str_contains($rule, ':')) {
                    [$ruleName, $ruleParam] = explode(':', $rule, 2);
                }

                // Required rule
                if ($ruleName === 'required') {
                    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                        $errors[$field][] = "The {$field} field is required.";
                        break; // Stop further checks on this field if absent
                    }
                    continue;
                }

                // If value is not provided and not required, skip subsequent rules
                if ($value === null || $value === '') {
                    continue;
                }

                switch ($ruleName) {
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen((string)$value) > 254) {
                            $errors[$field][] = "The {$field} must be a valid email address.";
                        }
                        break;

                    case 'password_strength':
                        $valStr = (string)$value;
                        if (strlen($valStr) < 8) {
                            $errors[$field][] = "The password must be at least 8 characters long.";
                        } elseif (!preg_match('/[A-Z]/', $valStr)) {
                            $errors[$field][] = "The password must contain at least one uppercase letter.";
                        } elseif (!preg_match('/[a-z]/', $valStr)) {
                            $errors[$field][] = "The password must contain at least one lowercase letter.";
                        } elseif (!preg_match('/[0-9]/', $valStr)) {
                            $errors[$field][] = "The password must contain at least one number.";
                        }
                        break;

                    case 'min':
                        $min = (int)$ruleParam;
                        if (is_string($value) && mb_strlen($value) < $min) {
                            $errors[$field][] = "The {$field} must be at least {$min} characters.";
                        } elseif (is_numeric($value) && $value < $min) {
                            $errors[$field][] = "The {$field} must be at least {$min}.";
                        }
                        break;

                    case 'max':
                        $max = (int)$ruleParam;
                        if (is_string($value) && mb_strlen($value) > $max) {
                            $errors[$field][] = "The {$field} must not exceed {$max} characters.";
                        } elseif (is_numeric($value) && $value > $max) {
                            $errors[$field][] = "The {$field} must not exceed {$max}.";
                        }
                        break;

                    case 'enum':
                        $allowed = explode(',', (string)$ruleParam);
                        if (!in_array((string)$value, $allowed, true)) {
                            $allowedStr = implode(', ', $allowed);
                            $errors[$field][] = "The {$field} must be one of: {$allowedStr}.";
                        }
                        break;

                    case 'date':
                        $format = $ruleParam ?? 'Y-m-d';
                        $d = \DateTime::createFromFormat($format, (string)$value);
                        if (!$d || $d->format($format) !== (string)$value) {
                            $errors[$field][] = "The {$field} must be a valid date in {$format} format.";
                        }
                        break;

                    case 'boolean':
                        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                            $errors[$field][] = "The {$field} must be a boolean value.";
                        }
                        break;

                    case 'integer':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $errors[$field][] = "The {$field} must be an integer.";
                        }
                        break;

                    case 'regex':
                        if (!preg_match((string)$ruleParam, (string)$value)) {
                            $errors[$field][] = "The {$field} format is invalid.";
                        }
                        break;

                    case 'uuid':
                        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string)$value)) {
                            $errors[$field][] = "The {$field} must be a valid UUID.";
                        }
                        break;
                }
            }
        }

        return [
            'isValid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Sanitize string input to prevent XSS.
     */
    public static function sanitizeString(?string $input): string
    {
        if ($input === null) {
            return '';
        }
        return htmlspecialchars(trim($input), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize email address.
     */
    public static function sanitizeEmail(?string $email): string
    {
        if ($email === null) {
            return '';
        }
        return strtolower(trim(filter_var($email, FILTER_SANITIZE_EMAIL) ?: ''));
    }

    /**
     * Validate a telehealth consultation meeting URL against strict security rules and approved domain allowlist.
     *
     * Rules enforced:
     * - Must be a valid URL format (FILTER_VALIDATE_URL)
     * - Protocol must strictly be HTTPS
     * - Rejects non-HTTP schemes (javascript:, data:, file:, etc.) and plain HTTP
     * - Rejects embedded authentication credentials (user:pass@host)
     * - Hostname must match an explicitly configured allowlist (TELEHEALTH_APPROVED_DOMAINS)
     * - Hostname matching is exact and case-insensitive (no insecure substring matching)
     * - If allowlist is empty or unconfigured, manual URLs are strictly rejected
     *
     * @param string|null $url The candidate URL to validate
     * @param string|null $customAllowlist Optional override for testing
     * @return array ['valid' => bool, 'url' => ?string, 'error' => ?string]
     */
    public static function validateTelehealthUrl(?string $url, ?string $customAllowlist = null): array
    {
        if ($url === null || trim($url) === '') {
            return ['valid' => true, 'url' => null, 'error' => null];
        }

        $trimmed = trim($url);

        // Maximum URL length safety check
        if (strlen($trimmed) > 2048) {
            return ['valid' => false, 'url' => null, 'error' => 'Meeting URL exceeds maximum length of 2048 characters.'];
        }

        // Validate basic URL structure
        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return ['valid' => false, 'url' => null, 'error' => 'Malformed or invalid meeting URL format.'];
        }

        $parts = parse_url($trimmed);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return ['valid' => false, 'url' => null, 'error' => 'Malformed meeting URL: Missing scheme or host.'];
        }

        // Scheme MUST strictly be HTTPS
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https') {
            return [
                'valid' => false,
                'url' => null,
                'error' => "Insecure or prohibited URL scheme '{$scheme}'. Meeting links must strictly use HTTPS."
            ];
        }

        // Embedded credentials check (e.g. https://user:pass@domain.com)
        if (isset($parts['user']) || isset($parts['pass'])) {
            return [
                'valid' => false,
                'url' => null,
                'error' => 'Meeting URLs containing embedded user authentication credentials are strictly prohibited.'
            ];
        }

        $host = strtolower($parts['host']);

        // Retrieve approved domain allowlist from environment or custom parameter
        $rawAllowlist = $customAllowlist !== null ? $customAllowlist : (string)env('TELEHEALTH_APPROVED_DOMAINS', '');
        $approvedDomains = array_filter(array_map('strtolower', array_map('trim', explode(',', $rawAllowlist))));

        if (empty($approvedDomains)) {
            return [
                'valid' => false,
                'url' => null,
                'error' => 'Telehealth domain allowlist is unconfigured or empty on the server. Manual meeting links are not permitted.'
            ];
        }

        // Exact hostname matching (no substring matches)
        if (!in_array($host, $approvedDomains, true)) {
            return [
                'valid' => false,
                'url' => null,
                'error' => "Untrusted telehealth domain '{$host}'. The host does not match any approved telehealth provider domain."
            ];
        }

        return ['valid' => true, 'url' => $trimmed, 'error' => null];
    }
}
