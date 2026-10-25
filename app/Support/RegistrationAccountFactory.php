<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/** Server-assigned unsaved account only. R1.2B owns duplicates, persistence and durable mail intents. */
class RegistrationAccountFactory
{
    public function make(string $name, string $email, #[\SensitiveParameter] string $password, string $accountType): User
    {
        RegistrationGate::ensureEnabled();
        $name = trim($name, " \t\r\n");
        $email = strtolower(trim($email, " \t\r\n"));
        Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'account_type' => $accountType], [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254', 'regex:/\A[!-~]+\z/'],
            'password' => ['required', 'string', 'min:15', 'max:128', function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen($value) > 512 || preg_match('//u', $value) !== 1) {
                    $fail('The password must be valid Unicode within 512 bytes.');
                }
            }],
            'account_type' => ['required', 'in:business_user,property_provider'],
        ])->validate();

        return (new User)->forceFill(['name' => $name, 'email' => $email, 'password' => Hash::make($password),
            'role' => 'business_user', 'email_verified_at' => null,
            'onboarding_origin' => $accountType === 'business_user' ? 'self_business' : 'self_provider']);
    }
}
