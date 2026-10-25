<?php

namespace Database\Factories;

use App\Models\ProviderApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderApplication> */
class ProviderApplicationFactory extends Factory
{
    public function definition(): array
    {
        return ['applicant_user_id' => User::factory()->providerApplicant(), 'business_name' => 'Fictitious provider',
            'business_location' => 'QA location', 'application_message' => 'We wish to list commercial properties for our business.',
            'status' => 'pending', 'submitted_at' => now()];
    }

    /** Trusted test fixture, not a runtime approval mechanism. */
    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => 'approved', 'decided_at' => now(), 'decided_by' => User::factory()->administrator()])
            ->afterCreating(function (ProviderApplication $application): void {
                $application->applicant->forceFill(['role' => 'property_provider'])->save();
            });
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => 'rejected', 'decided_at' => now(), 'decided_by' => User::factory()->administrator(), 'public_reason' => 'Application requires additional review.']);
    }
}
