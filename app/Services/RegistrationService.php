<?php

namespace App\Services;

use App\Models\User;
use App\Support\RegistrationAccountFactory;
use App\Support\RegistrationGate;
use App\Support\RegistrationValidation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Atomic account + durable mail intent; no automatic sign-in, Registered listener or delivery claim. */
class RegistrationService
{
    public function register(#[\SensitiveParameter] array $input): void
    {
        RegistrationGate::ensureEnabled();
        $data = app(RegistrationValidation::class)->validate($input);
        // Both new and duplicate requests pay the same bounded password hashing cost.
        $user = app(RegistrationAccountFactory::class)->make($data['name'], $data['email'], $data['password'], $data['account_type']);
        try {
            DB::transaction(function () use ($user): void {
                if (User::query()->where('email', $user->email)->exists()) {
                    return;
                }
                $user->save();
                app(EmailVerificationService::class)->enqueue($user);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent winner is accepted only for this canonical identity; all other constraints fail closed.
            if (! str_contains($exception->errorInfo[2] ?? '', 'users_email_unique')
                || ! User::query()->where('email', $user->email)->exists()) {
                throw $exception;
            }
        }
    }
}
