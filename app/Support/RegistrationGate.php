<?php

namespace App\Support;

use App\Exceptions\OnboardingException;

/** Independent server release control; verification and existing-account sessions remain available. */
class RegistrationGate
{
    public static function ensureEnabled(): void
    {
        if (config('features.registration_enabled', false) !== true) {
            throw new OnboardingException(503, 'REGISTRATION_DISABLED', 'Registration is currently unavailable.');
        }
    }
}
