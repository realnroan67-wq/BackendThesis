<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** Role is intentionally excluded from Fillable; public input cannot assign it. */
    protected $attributes = ['role' => 'business_user', 'onboarding_origin' => 'self_business'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /** Normalize identity only, never contact snapshot emails. */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value, " \t\r\n"));
    }

    /** @return HasOne<ProviderApplication, $this> */
    public function providerApplication(): HasOne
    {
        return $this->hasOne(ProviderApplication::class, 'applicant_user_id');
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'provider_id');
    }

    /** @return HasMany<Inquiry, $this> */
    public function sentInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'business_user_id');
    }

    /** Historical recipients are not an authorization scope. @return HasMany<Inquiry, $this> */
    public function historicallyReceivedInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'provider_id');
    }

    /** @return HasMany<InquiryProviderGrant, $this> */
    public function inquiryProviderGrants(): HasMany
    {
        return $this->hasMany(InquiryProviderGrant::class, 'provider_id');
    }
}
