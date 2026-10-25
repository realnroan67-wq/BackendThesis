<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable intent with bounded operational delivery; contains no rendered body or signed URL. */
class OnboardingNotification extends Model
{
    protected $table = 'onboarding_notification_outbox';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'attempt_count' => 'integer'];
    }
}
