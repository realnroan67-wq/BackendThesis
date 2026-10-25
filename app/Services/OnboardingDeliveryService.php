<?php

namespace App\Services;

use App\Models\OnboardingNotification;
use App\Models\ProviderApplication;
use App\Models\User;
use App\Notifications\OnboardingVerifyEmail;
use App\Notifications\ProviderApplicationDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Bounded at-least-once delivery. Locks user then intent; only committed intents are polled by the worker. */
class OnboardingDeliveryService
{
    public function deliver(int $id): bool
    {
        $subject = OnboardingNotification::query()->whereKey($id)->value('subject_user_id');
        if ($subject === null) {
            return false;
        }

        return DB::transaction(function () use ($id, $subject): bool {
            $user = User::query()->whereKey($subject)->lockForUpdate()->firstOrFail();
            $intent = OnboardingNotification::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($intent->notification_kind, ['email_verification', 'provider_decision'], true) || $intent->sent_at !== null
                || $intent->attempt_count >= 5 || $intent->next_attempt_at->isFuture()) {
                return false;
            }
            if ($intent->notification_kind === 'email_verification' && $user->hasVerifiedEmail()) {
                // An obsolete intent is neither a delivery attempt nor an exhausted retry.
                return false;
            }
            $intent->attempt_count++;
            try {
                // A log/array/failover mailer must never expose signed URLs or be recorded as delivery.
                if (config('mail.mailers.'.config('mail.default').'.transport') !== 'smtp') {
                    throw new \RuntimeException('Verified SMTP transport is required.');
                }
                $notification = new OnboardingVerifyEmail;
                if ($intent->notification_kind === 'provider_decision') {
                    $application = ProviderApplication::query()->whereKey($intent->application_id)->where('applicant_user_id', $user->id)->firstOrFail();
                    if (! in_array($application->status, ['approved', 'rejected'], true)) {
                        throw new \RuntimeException('Decision is not final.');
                    }
                    $notification = new ProviderApplicationDecision($application->status, $application->public_reason);
                }
                Notification::sendNow($user, $notification);
            } catch (\Throwable) {
                // Never report exception text: transport errors may include recipients, credentials or signed URLs.
                $intent->forceFill([
                    'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** ($intent->attempt_count - 1)))),
                    'last_error_code' => $intent->attempt_count >= 5 ? 'RETRY_EXHAUSTED' : 'DELIVERY_UNAVAILABLE',
                ])->save();

                return false;
            }
            $intent->forceFill(['sent_at' => now(), 'last_error_code' => null])->save();

            return true;
        }, attempts: 1);
    }
}
