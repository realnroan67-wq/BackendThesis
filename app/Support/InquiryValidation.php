<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use LogicException;

/** Pinned language-neutral contract, with JavaScript-compatible Unicode semantics. */
class InquiryValidation
{
    public function specification(): array
    {
        $path = base_path('contracts/inquiry-validation.v1.json');
        $manifest = json_decode(file_get_contents(base_path('contracts/inquiry-v1.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        if (hash_file('sha256', $path) !== $manifest['validation_sha256']) {
            throw new LogicException('The pinned inquiry validation artifact changed.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Validate only request fields; never accept identities, status, grants or query input. */
    public function validate(array $input): array
    {
        $spec = $this->specification();
        $errors = [];
        $values = [];
        if (array_diff(array_keys($input), array_keys($spec['properties']))) {
            $errors['payload'][] = 'Unrecognized inquiry fields are not allowed.';
        }
        foreach ($spec['properties'] as $field => $rule) {
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value, " \t\r\n");
                if ($value === '' && in_array($field, $spec['x-normalization']['blank-to-null'], true)) {
                    $value = null;
                }
            }
            $values[$field] = $value;
            if ($value === null) {
                if (in_array($field, $spec['required'], true)) {
                    $errors[$field][] = 'This field is required.';
                }

                continue;
            }
            if (($rule['type'] ?? null) === 'integer') {
                $integer = is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
                if (! $integer || $value < $rule['minimum'] || $value > $rule['maximum']) {
                    $errors[$field][] = 'Use a valid JSON integer in the supported range.';
                } else {
                    $values[$field] = (int) $value;
                }

                continue;
            }
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                $errors[$field][] = 'Use a valid string.';

                continue;
            }
            $length = mb_strlen($value, 'UTF-8');
            if ($length < ($rule['minLength'] ?? 0) || $length > $rule['maxLength']) {
                $errors[$field][] = 'Use a value within the permitted character limits.';
            }
            if (isset($rule['pattern'])) {
                // ECMAScript \s differs from PCRE Unicode \s (notably BOM and NEL).
                $whitespace = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
                $pattern = str_replace('\\s', $whitespace, $rule['pattern']);
                if (preg_match('~'.$pattern.'~uD', $value) !== 1) {
                    $errors[$field][] = 'Use the contact format specified by the inquiry contract.';
                }
            }
            if ($field === 'contact_phone') {
                $digits = strlen(preg_replace('/[^0-9]/', '', $value));
                $range = $spec['x-normalization']['phone-digits'];
                if ($digits < $range['minimum'] || $digits > $range['maximum']) {
                    $errors[$field][] = 'Use a phone number with the permitted number of digits.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    public function key(?string $key): string
    {
        if ($key === null || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => ['Supply a UUID v4 Idempotency-Key header.']]);
        }

        return strtolower($key);
    }
}
