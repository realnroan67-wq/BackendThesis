<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Versioned validation shared as artifacts, never by importing frontend application code. */
class RegistrationValidation
{
    public function specification(): array
    {
        return json_decode(file_get_contents(base_path('contracts/registration-validation-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function fromRequest(Request $request): array
    {
        if (! $request->isJson() || strlen($request->getContent()) > $this->specification()['body_max_bytes']) {
            throw ValidationException::withMessages(['payload' => ['Send a JSON object within 16 KiB.']]);
        }
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['payload' => ['Send a valid JSON object.']]);
        }
        if (! $body instanceof stdClass) {
            throw ValidationException::withMessages(['payload' => ['Send a JSON object.']]);
        }

        return $this->validate((array) $body);
    }

    public function validate(#[\SensitiveParameter] array $input): array
    {
        $spec = $this->specification();
        if (array_diff(array_keys($input), array_keys($spec['fields']))) {
            throw ValidationException::withMessages(['payload' => ['Unsupported registration fields.']]);
        }
        foreach ($spec['fields'] as $field => $rule) {
            if (! isset($input[$field]) || ! is_string($input[$field]) || preg_match('//u', $input[$field]) !== 1) {
                throw ValidationException::withMessages([$field => ['This field must be a Unicode string.']]);
            }
        }
        $input['name'] = trim($input['name'], " \t\r\n");
        $input['email'] = strtolower(trim($input['email'], " \t\r\n"));
        $rules = [];
        foreach ($spec['fields'] as $field => $rule) {
            $rules[$field] = ['required', 'string'];
            if (isset($rule['minLength'])) {
                $rules[$field][] = 'min:'.$rule['minLength'];
            }
            if (isset($rule['maxLength'])) {
                $rules[$field][] = 'max:'.$rule['maxLength'];
            }
            if (isset($rule['maxBytes'])) {
                $rules[$field][] = function (string $attribute, mixed $value, \Closure $fail) use ($rule): void {
                    if (strlen($value) > $rule['maxBytes']) {
                        $fail('This field exceeds its byte limit.');
                    }
                };
            }
        }
        $rules['email'][] = 'email:rfc';
        $rules['email'][] = 'regex:/\A[!-~]+\z/';
        $rules['account_type'][] = 'in:'.implode(',', $spec['fields']['account_type']['enum']);
        $rules['password_confirmation'][] = 'same:password';
        $rules['password'][] = function (string $attribute, mixed $value, \Closure $fail) use ($spec): void {
            if (in_array(strtolower($value), $spec['common_passwords'], true)) {
                $fail('Choose a less common password.');
            }
        };
        Validator::make($input, $rules)->validate();

        return $input;
    }
}
