<?php

namespace App\Services;

use App\Exceptions\OnboardingException;
use App\Models\OnboardingAuditEvent;
use App\Models\OnboardingNotification;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailVerificationService
{
    /** Caller holds the user lock (or owns the newly inserted user) before touching outbox rows. */
    public function enqueue(User $user): void
    {
        if ($user->hasVerifiedEmail() || OnboardingNotification::query()->where('subject_user_id', $user->id)
            ->where('notification_kind', 'email_verification')->whereNull('sent_at')->where('attempt_count', '<', 5)->exists()) {
            return;
        }
        (new OnboardingNotification)->forceFill([
            'subject_user_id' => $user->id, 'notification_kind' => 'email_verification',
            'deduplication_key' => (string) Str::uuid(), 'next_attempt_at' => now(),
        ])->save();
    }

    public function resend(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->enqueue($user);
        }, attempts: 3);
    }

    /** Signature/origin checked at HTTP boundary; participant and current email checked again under lock. */
    public function verify(User $actor, string $id, string $hash): void
    {
        DB::transaction(function () use ($actor, $id, $hash): void {
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ((string) $user->id !== $id || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                throw new OnboardingException(403, 'VERIFICATION_LINK_INVALID', 'The verification link is invalid or expired.');
            }
            if ($user->hasVerifiedEmail()) {
                return;
            }
            $user->forceFill(['email_verified_at' => now()])->save();
            (new OnboardingAuditEvent)->forceFill([
                'subject_user_id' => $user->id, 'actor_user_id' => $user->id,
                'event' => 'email_verified', 'new_state' => 'verified', 'occurred_at' => now(),
            ])->save();
            DB::afterCommit(fn () => event(new Verified($user)));
        }, attempts: 3);
    }
}
