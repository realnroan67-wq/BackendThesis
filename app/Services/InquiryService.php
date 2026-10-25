<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\InquiryException;
use App\Http\Resources\InquiryResource;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\Property;
use App\Models\User;
use App\Support\AccountEligibility;
use App\Support\InquirySubmissionGate;
use App\Support\InquiryValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** No notifications or external effects; return success only after the creation transaction commits. */
class InquiryService
{
    public function __construct(private InquiryValidation $validation) {}

    /** @return array{body: string, location: string, replayed: bool} */
    public function create(User $user, array $payload, string $key): array
    {
        Gate::forUser($user)->authorize('create', Inquiry::class);
        InquirySubmissionGate::ensureEnabled();
        $payload = $this->validation->validate($payload);
        $key = $this->validation->key($key);
        ksort($payload);
        $fingerprint = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $payload, $key, $fingerprint): array {
            $scope = json_encode([$user->getKey(), 'createInquiry', $key], JSON_THROW_ON_ERROR);
            $lock = DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', [$scope]);
            if (! $lock->acquired) {
                throw new InquiryException(409, 'IDEMPOTENCY_IN_PROGRESS', 'This inquiry attempt is still in progress.', ['Retry-After' => '1']);
            }
            // Share locks keep role changes from racing a committed creation/replay.
            $sender = User::query()->whereKey($user->getKey())->sharedLock()->first();
            if ($sender === null || ! AccountEligibility::allows($sender, UserRole::BUSINESS_USER)) {
                throw new InquiryException(403, 'FORBIDDEN', 'Only Business Users may create inquiries.');
            }
            $prior = DB::table('inquiry_idempotency_results')->where('business_user_id', $sender->id)
                ->where('operation', 'createInquiry')->where('idempotency_key', $key)->first();
            if ($prior !== null) {
                if (! hash_equals($prior->fingerprint, $fingerprint)) {
                    throw new InquiryException(409, 'IDEMPOTENCY_CONFLICT', 'This key was already used with different inquiry details.');
                }
                if (! Inquiry::query()->visibleTo($sender)->whereKey($prior->inquiry_id)->exists()) {
                    throw new InquiryException(404, 'INQUIRY_NOT_FOUND', 'Inquiry not found.');
                }

                return ['body' => $prior->response_body, 'location' => $prior->location, 'replayed' => true];
            }
            $property = Property::query()->whereKey($payload['property_id'])->lockForUpdate()->first();
            if ($property === null || ! $property->is_published || ! $property->accepts_inquiries) {
                throw new InquiryException(404, 'PROPERTY_UNAVAILABLE', 'Property unavailable.');
            }
            $provider = User::query()->whereKey($property->provider_id)->sharedLock()->first();
            if ($provider === null || ! AccountEligibility::allows($provider, UserRole::PROPERTY_PROVIDER)) {
                throw new InquiryException(404, 'PROPERTY_UNAVAILABLE', 'Property unavailable.');
            }
            $inquiry = new Inquiry;
            $inquiry->fill(array_diff_key($payload, ['property_id' => true]));
            $inquiry->property()->associate($property);
            $inquiry->sender()->associate($sender);
            $inquiry->historicalProvider()->associate($provider);
            $inquiry->save();
            $grant = new InquiryProviderGrant;
            $grant->forceFill(['inquiry_id' => $inquiry->id, 'provider_id' => $provider->id, 'granted_at' => now(), 'granted_by' => null])->save();
            $body = json_encode(['data' => (new InquiryResource($inquiry))->resolve(), 'message' => 'Inquiry submitted successfully.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $location = '/api/v1/inquiries/'.$inquiry->id;
            DB::table('inquiry_idempotency_results')->insert([
                'business_user_id' => $sender->id, 'operation' => 'createInquiry', 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'inquiry_id' => $inquiry->id, 'response_body' => $body,
                'location' => $location, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['body' => $body, 'location' => $location, 'replayed' => false];
        }, 3);
    }
}
