<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inquiry> */
class InquiryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'business_user_id' => User::factory()->businessUser(),
            'provider_id' => fn (array $attributes): int => Property::findOrFail($attributes['property_id'])->provider_id,
            'business_name' => null,
            'contact_name' => 'Business contact',
            'contact_email' => 'contact@example.test',
            'contact_phone' => null,
            'message' => 'We would like to arrange a viewing for our business.',
            'status' => 'pending',
        ];
    }

    /** Test fixtures only; production creation transaction belongs to the next milestone. */
    public function withRecipientGrant(): static
    {
        return $this->afterCreating(function (Inquiry $inquiry): void {
            InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $inquiry->provider_id]);
        });
    }
}
