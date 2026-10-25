<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\InquiryStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InquiryStatusChange> */
class InquiryStatusChangeFactory extends Factory
{
    /** Test fixtures only; production status/audit writes belong to InquiryStatusService. */
    public function definition(): array
    {
        return [
            'inquiry_id' => Inquiry::factory()->state(['status' => 'closed']),
            'previous_status' => 'pending', 'new_status' => 'closed',
            'actor_id' => fn (array $attributes): int => Inquiry::findOrFail($attributes['inquiry_id'])->provider_id,
            'changed_at' => now(),
        ];
    }
}
