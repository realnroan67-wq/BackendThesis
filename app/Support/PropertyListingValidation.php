<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

/** Validate trusted authoring snapshots, never authorize publishing, images or ownership. */
class PropertyListingValidation
{
    public const FIELDS = ['title', 'location', 'address', 'address_is_public', 'property_type', 'transaction_type', 'price_amount', 'price_period', 'price_currency', 'area_sqm', 'description', 'features'];

    public function validate(array $input): array
    {
        $nonblank = 'not_regex:/\A[ \t\r\n]*\z/u';
        $decimal = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) && ! is_int($value)) {
                $fail('Use an exact decimal string or whole integer, not floating point.');
            }
        };
        $rules = [
            'title' => ['required', 'string', 'max:255', $nonblank],
            'location' => ['nullable', 'string', 'max:150', $nonblank],
            'address' => ['nullable', 'string', 'max:255', $nonblank],
            'address_is_public' => ['sometimes', 'boolean'],
            'property_type' => ['nullable', 'string', 'in:office,retail,warehouse,commercial-building'],
            'transaction_type' => ['nullable', 'string', 'in:sale,lease'],
            'price_amount' => ['nullable', $decimal, 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?\z/'],
            'price_period' => ['nullable', 'string', 'in:total,month'],
            'price_currency' => ['sometimes', 'required', 'string', 'in:PHP'],
            'area_sqm' => ['nullable', $decimal, 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:10000', $nonblank],
            'features' => ['sometimes', 'array', 'list', 'max:20'],
            'features.*' => ['required', 'string', 'max:150', $nonblank],
        ];
        $validator = Validator::make($input, $rules);
        $validator->after(function (LaravelValidator $validator) use ($input): void {
            if (array_diff(array_keys($input), self::FIELDS) !== []) {
                $validator->errors()->add('payload', 'Unknown listing fields are not allowed.');
            }
            foreach (['title', 'location', 'address', 'property_type', 'transaction_type', 'price_amount', 'price_currency', 'price_period', 'area_sqm', 'description'] as $field) {
                if (isset($input[$field]) && is_string($input[$field]) && trim($input[$field], " \t\r\n") === '') {
                    $validator->errors()->add($field, 'Use a nonblank value or null for an unknown optional fact.');
                }
            }
            if (array_key_exists('features', $input) && (! is_array($input['features']) || ! array_is_list($input['features']))) {
                $validator->errors()->add('features', 'Use a list of feature strings.');
            }
            $amount = $input['price_amount'] ?? null;
            $period = $input['price_period'] ?? null;
            $transaction = $input['transaction_type'] ?? null;
            if (($amount !== null || $period !== null) && ! ($amount !== null &&
                (($transaction === 'sale' && $period === 'total') || ($transaction === 'lease' && $period === 'month')))) {
                $validator->errors()->add('price_period', 'Use a sale total or monthly lease price with its amount.');
            }
            if (($input['address_is_public'] ?? false) && ($input['address'] ?? null) === null) {
                $validator->errors()->add('address', 'A public address requires an approved address value.');
            }
        });

        return $validator->validate();
    }
}
