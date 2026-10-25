<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InquiryReadTest extends TestCase
{
    use DatabaseTransactions;

    private const FIELDS = ['id', 'property_id', 'business_user_id', 'provider_id', 'business_name', 'contact_name', 'contact_email', 'contact_phone', 'message', 'status', 'created_at', 'updated_at'];

    /** Each simulated session gets fresh guards; Sanctum caches its resolved actor. */
    private function signIn(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    #[DataProvider('readPaths')]
    public function test_guests_receive_safe_json_401_even_without_accept_headers(string $path): void
    {
        $this->get($path)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function readPaths(): array
    {
        return [['/api/v1/inquiries'], ['/api/v1/inquiries/1'], ['/api/v1/provider/inquiries']];
    }

    public function test_sender_history_is_scoped_before_count_filter_and_pagination_with_stable_order(): void
    {
        $first = Inquiry::factory()->create(['created_at' => '2026-10-18 12:00:00+00']);
        $second = Inquiry::factory()->create(['business_user_id' => $first->business_user_id, 'created_at' => $first->created_at]);
        $old = Inquiry::factory()->create(['business_user_id' => $first->business_user_id, 'created_at' => '2026-10-17 12:00:00+00', 'status' => 'closed']);
        Inquiry::factory()->count(3)->create();
        $this->signIn($first->sender);
        $page = $this->getJson('/api/v1/inquiries?per_page=1')->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 1, 'total' => 3, 'last_page' => 3])
            ->assertJsonPath('data.0.id', $second->id)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['data', 'meta'], array_keys($page->json()));
        $this->assertSame(self::FIELDS, array_keys($page->json('data.0')));
        $this->getJson('/api/v1/inquiries?page=2&per_page=1')->assertOk()->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/inquiries?status=closed&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/v1/inquiries?status=pending')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_defaults_bounds_empty_and_arbitrarily_distant_pages_follow_the_contract(): void
    {
        $sender = User::factory()->businessUser()->create();
        $this->signIn($sender)->getJson('/api/v1/inquiries')->assertOk()->assertExactJson([
            'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1],
        ]);
        Inquiry::factory()->count(21)->create(['business_user_id' => $sender->id]);
        $this->getJson('/api/v1/inquiries')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/inquiries?per_page=100')->assertOk()->assertJsonCount(21, 'data')->assertJsonPath('meta.last_page', 1);
        $this->getJson('/api/v1/inquiries?page=10001')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 21);
        $this->getJson('/api/v1/inquiries?page='.PHP_INT_MAX)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 21);
    }

    public function test_detail_allowlist_and_indistinguishable_missing_or_out_of_scope_errors(): void
    {
        $own = Inquiry::factory()->create(['business_name' => 'QA business', 'contact_phone' => '+639171234567']);
        $other = Inquiry::factory()->create(['contact_email' => 'private-other@example.test']);
        $this->signIn($own->sender);
        $response = $this->getJson('/api/v1/inquiries/'.$own->id)->assertOk()->assertJsonPath('data.contact_email', $own->contact_email)
            ->assertJsonPath('data.business_name', 'QA business')->assertJsonPath('data.provider_id', $own->provider_id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(self::FIELDS, array_keys($response->json('data')));
        $otherResponse = $this->getJson('/api/v1/inquiries/'.$other->id)->assertNotFound()->assertJsonPath('code', 'INQUIRY_NOT_FOUND');
        $this->getJson('/api/v1/inquiries/9007199254740991')->assertNotFound()->assertExactJson($otherResponse->json());
        $this->assertStringNotContainsString('private-other', $otherResponse->getContent());
    }

    public function test_provider_inbox_and_detail_follow_active_grants_including_explicit_nonhistorical_participants(): void
    {
        $granted = Inquiry::factory()->withRecipientGrant()->create(['status' => 'closed']);
        $provider = $granted->historicalProvider;
        $explicit = Inquiry::factory()->create();
        InquiryProviderGrant::factory()->for($explicit)->create(['provider_id' => $provider->id]);
        Inquiry::factory()->create(['provider_id' => $provider->id]);
        Inquiry::factory()->withRecipientGrant()->create();
        $this->signIn($provider)->getJson('/api/v1/provider/inquiries?per_page=1&status=pending')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $explicit->id);
        $this->getJson('/api/v1/provider/inquiries')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/v1/inquiries/'.$granted->id)->assertOk()->assertJsonPath('data.status', 'closed');
        $response = $this->getJson('/api/v1/inquiries/'.$explicit->id)->assertOk();
        $this->assertSame(self::FIELDS, array_keys($response->json('data')));
        $this->assertNotSame($provider->id, $response->json('data.provider_id'));
        $this->assertStringNotContainsString('revocation', $response->getContent());
        $this->assertStringNotContainsString('granted_at', $response->getContent());
        $this->getJson('/api/v1/inquiries')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_provider_pagination_counts_only_active_grants_before_selecting_each_page(): void
    {
        $first = Inquiry::factory()->withRecipientGrant()->create(['created_at' => '2026-10-18 12:00:00+00']);
        $authorized = Inquiry::factory()->withRecipientGrant()->count(2)->create([
            'property_id' => $first->property_id, 'provider_id' => $first->provider_id, 'created_at' => $first->created_at,
        ]);
        Inquiry::factory()->count(3)->create(['property_id' => $first->property_id, 'provider_id' => $first->provider_id]);
        $this->signIn($first->historicalProvider);
        $page = $this->getJson('/api/v1/provider/inquiries?per_page=2')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)->assertJsonCount(2, 'data');
        $this->assertSame($authorized->reverse()->pluck('id')->values()->all(), array_column($page->json('data'), 'id'));
        $this->getJson('/api/v1/provider/inquiries?per_page=2&page=2')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }

    public function test_historical_recipient_without_grant_and_future_grants_are_denied(): void
    {
        $inquiry = Inquiry::factory()->create();
        $provider = $inquiry->historicalProvider;
        $this->signIn($provider)->getJson('/api/v1/inquiries/'.$inquiry->id)->assertNotFound();
        InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $provider->id, 'granted_at' => now()->addDay()]);
        $this->getJson('/api/v1/inquiries/'.$inquiry->id)->assertNotFound()->assertJsonPath('code', 'INQUIRY_NOT_FOUND');
        $this->getJson('/api/v1/provider/inquiries')->assertOk()->assertExactJson([
            'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1],
        ]);
    }

    public function test_revocation_is_observed_on_the_next_request_even_with_cached_grants(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $inquiry->load('providerGrants');
        $provider = $inquiry->historicalProvider;
        $this->signIn($provider)->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk();
        $this->getJson('/api/v1/provider/inquiries')->assertOk()->assertJsonPath('meta.total', 1);
        $inquiry->providerGrants->first()->forceFill([
            'revoked_at' => now(), 'revoked_by' => $provider->id, 'revocation_reason' => 'QA access withdrawn',
        ])->save();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id)->assertNotFound()->assertJsonPath('code', 'INQUIRY_NOT_FOUND');
        $this->getJson('/api/v1/provider/inquiries')->assertOk()->assertJsonPath('meta.total', 0);
        $this->signIn($inquiry->sender)->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk();
    }

    public function test_transfer_and_unpublishing_preserve_sender_history_but_do_not_grant_the_new_owner_access(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $newOwner = User::factory()->propertyProvider()->create();
        DB::table('properties')->where('id', $inquiry->property_id)->update(['provider_id' => $newOwner->id, 'is_published' => false]);
        $this->signIn($inquiry->sender)->getJson('/api/v1/inquiries')->assertOk()->assertJsonPath('data.0.id', $inquiry->id);
        $this->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk()->assertJsonPath('data.provider_id', $inquiry->provider_id);
        $this->signIn($newOwner)->getJson('/api/v1/inquiries/'.$inquiry->id)->assertNotFound();
        $this->getJson('/api/v1/provider/inquiries')->assertOk()->assertJsonPath('meta.total', 0);
        $this->signIn($inquiry->historicalProvider)->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk();
        $this->assertSame($inquiry->provider_id, $inquiry->fresh()->provider_id);
        $this->assertSame(1, $inquiry->providerGrants()->count());
    }

    public function test_stored_role_changes_override_cached_authenticated_roles(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $provider = $inquiry->historicalProvider;
        $this->signIn($provider)->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk();
        User::query()->whereKey($provider->id)->update(['role' => UserRole::BUSINESS_USER]);
        $this->getJson('/api/v1/provider/inquiries')->assertForbidden();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id)->assertNotFound();
        $this->getJson('/api/v1/inquiries')->assertOk()->assertJsonPath('meta.total', 0);
        User::query()->whereKey($provider->id)->update(['role' => UserRole::ADMINISTRATOR]);
        $this->getJson('/api/v1/inquiries/'.$inquiry->id)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_is_denied_even_with_an_explicit_provider_grant_and_business_users_cannot_open_provider_inbox(): void
    {
        $inquiry = Inquiry::factory()->create();
        $admin = User::factory()->administrator()->create();
        InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $admin->id]);
        foreach (['/api/v1/inquiries', '/api/v1/provider/inquiries', '/api/v1/inquiries/'.$inquiry->id] as $path) {
            $this->signIn($admin)->getJson($path)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        }
        $this->signIn($inquiry->sender)->getJson('/api/v1/provider/inquiries')->assertForbidden();
        $this->getJson('/api/v1/provider/inquiries?per_page=0')->assertForbidden();
    }

    public function test_get_bodies_cannot_override_filters_pagination_or_identity(): void
    {
        $own = Inquiry::factory()->create();
        Inquiry::factory()->create(['business_user_id' => $own->business_user_id, 'status' => 'closed']);
        $other = Inquiry::factory()->create();
        $this->signIn($own->sender)->json('GET', '/api/v1/inquiries?per_page=1&status=pending', [
            'business_user_id' => $other->business_user_id, 'per_page' => 100, 'status' => 'closed', 'page' => 2,
        ])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.current_page', 1)->assertJsonPath('data.0.id', $own->id);
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_or_identity_bearing_query_parameters_fail_closed_on_both_lists(string $query, string $field): void
    {
        $sender = User::factory()->businessUser()->create();
        $provider = User::factory()->propertyProvider()->create();
        foreach ([[$sender, '/api/v1/inquiries'], [$provider, '/api/v1/provider/inquiries']] as [$user, $path]) {
            $this->signIn($user)->getJson($path.'?'.$query)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
                ->assertJsonValidationErrors($field)->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public static function invalidQueries(): array
    {
        return [
            ['page=0', 'page'], ['page=-1', 'page'], ['page=1.5', 'page'], ['page[]=1', 'page'], ['page=', 'page'],
            ['page=999999999999999999999999999', 'page'], ['per_page=0', 'per_page'], ['per_page=101', 'per_page'],
            ['per_page[]=1', 'per_page'], ['per_page=', 'per_page'], ['status=responded', 'status'], ['status[]=pending', 'status'], ['status=', 'status'],
            ['provider_id=1', 'query'], ['business_user_id=1', 'query'], ['include=providerGrants', 'query'], ['sort=id', 'query'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_malformed_and_unsafe_detail_identifiers_use_safe_404(string $id): void
    {
        $sender = User::factory()->businessUser()->create();
        $this->signIn($sender)->getJson('/api/v1/inquiries/'.$id)->assertNotFound()->assertJsonPath('code', 'INQUIRY_NOT_FOUND')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function invalidIds(): array
    {
        return array_map(fn (string $id): array => [$id], ['0', '-1', '01', '1.0', '1e2', 'abc', '9007199254740992', str_repeat('9', 100)]);
    }

    public function test_reads_leave_all_history_timestamps_grants_and_statuses_unchanged_and_do_not_expose_contacts_publicly(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $inquiry->property->forceFill(['is_published' => true])->save();
        $before = $inquiry->fresh()->getRawOriginal();
        $grantBefore = $inquiry->providerGrants()->first()->getRawOriginal();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/\A\s*(?:insert|update|delete|alter|create|drop)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $this->signIn($inquiry->sender)->getJson('/api/v1/inquiries')->assertOk();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk();
        $this->signIn($inquiry->historicalProvider)->getJson('/api/v1/provider/inquiries')->assertOk();
        foreach (['/api/v1/properties', '/api/v1/properties/'.$inquiry->property_id] as $path) {
            $public = $this->getJson($path)->assertOk();
            $this->assertStringNotContainsString($inquiry->contact_email, $public->getContent());
            $this->assertStringNotContainsString('business_user_id', $public->getContent());
        }
        $this->assertSame([], $writes);
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertSame($grantBefore, $inquiry->providerGrants()->first()->getRawOriginal());
        $this->assertSame(0, DB::table('inquiry_idempotency_results')->count());
    }

    public function test_unknown_detail_includes_and_general_mutations_are_rejected_and_registration_stays_unavailable(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $this->signIn($inquiry->sender)->getJson('/api/v1/inquiries/'.$inquiry->id.'?include=providerGrants')
            ->assertUnprocessable()->assertJsonValidationErrors('query');
        $this->patchJson('/api/v1/inquiries/'.$inquiry->id, ['status' => 'closed'])->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
        $this->signIn($inquiry->historicalProvider)->patchJson('/api/v1/provider/inquiries/'.$inquiry->id.'/status', ['status' => 'pending'])->assertUnprocessable();
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->postJson('/register')->assertStatus(409)->assertJsonPath('code', 'ALREADY_AUTHENTICATED');
    }

    public function test_read_errors_hide_contact_and_database_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $inquiry = Inquiry::factory()->create();
        $this->signIn($inquiry->sender);
        Inquiry::retrieved(function (): void {
            throw new RuntimeException('PRIVATE CONTACTS SQL AND DATABASE PASSWORD');
        });
        foreach (['/api/v1/inquiries', '/api/v1/inquiries/'.$inquiry->id] as $path) {
            $response = $this->getJson($path)->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');
            $this->assertSame(['message', 'code'], array_keys($response->json()));
            $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        }
    }
}
