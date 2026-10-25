<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\AccountEligibility;
use App\Support\PropertyListingValidation;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * PURPOSE: Persist listing ownership independently of frontend mock listings.
 * CURRENT: Trusted code associates a provider; request mass assignment cannot.
 * FUTURE: Publication and ownership-transfer workflows need
 * separately authorized workflows. No property management API is exposed here.
 */
#[Fillable(['title', 'location', 'property_type', 'transaction_type', 'price_amount', 'price_period', 'price_currency', 'area_sqm', 'description', 'features'])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    protected $attributes = ['is_published' => false, 'accepts_inquiries' => false];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'accepts_inquiries' => 'boolean', 'address_is_public' => 'boolean', 'price_amount' => 'decimal:2', 'area_sqm' => 'decimal:2', 'features' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Property $property): void {
            $values = array_intersect_key($property->getAttributes(), array_flip(PropertyListingValidation::FIELDS));
            $values['features'] = array_key_exists('features', $values) ? $property->features : [];
            app(PropertyListingValidation::class)->validate($values);
            if ($property->exists && $property->isDirty('provider_id')) {
                throw ValidationException::withMessages([
                    'provider_id' => 'Ownership transfers require a separately authorized workflow.',
                ]);
            }

            if (! AccountEligibility::constrain(User::query()->whereKey($property->provider_id), UserRole::PROPERTY_PROVIDER)->exists()) {
                throw ValidationException::withMessages([
                    'provider_id' => 'A property must belong to an existing Property Provider.',
                ]);
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * Management scope only, never a public discovery or inquiry-access scope.
     * Recheck the stored provider role so stale user objects cannot retain access.
     *
     * @param  Builder<Property>  $query
     * @return Builder<Property>
     */
    public function scopeOwnedBy(Builder $query, User $provider): Builder
    {
        if (! $provider->exists) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('provider_id', $provider->getKey())
            ->whereHas('provider', fn (Builder $owner): Builder => AccountEligibility::constrain($owner, UserRole::PROPERTY_PROVIDER));
    }

    /**
     * Public discovery needs publication and a current provider owner, not a review label.
     * Inquiry availability is separate: closed-to-inquiry listings can still be browsed.
     *
     * @param  Builder<Property>  $query
     * @return Builder<Property>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('properties.is_published', true)
            ->whereBetween('properties.id', [1, 9007199254740991])
            ->whereHas('provider', fn (Builder $owner): Builder => AccountEligibility::constrain($owner, UserRole::PROPERTY_PROVIDER));
    }

    /** @return HasMany<PropertyImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class);
    }

    /** @return HasMany<PropertyImage, $this> */
    public function publicImages(): HasMany
    {
        return $this->images()->where('is_public', true)->orderBy('position');
    }

    /** @return HasMany<Inquiry, $this> */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }
}
