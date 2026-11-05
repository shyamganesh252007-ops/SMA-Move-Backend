<?php
declare(strict_types=1);

/**
 * SMA-Move — Request Body Parser & Validation Middleware
 *
 * Automatically parses incoming JSON payloads and executes validation rules,
 * returning a standardized 422 JSON response if constraints are violated.
 */

namespace SmaMove\Middleware;

require_once dirname(__DIR__) . '/utils/response.php';
require_once dirname(__DIR__) . '/utils/validation.php';

use SmaMove\Utils\Response;
use SmaMove\Utils\Validation;

class RequestValidator
{
    /**
     * Parse and return JSON payload from request body.
     *
     * @param bool $required Whether the JSON body is required to be non-empty
     * @return array Parsed JSON data as associative array
     */
    public static function getJsonBody(bool $required = true): array
    {
        $rawInput = file_get_contents('php://input');

        if ($rawInput === false || trim($rawInput) === '') {
            if ($required) {
                Response::badRequest('Request body must contain valid JSON.');
            }
            return [];
        }

        $decoded = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::badRequest('Malformed JSON payload: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            Response::badRequest('JSON payload must be a key-value object.');
        }

        return $decoded;
    }

    /**
     * Parse JSON body and validate against rules in one step.
     *
     * @param array $rules Validation rule definitions
     * @param bool $required Whether request body is required
     * @return array Validated input data
     */
    public static function validate(array $rules, bool $required = true): array
    {
        $data = self::getJsonBody($required);

        $result = Validation::validate($data, $rules);

        if (!$result['isValid']) {
            Response::validationError($result['errors'], 'Validation failed for one or more fields.');
        }

        return $data;
    }
}
