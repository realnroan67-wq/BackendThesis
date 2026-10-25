<?php

namespace App\Http\Requests;

use App\Models\Inquiry;
use App\Support\InquirySubmissionGate;
use App\Support\InquiryValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use stdClass;

class StoreInquiryRequest extends FormRequest
{
    private array $inquiryPayload = [];

    private string $inquiryKey = '';

    public function authorize(): bool
    {
        if (! Gate::allows('create', Inquiry::class)) {
            return false;
        }
        InquirySubmissionGate::ensureEnabled();

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->isJson() || ! (json_decode($this->getContent()) instanceof stdClass)) {
                $validator->errors()->add('payload', 'Send a JSON object.');

                return;
            }
            $contract = app(InquiryValidation::class);
            try {
                $this->inquiryPayload = $contract->validate($this->json()->all());
                $this->inquiryKey = $contract->key($this->header('Idempotency-Key'));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    public function payload(): array
    {
        return $this->inquiryPayload;
    }

    public function idempotencyKey(): string
    {
        return $this->inquiryKey;
    }
}
