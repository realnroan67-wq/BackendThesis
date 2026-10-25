<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\OnboardingException;
use App\Models\OnboardingAuditEvent;
use App\Models\OnboardingNotification;
use App\Models\ProviderApplication;
use App\Models\User;
use App\Support\AccountEligibility;
use App\Support\AdministratorConfirmation;
use App\Support\ProviderApplicationValidation;
use App\Support\RegistrationGate;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderApplicationService
{
    public function authorizeApplicant(User $actor): void
    {
        $current = User::query()->findOrFail($actor->id);
        if ($current->onboarding_origin !== 'self_provider') {
            throw new OnboardingException(403, 'FORBIDDEN', 'Action forbidden.');
        }
        if (! $current->hasVerifiedEmail()) {
            throw new OnboardingException(403, 'EMAIL_VERIFICATION_REQUIRED', 'Verify your email before applying.');
        }
        if ($current->role !== UserRole::BUSINESS_USER && ! AccountEligibility::allows($current, UserRole::PROPERTY_PROVIDER)) {
            throw new OnboardingException(403, 'FORBIDDEN', 'Action forbidden.');
        }
    }

    /** @return array{application: ProviderApplication, created: bool} */
    public function submit(User $actor, array $payload): array
    {
        return DB::transaction(function () use ($actor, $payload): array {
            $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorizeApplicant($current);
            RegistrationGate::ensureEnabled();
            $data = app(ProviderApplicationValidation::class)->application($payload);
            $existing = ProviderApplication::query()->where('applicant_user_id', $current->id)->first();
            if ($existing) {
                foreach ($data as $field => $value) {
                    if ($existing->{$field} !== $value) {
                        throw new OnboardingException(409, 'APPLICATION_ALREADY_EXISTS', 'An application already exists.');
                    }
                }

                return ['application' => $existing, 'created' => false];
            }
            $application = (new ProviderApplication)->forceFill($data + [
                'applicant_user_id' => $current->id, 'status' => 'pending', 'submitted_at' => now(),
            ]);
            $application->save();

            return ['application' => $application, 'created' => true];
        }, attempts: 3);
    }

    /** Review scope is applications from public provider onboarding, never unrelated user records. */
    public function reviewQuery(User $actor): Builder
    {
        app(AdministratorConfirmation::class)->authorize($actor);

        return ProviderApplication::query()->whereHas('applicant', fn (Builder $query): Builder => $query->where('onboarding_origin', 'self_provider'));
    }

    public function findForReview(User $actor, string $id): ProviderApplication
    {
        $application = $this->reviewQuery($actor)->whereKey($this->id($id))->with('applicant')->first();
        if (! $application) {
            $this->notFound();
        }

        return $application;
    }

    public function decide(User $actor, Session $session, string $id, array $payload): ProviderApplication
    {
        $confirmation = app(AdministratorConfirmation::class);
        $confirmation->ensureFresh($actor, $session);
        $data = app(ProviderApplicationValidation::class)->decision($payload);
        $candidate = $this->findForReview($actor, $id);

        // All user rows in ascending ID order, then application, then audit/outbox.
        // This also serializes approval with verification/resend and other decisions.
        return DB::transaction(function () use ($actor, $session, $candidate, $data, $confirmation): ProviderApplication {
            $users = User::query()->whereIn('id', [$actor->id, $candidate->applicant_user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $users->get($actor->id);
            $applicant = $users->get($candidate->applicant_user_id);
            if (! $currentActor || ! $applicant || $actor->id === $applicant->id) {
                throw new OnboardingException(403, 'FORBIDDEN', 'Action forbidden.');
            }
            $confirmation->ensureFresh($currentActor, $session);
            $application = ProviderApplication::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($applicant->onboarding_origin !== 'self_provider') {
                $this->notFound();
            }
            if ($application->status !== 'pending') {
                if ($application->status !== $data['decision'] || $application->public_reason !== $data['public_reason']) {
                    throw new OnboardingException(409, 'APPLICATION_DECISION_CONFLICT', 'The application already has a different final decision.');
                }

                return $application;
            }
            if (! $applicant->hasVerifiedEmail() || $applicant->role !== UserRole::BUSINESS_USER) {
                throw new OnboardingException(403, 'FORBIDDEN', 'Action forbidden.');
            }
            if ($data['decision'] === 'approved') {
                $applicant->forceFill(['role' => UserRole::PROPERTY_PROVIDER])->save();
            }
            $application->forceFill([
                'status' => $data['decision'], 'decided_by' => $currentActor->id,
                'decided_at' => now(), 'public_reason' => $data['public_reason'],
            ])->save();
            (new OnboardingAuditEvent)->forceFill([
                'subject_user_id' => $applicant->id, 'application_id' => $application->id,
                'actor_user_id' => $currentActor->id, 'event' => 'provider_'.$data['decision'],
                'old_state' => 'pending', 'new_state' => $data['decision'], 'occurred_at' => now(),
            ])->save();
            // Durable intent commits atomically; operational delivery only sees committed decisions.
            (new OnboardingNotification)->forceFill([
                'subject_user_id' => $applicant->id, 'application_id' => $application->id,
                'notification_kind' => 'provider_decision', 'deduplication_key' => (string) Str::uuid(),
                'next_attempt_at' => now(),
            ])->save();

            return $application;
        }, attempts: 3);
    }

    private function id(string $id): int
    {
        if (! ctype_digit($id) || strlen($id) > 16 || (int) $id < 1 || (int) $id > 9007199254740991) {
            $this->notFound();
        }

        return (int) $id;
    }

    private function notFound(): never
    {
        throw new OnboardingException(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
    }
}
