<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Property> */
class PropertyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => User::factory()->propertyProvider(),
            'title' => fake()->sentence(4),
        ];
    }

    /** Test fixtures only; no publication endpoint is exposed. */
    public function acceptingInquiries(): static
    {
        return $this->state(fn (): array => ['is_published' => true, 'accepts_inquiries' => true]);
    }
}
