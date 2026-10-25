<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'onboarding_origin' => 'provisioned',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** Trusted test fixtures only; never use factory states for public registration. */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::ADMINISTRATOR]);
    }

    public function propertyProvider(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::PROPERTY_PROVIDER]);
    }

    public function businessUser(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::BUSINESS_USER]);
    }

    /** Public-applicant fixture: verified email still confers no business/provider area access. */
    public function providerApplicant(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::BUSINESS_USER, 'onboarding_origin' => 'self_provider']);
    }

    public function selfRegisteredBusiness(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::BUSINESS_USER, 'onboarding_origin' => 'self_business']);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
