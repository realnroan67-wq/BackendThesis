<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InquiryProviderGrant> */
class InquiryProviderGrantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'inquiry_id' => Inquiry::factory(),
            'provider_id' => fn (array $attributes): int => Inquiry::findOrFail($attributes['inquiry_id'])->provider_id,
            'granted_at' => now(),
            'granted_by' => null,
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
        ];
    }
}
