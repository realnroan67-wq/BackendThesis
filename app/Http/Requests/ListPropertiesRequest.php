<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListPropertiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** GET bodies must not alter discovery pagination. */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'required', 'string', 'max:150'],
            'property_type' => ['sometimes', 'required', 'string', 'in:office,retail,warehouse,commercial-building'],
            'transaction_type' => ['sometimes', 'required', 'string', 'in:sale,lease'],
            'max_price' => ['sometimes', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?\z/'],
            'sort' => ['sometimes', 'required', 'string', 'in:recommended,price-low,price-high,name'],
        ];
    }

    /** Reject unsupported filters rather than silently suggesting they were applied. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->query->all()), ['page', 'per_page', 'search', 'property_type', 'transaction_type', 'max_price', 'sort']) !== []) {
                $validator->errors()->add('query', 'Unsupported property search parameter.');
            }
            $query = $this->query->all();
            if ((array_key_exists('max_price', $query) || in_array($query['sort'] ?? null, ['price-low', 'price-high'], true))
                && ! in_array($query['transaction_type'] ?? null, ['sale', 'lease'], true)) {
                $validator->errors()->add('transaction_type', 'Choose sale totals or monthly leases before filtering or sorting prices.');
            }
        }];
    }
}
