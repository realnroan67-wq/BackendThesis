<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PropertyImage> */
class PropertyImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'storage_path' => fn (array $attributes): string => 'properties/'.$attributes['property_id'].'/'.Str::uuid().'.webp',
            'position' => 0,
            'caption' => null,
            'is_public' => false,
        ];
    }

    /** Fixtures only: this does not create or approve real upload files. */
    public function publiclyApproved(): static
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }
}
