<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\InquiryException;
use App\Http\Resources\InquiryResource;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\InquiryStatusChange;
use App\Models\User;
use App\Support\AccountEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * CURRENT: pending -> closed only; repeated close is an authorized no-op.
 * SECURITY: Lock order is actor, inquiry, grant. Hold locks through status/audit
 * commit so role changes, concurrent closes and grant revocation serialize.
 */
class InquiryStatusService
{
    public function close(User $user, string $id, string $status): array
    {
        if ($status !== 'closed') {
            throw ValidationException::withMessages(['status' => ['Only closed is supported.']]);
        }

        return DB::transaction(function () use ($user, $id): array {
            $actor = User::query()->whereKey($user->getKey())->sharedLock()->first();
            if ($actor === null || ! AccountEligibility::allows($actor, UserRole::PROPERTY_PROVIDER)) {
                throw new InquiryException(403, 'FORBIDDEN', 'Action forbidden.');
            }
            $validId = preg_match('/\A[1-9][0-9]{0,15}\z/', $id) === 1
                && (strlen($id) < 16 || strcmp($id, '9007199254740991') <= 0);
            $inquiry = $validId ? Inquiry::query()->whereKey($id)->lockForUpdate()->first() : null;
            if ($inquiry === null) {
                throw new InquiryException(404, 'INQUIRY_NOT_FOUND', 'Resource unavailable.');
            }
            $grant = InquiryProviderGrant::query()->where('inquiry_id', $inquiry->id)
                ->where('provider_id', $actor->id)->active()->lockForUpdate()->first();
            if ($grant === null || ! Gate::forUser($actor)->allows('updateStatus', $inquiry)) {
                throw new InquiryException(404, 'INQUIRY_NOT_FOUND', 'Resource unavailable.');
            }
            if ($inquiry->status === 'pending') {
                $changedAt = now();
                $inquiry->forceFill(['status' => 'closed', 'updated_at' => $changedAt])->save();
                $change = new InquiryStatusChange;
                $change->forceFill([
                    'inquiry_id' => $inquiry->id, 'previous_status' => 'pending', 'new_status' => 'closed',
                    'actor_id' => $actor->id, 'changed_at' => $changedAt,
                ])->save();
            } elseif ($inquiry->status !== 'closed') {
                throw ValidationException::withMessages(['status' => ['This transition is not supported.']]);
            }

            // Validate serialization before commit; no audit metadata enters the resource.
            return (new InquiryResource($inquiry))->resolve();
        }, 3);
    }
}
