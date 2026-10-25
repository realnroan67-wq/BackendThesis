<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\AccountEligibility;
use Database\Factories\InquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CURRENT: InquiryService creates records, initial grants and retry results atomically.
 * Historical recipient never determines current provider access by itself.
 * SECURITY: Creation derives identities and validates the shared contact contract;
 * read endpoints reuse participant scopes; frontend release remains default-disabled.
 */
#[Fillable(['business_name', 'contact_name', 'contact_email', 'contact_phone', 'message'])]
class Inquiry extends Model
{
    /** @use HasFactory<InquiryFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending'];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function historicalProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /** @return HasMany<InquiryProviderGrant, $this> */
    public function providerGrants(): HasMany
    {
        return $this->hasMany(InquiryProviderGrant::class);
    }

    /** @param Builder<Inquiry> $query
     * @return Builder<Inquiry>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->exists) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $participants) use ($user): void {
            $participants->where(function (Builder $sender) use ($user): void {
                $sender->where('business_user_id', $user->getKey())->whereExists(
                    AccountEligibility::constrain(User::query()->whereKey($user->getKey()), UserRole::BUSINESS_USER)->selectRaw('1')->toBase(),
                );
            })->orWhere(fn (Builder $provider): Builder => $provider->forProvider($user));
        });
    }

    /** @param Builder<Inquiry> $query
     * @return Builder<Inquiry>
     */
    public function scopeForProvider(Builder $query, User $user): Builder
    {
        if (! $user->exists) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(AccountEligibility::constrain(User::query()->whereKey($user->getKey()), UserRole::PROPERTY_PROVIDER)->selectRaw('1')->toBase())
            ->whereHas('providerGrants', fn (Builder $grants): Builder => $grants->active()->where('provider_id', $user->getKey()));
    }
}
