<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\AccountEligibility;

/** Stored sender scope or active provider grant; never current property ownership. */
class InquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return AccountEligibility::allows($user, UserRole::BUSINESS_USER) || AccountEligibility::allows($user, UserRole::PROPERTY_PROVIDER);
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return $inquiry->exists && Inquiry::query()->visibleTo($user)->whereKey($inquiry->getKey())->exists();
    }

    /** Eligibility to close only; InquiryStatusService locks/rechecks the grant and handles no-op atomically. */
    public function updateStatus(User $user, Inquiry $inquiry): bool
    {
        return $inquiry->exists && Inquiry::query()->forProvider($user)->whereKey($inquiry->getKey())->exists();
    }

    /** Role eligibility only; creation service also locks and rechecks the sender. */
    public function create(User $user): bool
    {
        return AccountEligibility::allows($user, UserRole::BUSINESS_USER);
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        return false;
    }

    public function delete(User $user, Inquiry $inquiry): bool
    {
        return false;
    }
}
