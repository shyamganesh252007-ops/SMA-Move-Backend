<?php
declare(strict_types=1);

/**
 * SMA-Move — Environment Variable Loader
 *
 * Lightweight, safe .env loader that populates getenv(), $_ENV, and $_SERVER
 * without leaking secrets into error logs or web outputs.
 */

namespace SmaMove\Config {

class Environment
{
    private static bool $loaded = false;

    /**
     * Load environment variables from a .env file.
     *
     * @param string|null $filePath Path to .env file (defaults to root directory)
     * @return bool True if loaded successfully, false if file does not exist
     */
    public static function load(?string $filePath = null): bool
    {
        if (self::$loaded && $filePath === null) {
            return true;
        }

        $path = $filePath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comment lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split by the first '=' character
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Validate key naming format
            if (!preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }

            // Strip surrounding double or single quotes
            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            // Cast common boolean and null representations
            $lowered = strtolower($val);
            if ($lowered === 'true') {
                $val = 'true';
            } elseif ($lowered === 'false') {
                $val = 'false';
            } elseif ($lowered === 'null') {
                $val = '';
            }

            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }

        self::$loaded = true;
        return true;
    }

    /**
     * Retrieve an environment variable with a fallback default.
     *
     * @param string $key Environment key
     * @param mixed $default Fallback value if not set
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        $value = getenv($key);
        if ($value !== false) {
            return self::parseValue($value);
        }

        if (array_key_exists($key, $_ENV)) {
            return self::parseValue($_ENV[$key]);
        }

        if (array_key_exists($key, $_SERVER)) {
            return self::parseValue($_SERVER[$key]);
        }

        return $default;
    }

    /**
     * Parse raw string values into typed PHP values where appropriate.
     */
    private static function parseValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

}

namespace {

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return \SmaMove\Config\Environment::get($key, $default);
    }
}

}
