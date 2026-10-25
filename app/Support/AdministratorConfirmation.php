<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Exceptions\OnboardingException;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/** Server session only; never accept confirmation timestamps, actor IDs or credentials from decision payloads. */
class AdministratorConfirmation
{
    public function authorize(User $actor): void
    {
        if (! AccountEligibility::allows($actor, UserRole::ADMINISTRATOR) || ! Gate::forUser($actor)->allows('review-provider-applications')) {
            throw new OnboardingException(403, 'FORBIDDEN', 'Action forbidden.');
        }
    }

    public function confirm(User $actor, Session $session, #[\SensitiveParameter] string $password): void
    {
        $proof = DB::transaction(function () use ($actor, $password): array {
            $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($current);
            if (! Hash::check($password, $current->password)) {
                throw new OnboardingException(422, 'PASSWORD_CONFIRMATION_FAILED', 'Password confirmation failed.');
            }

            return ['user_id' => $current->id, 'confirmed_at' => now()->timestamp, 'credential' => $this->fingerprint($current)];
        });
        $session->regenerate(true);
        $session->put('onboarding.administrator_confirmation', $proof);
    }

    /** Called again on the locked actor, including authorized no-ops. */
    public function ensureFresh(User $actor, Session $session): void
    {
        $this->authorize($actor);
        $proof = $session->get('onboarding.administrator_confirmation');
        if (! is_array($proof) || ($proof['user_id'] ?? null) !== $actor->id
            || ! is_int($proof['confirmed_at'] ?? null) || $proof['confirmed_at'] > now()->timestamp
            || now()->timestamp - $proof['confirmed_at'] > 600
            || ! is_string($proof['credential'] ?? null) || ! hash_equals($this->fingerprint($actor), $proof['credential'])) {
            throw new OnboardingException(403, 'REAUTHENTICATION_REQUIRED', 'Confirm your password to continue.');
        }
    }

    private function fingerprint(User $actor): string
    {
        return hash_hmac('sha256', $actor->password, config('app.key'));
    }
}
