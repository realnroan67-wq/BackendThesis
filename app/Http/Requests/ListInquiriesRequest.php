<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/** CURRENT: Read-only participant lists; identity scope always comes from the session. */
class ListInquiriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->routeIs('api.v1.provider.inquiries.index')
            ? UserRole::PROPERTY_PROVIDER : UserRole::BUSINESS_USER;

        return Gate::allows('viewAny', Inquiry::class)
            && User::query()->whereKey($this->user()->getKey())->where('role', $role)->exists();
    }

    /** Query parameters only; GET bodies cannot widen participant scope. */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'required', 'string', 'in:pending,closed'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->query->all()), ['page', 'per_page', 'status']) !== []) {
                $validator->errors()->add('query', 'Only page, per_page and status are supported.');
            }
        }];
    }
}
