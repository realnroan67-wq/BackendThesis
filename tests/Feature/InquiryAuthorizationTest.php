<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InquiryAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sender_can_view_only_own_records_and_cannot_close_them(): void
    {
        $own = Inquiry::factory()->create();
        $other = Inquiry::factory()->create();
        $sender = $own->sender;
        $this->assertTrue(Gate::forUser($sender)->allows('view', $own));
        $this->assertFalse(Gate::forUser($sender)->allows('view', $other));
        $this->assertFalse(Gate::forUser($sender)->allows('updateStatus', $own));
        $this->assertSame([$own->id], Inquiry::query()->visibleTo($sender)->pluck('id')->all());
        $this->assertTrue($sender->sentInquiries()->first()->is($own));
        $this->assertTrue($own->property->inquiries()->first()->is($own));
    }

    public function test_historical_recipient_without_grant_has_no_current_access(): void
    {
        $inquiry = Inquiry::factory()->create();
        $provider = $inquiry->historicalProvider;
        $this->assertTrue($provider->historicallyReceivedInquiries()->first()->is($inquiry));
        $this->assertFalse(Gate::forUser($provider)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($provider)->allows('updateStatus', $inquiry));
        $this->assertSame([], Inquiry::query()->forProvider($provider)->pluck('id')->all());
    }

    public function test_active_grant_allows_view_and_close_without_modifying_status_by_reading(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $provider = $inquiry->historicalProvider;
        $this->assertTrue(Gate::forUser($provider)->allows('view', $inquiry));
        $this->assertTrue(Gate::forUser($provider)->allows('updateStatus', $inquiry));
        $this->assertSame([$inquiry->id], Inquiry::query()->visibleTo($provider)->pluck('id')->all());
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->assertSame($inquiry->id, $provider->inquiryProviderGrants()->first()->inquiry->id);
        DB::table('inquiries')->where('id', $inquiry->id)->update(['status' => 'closed']);
        $this->assertTrue(Gate::forUser($provider)->allows('updateStatus', $inquiry));
    }

    public function test_revocation_immediately_removes_view_list_and_close_access_with_cached_relations(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $provider = $inquiry->historicalProvider;
        $inquiry->load('providerGrants');
        $grant = $inquiry->providerGrants->first();
        $this->assertTrue(Gate::forUser($provider)->allows('view', $inquiry));
        $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $provider->id, 'revocation_reason' => 'Access withdrawn'])->save();
        $this->assertFalse(Gate::forUser($provider)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($provider)->allows('updateStatus', $inquiry));
        $this->assertSame([], Inquiry::query()->forProvider($provider)->pluck('id')->all());
        $this->assertTrue(Gate::forUser($inquiry->sender)->allows('view', $inquiry));
        $this->assertTrue($grant->fresh()->revokedBy->is($provider));
    }

    public function test_transfer_preserves_original_recipient_grant_and_gives_new_owner_no_access(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $original = $inquiry->historicalProvider;
        $newOwner = User::factory()->propertyProvider()->create();
        DB::table('properties')->where('id', $inquiry->property_id)->update(['provider_id' => $newOwner->id]);
        $this->assertSame($original->id, $inquiry->fresh()->provider_id);
        $this->assertTrue(Gate::forUser($original)->allows('view', $inquiry));
        $this->assertTrue(Gate::forUser($original)->allows('updateStatus', $inquiry));
        $this->assertFalse(Gate::forUser($newOwner)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($newOwner)->allows('updateStatus', $inquiry));
        $this->assertSame(1, $inquiry->providerGrants()->count());
        $this->assertDatabaseMissing('inquiry_provider_grants', ['inquiry_id' => $inquiry->id, 'provider_id' => $newOwner->id]);
    }

    public function test_explicit_grant_is_scoped_to_its_inquiry_and_does_not_rewrite_historical_recipient(): void
    {
        $first = Inquiry::factory()->create();
        $second = Inquiry::factory()->create();
        $provider = User::factory()->propertyProvider()->create();
        InquiryProviderGrant::factory()->for($first)->create(['provider_id' => $provider->id]);
        $this->assertTrue(Gate::forUser($provider)->allows('view', $first));
        $this->assertFalse(Gate::forUser($provider)->allows('view', $second));
        $this->assertFalse(Gate::forUser($provider)->allows('updateStatus', $second));
        $this->assertNotSame($provider->id, $first->fresh()->provider_id);
    }

    public function test_administrator_is_denied_even_with_a_grant(): void
    {
        $inquiry = Inquiry::factory()->create();
        $admin = User::factory()->administrator()->create();
        InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $admin->id]);
        $this->assertFalse(Gate::forUser($admin)->allows('viewAny', Inquiry::class));
        $this->assertFalse(Gate::forUser($admin)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($admin)->allows('updateStatus', $inquiry));
        $this->assertSame([], Inquiry::query()->visibleTo($admin)->pluck('id')->all());
    }

    public function test_grant_does_not_give_another_business_user_access(): void
    {
        $inquiry = Inquiry::factory()->create();
        $other = User::factory()->businessUser()->create();
        InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $other->id]);
        $this->assertFalse(Gate::forUser($other)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($other)->allows('updateStatus', $inquiry));
    }

    public function test_role_change_revokes_provider_access_despite_stale_user_object(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $provider = $inquiry->historicalProvider;
        DB::table('users')->where('id', $provider->id)->update(['role' => 'business_user']);
        $this->assertFalse(Gate::forUser($provider)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($provider)->allows('updateStatus', $inquiry));
    }

    public function test_sender_role_change_removes_sender_access(): void
    {
        $inquiry = Inquiry::factory()->create();
        $sender = $inquiry->sender;
        DB::table('users')->where('id', $sender->id)->update(['role' => 'administrator']);
        $this->assertFalse(Gate::forUser($sender)->allows('view', $inquiry));
    }

    public function test_future_dated_grant_is_not_active_before_its_grant_time(): void
    {
        $this->freezeTime();
        $inquiry = Inquiry::factory()->create();
        InquiryProviderGrant::factory()->for($inquiry)->create(['granted_at' => now()->addHour()]);
        $this->assertFalse(Gate::forUser($inquiry->historicalProvider)->allows('view', $inquiry));
    }

    public function test_forged_in_memory_sender_and_recipient_do_not_grant_access(): void
    {
        $inquiry = Inquiry::factory()->create();
        $other = User::factory()->businessUser()->create();
        $inquiry->business_user_id = $other->id;
        $inquiry->provider_id = $other->id;
        $this->assertFalse(Gate::forUser($other)->allows('view', $inquiry));
    }

    public function test_guests_and_unsaved_records_are_denied_and_only_business_users_are_creation_eligible(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        foreach ([$inquiry->sender, $inquiry->historicalProvider, User::factory()->administrator()->create()] as $user) {
            $gate = Gate::forUser($user);
            $this->assertSame($user->role === UserRole::BUSINESS_USER, $gate->allows('create', Inquiry::class));
            $this->assertFalse($gate->allows('update', $inquiry));
            $this->assertFalse($gate->allows('delete', $inquiry));
        }
        $this->assertFalse(Gate::forUser(null)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser(null)->allows('updateStatus', $inquiry));
        $this->assertFalse(Gate::forUser(User::factory()->businessUser()->make())->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($inquiry->sender)->allows('view', Inquiry::factory()->make()));
    }

    #[DataProvider('immutableInquiryFields')]
    public function test_database_protects_inquiry_identities_and_snapshots(string $field): void
    {
        $inquiry = Inquiry::factory()->create();
        $replacement = match ($field) {
            'provider_id' => User::factory()->propertyProvider()->create()->id,
            'business_user_id' => User::factory()->businessUser()->create()->id,
            'property_id' => Property::factory()->create()->id,
            default => 'Rewritten snapshot message that must not be persisted.',
        };
        $this->expectException(QueryException::class);
        DB::table('inquiries')->where('id', $inquiry->id)->update([$field => $replacement]);
    }

    public static function immutableInquiryFields(): array
    {
        return [['provider_id'], ['business_user_id'], ['property_id'], ['message']];
    }

    public function test_inquiry_status_is_not_publicly_assignable_and_creation_defaults_pending(): void
    {
        $inquiry = Inquiry::factory()->create();
        $inquiry->fill(['status' => 'closed', 'provider_id' => 999999, 'business_user_id' => 999999])->save();
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->assertSame($inquiry->provider_id, $inquiry->fresh()->provider_id);
    }

    public function test_grant_membership_and_revocation_cannot_be_mass_assigned(): void
    {
        $grant = InquiryProviderGrant::factory()->create();
        $this->expectException(MassAssignmentException::class);
        $grant->fill(['revoked_at' => now(), 'provider_id' => 999999]);
    }

    public function test_duplicate_membership_is_rejected(): void
    {
        $grant = InquiryProviderGrant::factory()->create();
        $this->expectException(QueryException::class);
        InquiryProviderGrant::factory()->create(['inquiry_id' => $grant->inquiry_id, 'provider_id' => $grant->provider_id]);
    }

    public function test_revocation_requires_audit_actor_and_reason(): void
    {
        $grant = InquiryProviderGrant::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('inquiry_provider_grants')->where('id', $grant->id)->update(['revoked_at' => now()]);
    }

    public function test_revoked_grant_cannot_be_reactivated_by_raw_update(): void
    {
        $grant = InquiryProviderGrant::factory()->create();
        $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $grant->provider_id, 'revocation_reason' => 'Test revocation'])->save();
        $this->expectException(QueryException::class);
        DB::table('inquiry_provider_grants')->where('id', $grant->id)->update(['revoked_at' => null, 'revoked_by' => null, 'revocation_reason' => null]);
    }

    public function test_grant_cannot_be_repointed_to_another_provider(): void
    {
        $grant = InquiryProviderGrant::factory()->create();
        $other = User::factory()->propertyProvider()->create();
        $this->expectException(QueryException::class);
        DB::table('inquiry_provider_grants')->where('id', $grant->id)->update(['provider_id' => $other->id]);
    }

    public function test_grant_audit_history_cannot_be_deleted(): void
    {
        $grant = InquiryProviderGrant::factory()->create();
        $this->expectException(QueryException::class);
        $grant->delete();
    }

    public function test_inquiry_history_cannot_be_deleted_even_without_a_grant(): void
    {
        $inquiry = Inquiry::factory()->create();
        $this->expectException(QueryException::class);
        $inquiry->delete();
    }

    #[DataProvider('protectedParents')]
    public function test_history_prevents_deleting_referenced_property_or_user(string $relation): void
    {
        $inquiry = Inquiry::factory()->create();
        $this->expectException(QueryException::class);
        $inquiry->{$relation}->delete();
    }

    public static function protectedParents(): array
    {
        return [['property'], ['sender'], ['historicalProvider']];
    }

    #[DataProvider('invalidStatuses')]
    public function test_invalid_or_reopened_status_is_rejected(string $initial, string $next): void
    {
        $inquiry = Inquiry::factory()->create(['status' => $initial]);
        $this->expectException(QueryException::class);
        DB::table('inquiries')->where('id', $inquiry->id)->update(['status' => $next]);
    }

    public static function invalidStatuses(): array
    {
        return [['pending', 'submitted'], ['closed', 'pending']];
    }

    public function test_inquiry_creation_rejects_an_empty_request(): void
    {
        config(['features.inquiry_submission_enabled' => true]);
        $this->actingAs(User::factory()->businessUser()->create(), 'web')
            ->postJson('/api/v1/inquiries', [])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }
}
