<?php

namespace App\Http\Requests\Auth;

use App\Exceptions\OnboardingException;
use App\Support\RegistrationGate;
use App\Support\RegistrationValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    private array $registrationPayload = [];

    public function authorize(): bool
    {
        if ($this->user('web')) {
            throw new OnboardingException(409, 'ALREADY_AUTHENTICATED', 'Sign out before registering another account.');
        }
        RegistrationGate::ensureEnabled();

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->registrationPayload = app(RegistrationValidation::class)->fromRequest($this);
        }];
    }

    public function payload(): array
    {
        return $this->registrationPayload;
    }
}
