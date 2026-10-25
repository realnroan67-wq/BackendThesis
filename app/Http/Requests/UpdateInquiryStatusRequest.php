<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use stdClass;

/** Close only; identities, grants, timestamps and audit fields are never client input. */
class UpdateInquiryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return User::query()->whereKey($this->user()->getKey())->where('role', UserRole::PROPERTY_PROVIDER)->exists();
    }

    public function validationData(): array
    {
        $decoded = json_decode($this->getContent(), true);

        return $this->isJson() && is_array($decoded) ? $decoded : [];
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', 'in:closed']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->isJson() || ! (json_decode($this->getContent()) instanceof stdClass)) {
                $validator->errors()->add('payload', 'Send a JSON object containing only status.');
            }
            if (array_diff(array_keys($this->validationData()), ['status']) !== []) {
                $validator->errors()->add('payload', 'Only status may be updated.');
            }
            if ($this->query->all() !== []) {
                $validator->errors()->add('query', 'Status updates do not support query parameters.');
            }
        }];
    }
}
