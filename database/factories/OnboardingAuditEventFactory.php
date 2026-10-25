<?php

namespace Database\Factories;

use App\Models\OnboardingAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OnboardingAuditEvent> */
class OnboardingAuditEventFactory extends Factory
{
    public function definition(): array
    {
        return ['subject_user_id' => User::factory(), 'actor_user_id' => fn (array $attributes): int => $attributes['subject_user_id'],
            'event' => 'email_verified', 'old_state' => null, 'new_state' => 'verified', 'occurred_at' => now()];
    }
}
