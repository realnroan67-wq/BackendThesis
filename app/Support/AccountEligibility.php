<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Query-level eligibility; callers must still enforce ownership and current participant grants. */
class AccountEligibility
{
    public static function constrain(Builder $query, UserRole $role): Builder
    {
        return $query->where('role', $role)->whereNotNull('email_verified_at')
            ->where(function (Builder $origins) use ($role): void {
                $origins->where('onboarding_origin', 'provisioned');
                if ($role === UserRole::BUSINESS_USER) {
                    $origins->orWhere('onboarding_origin', 'self_business');
                } elseif ($role === UserRole::PROPERTY_PROVIDER) {
                    $origins->orWhere(fn (Builder $applicant): Builder => $applicant->where('onboarding_origin', 'self_provider')
                        ->whereHas('providerApplication', fn (Builder $application): Builder => $application->where('status', 'approved')));
                }
            });
    }

    public static function allows(User $user, UserRole $role): bool
    {
        return $user->exists && self::constrain(User::query()->whereKey($user->getKey()), $role)->exists();
    }
}
