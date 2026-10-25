<?php

namespace App\Console\Commands;

use App\Models\OnboardingNotification;
use App\Models\User;
use App\Services\OnboardingDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DeliverOnboardingNotifications extends Command
{
    protected $signature = 'onboarding:deliver {--limit=20 : Maximum due onboarding intents (1-100)}';

    protected $description = 'Deliver a bounded batch of committed onboarding intents without printing sensitive data';

    public function handle(OnboardingDeliveryService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }
        $ids = OnboardingNotification::query()->where(function (Builder $query): void {
            $query->where('notification_kind', 'provider_decision')->orWhere(function (Builder $verification): void {
                $verification->where('notification_kind', 'email_verification')
                    ->whereIn('subject_user_id', User::query()->select('id')->whereNull('email_verified_at'));
            });
        })
            ->whereNull('sent_at')->where('attempt_count', '<', 5)->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        $accepted = 0;
        foreach ($ids as $id) {
            $accepted += (int) $service->deliver($id);
        }
        $this->info('Processed '.$ids->count().' intents; transport accepted '.$accepted.'. This is not inbox-delivery evidence.');

        return self::SUCCESS;
    }
}
