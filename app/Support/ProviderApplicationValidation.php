<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Exact allowlists; request middleware must not normalize passwords or decision enums. */
class ProviderApplicationValidation
{
    public function body(Request $request): array
    {
        if (! $request->isJson() || strlen($request->getContent()) > 16384) {
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

        return (array) $body;
    }

    public function application(array $input): array
    {
        return $this->strings($input, ['business_name' => [1, 150], 'business_location' => [1, 255], 'application_message' => [20, 2000]]);
    }

    public function decision(array $input): array
    {
        $decision = $input['decision'] ?? null;
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => ['Choose approved or rejected.']]);
        }
        $this->keys($input, $decision === 'approved' ? ['decision'] : ['decision', 'public_reason']);
        if ($decision === 'approved') {
            return ['decision' => 'approved', 'public_reason' => null];
        }
        $reason = $this->strings(['public_reason' => $input['public_reason'] ?? null], ['public_reason' => [1, 1000]])['public_reason'];
        if (strip_tags($reason) !== $reason) {
            throw ValidationException::withMessages(['public_reason' => ['Use plain text.']]);
        }

        return ['decision' => 'rejected', 'public_reason' => $reason];
    }

    public function password(#[\SensitiveParameter] array $input): string
    {
        $this->keys($input, ['password']);
        $value = $input['password'] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > 512) {
            throw ValidationException::withMessages(['password' => ['Supply the current password.']]);
        }

        return $value;
    }

    public function query(array $input): array
    {
        $this->keys($input, ['status', 'page', 'per_page']);
        Validator::make($input, [
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        return $input;
    }

    private function strings(array $input, array $fields): array
    {
        $this->keys($input, array_keys($fields));
        $rules = [];
        foreach ($fields as $field => [$min, $max]) {
            $value = $input[$field] ?? null;
            if (! is_string($value) || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                throw ValidationException::withMessages([$field => ['Supply a valid text value.']]);
            }
            $input[$field] = trim($value, " \t\r\n");
            $rules[$field] = ['required', 'string', 'min:'.$min, 'max:'.$max];
        }
        Validator::make($input, $rules)->validate();

        return $input;
    }

    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['payload' => ['Unsupported fields.']]);
        }
    }
}
