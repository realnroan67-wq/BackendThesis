<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\Property;
use App\Models\User;
use App\Support\InquiryValidation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InquiryCreationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.inquiry_submission_enabled' => true]);
    }

    private function payload(Property $property): array
    {
        return ['property_id' => $property->id, 'contact_name' => 'Business Contact', 'contact_email' => 'Contact@Example.test',
            'message' => 'Please arrange a viewing for our expanding business.'];
    }

    public function test_guest_gets_contract_401(): void
    {
        $this->postJson('/api/v1/inquiries', [])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED']);
    }

    #[DataProvider('forbiddenRoles')]
    public function test_non_business_role_gets_403_before_validation_or_property_disclosure(UserRole $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]), 'web')
            ->postJson('/api/v1/inquiries', [])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public static function forbiddenRoles(): array
    {
        return [[UserRole::ADMINISTRATOR], [UserRole::PROPERTY_PROVIDER]];
    }

    public function test_valid_creation_commits_derived_identities_initial_grant_and_exact_resource_fields(): void
    {
        $this->freezeTime();
        $property = Property::factory()->acceptingInquiries()->create();
        $sender = User::factory()->businessUser()->create();
        $response = $this->actingAs($sender, 'web')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated();
        $data = $response->json('data');
        $this->assertSame(['id', 'property_id', 'business_user_id', 'provider_id', 'business_name', 'contact_name', 'contact_email', 'contact_phone', 'message', 'status', 'created_at', 'updated_at'], array_keys($data));
        $response->assertHeader('Location', '/api/v1/inquiries/'.$data['id'])->assertHeaderMissing('Idempotency-Replayed')
            ->assertJsonPath('message', 'Inquiry submitted successfully.');
        $this->assertSame($sender->id, $data['business_user_id']);
        $this->assertSame($property->provider_id, $data['provider_id']);
        $this->assertSame('pending', $data['status']);
        $this->assertNull($data['business_name']);
        $this->assertNull($data['contact_phone']);
        $this->assertSame('Contact@Example.test', $data['contact_email']);
        $this->assertSame(now()->utc()->toRfc3339String(), $data['created_at']);
        $this->assertDatabaseHas('inquiries', ['id' => $data['id'], 'business_user_id' => $sender->id, 'provider_id' => $property->provider_id, 'status' => 'pending']);
        $this->assertDatabaseHas('inquiry_provider_grants', ['inquiry_id' => $data['id'], 'provider_id' => $property->provider_id, 'revoked_at' => null]);
        $this->assertDatabaseHas('inquiry_idempotency_results', ['inquiry_id' => $data['id'], 'business_user_id' => $sender->id]);
        $this->assertNotSame('Business Contact', $sender->fresh()->name);
    }

    #[DataProvider('ineligibleProperties')]
    public function test_unknown_or_ineligible_property_is_indistinguishable_and_does_not_reserve_key(string $state): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        if ($state === 'unpublished') {
            DB::table('properties')->where('id', $property->id)->update(['is_published' => false]);
        }
        if ($state === 'disabled') {
            DB::table('properties')->where('id', $property->id)->update(['accepts_inquiries' => false]);
        }
        if ($state === 'owner-role') {
            DB::table('users')->where('id', $property->provider_id)->update(['role' => 'business_user']);
        }
        $payload = $this->payload($property);
        if ($state === 'missing') {
            $payload['property_id'] = 99999999;
        }
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/inquiries', $payload)->assertNotFound()->assertExactJson(['message' => 'Property unavailable.', 'code' => 'PROPERTY_UNAVAILABLE']);
        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('inquiry_idempotency_results', 0);
    }

    public static function ineligibleProperties(): array
    {
        return [['missing'], ['unpublished'], ['disabled'], ['owner-role']];
    }

    #[DataProvider('forgedFields')]
    public function test_unknown_identity_or_workflow_fields_are_rejected(string $field): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/inquiries', $this->payload($property) + [$field => 999])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('payload');
        $this->assertDatabaseCount('inquiries', 0);
    }

    public static function forgedFields(): array
    {
        return [['provider_id'], ['business_user_id'], ['status'], ['grants'], ['id'], ['created_at']];
    }

    #[DataProvider('invalidKeys')]
    public function test_missing_or_non_v4_key_returns_422(?string $key): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web');
        if ($key !== null) {
            $this->withHeader('Idempotency-Key', $key);
        }
        $this->postJson('/api/v1/inquiries', $this->payload($property))->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public static function invalidKeys(): array
    {
        return [[null], ['not-a-key'], ['00000000-0000-1000-8000-000000000000']];
    }

    public function test_normalized_matching_retry_returns_identical_original_body_and_location(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', strtoupper((string) Str::uuid()));
        $payload = $this->payload($property);
        $first = $this->postJson('/api/v1/inquiries', $payload)->assertCreated();
        $payload['business_name'] = " \t\r\n";
        $payload['contact_phone'] = null;
        $payload['contact_name'] = "\tBusiness Contact\r\n";
        $second = $this->postJson('/api/v1/inquiries', array_reverse($payload, true))->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertDatabaseCount('inquiries', 1);
        $this->assertDatabaseCount('inquiry_provider_grants', 1);
        $this->assertDatabaseCount('inquiry_idempotency_results', 1);
    }

    public function test_changed_payload_conflicts_before_rechecking_property_availability(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        $payload = $this->payload($property);
        $this->postJson('/api/v1/inquiries', $payload)->assertCreated();
        $payload['property_id'] = 99999999;
        $this->postJson('/api/v1/inquiries', $payload)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('inquiries', 1);
    }

    public function test_replay_after_close_transfer_and_unpublishing_retains_original_committed_result(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $sender = User::factory()->businessUser()->create();
        $this->actingAs($sender, 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        $payload = $this->payload($property);
        $first = $this->postJson('/api/v1/inquiries', $payload)->assertCreated();
        DB::table('inquiries')->where('id', $first->json('data.id'))->update(['status' => 'closed']);
        DB::table('properties')->where('id', $property->id)->update(['provider_id' => User::factory()->propertyProvider()->create()->id, 'is_published' => false]);
        $second = $this->postJson('/api/v1/inquiries', $payload)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $second->getContent());
        DB::table('users')->where('id', $sender->id)->update(['role' => 'administrator']);
        $this->postJson('/api/v1/inquiries', $payload)->assertForbidden();
    }

    public function test_same_key_is_independent_per_authenticated_user(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $first = $this->actingAs(User::factory()->businessUser()->create(), 'web')->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated();
        $this->app['auth']->forgetGuards();
        $second = $this->actingAs(User::factory()->businessUser()->create(), 'web')->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('inquiries', 2);
    }

    public function test_grant_failure_rolls_back_every_record_and_same_key_can_be_reused(): void
    {
        config(['app.debug' => true]);
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        InquiryProviderGrant::creating(fn () => throw new RuntimeException('Sensitive database failure detail'));
        try {
            $this->postJson('/api/v1/inquiries', $this->payload($property))->assertStatus(500)
                ->assertExactJson(['message' => 'Unable to process the inquiry.', 'code' => 'SERVER_ERROR']);
        } finally {
            Event::forget('eloquent.creating: '.InquiryProviderGrant::class);
        }
        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('inquiry_provider_grants', 0);
        $this->assertDatabaseCount('inquiry_idempotency_results', 0);
        $this->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated();
    }

    public function test_retry_result_failure_also_rolls_back_inquiry_and_grant(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        DB::statement('ALTER TABLE inquiry_idempotency_results ADD CONSTRAINT test_reject_result CHECK (false)');
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/inquiries', $this->payload($property))->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');
        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('inquiry_provider_grants', 0);
    }

    public function test_database_enforces_unique_retry_scope(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated();
        $row = (array) DB::table('inquiry_idempotency_results')->first();
        unset($row['id']);
        $row['inquiry_id'] = Inquiry::factory()->create()->id;
        $this->expectException(QueryException::class);
        DB::table('inquiry_idempotency_results')->insert($row);
    }

    #[DataProvider('sharedCases')]
    public function test_shared_contract_fixtures_agree_with_backend(array $case): void
    {
        $payload = ['property_id' => 1, 'contact_name' => 'Business Contact', 'contact_email' => 'contact@example.test', 'message' => 'We would like to arrange a viewing for our business.'];
        $payload = array_replace($payload, $case['patch']);
        foreach ($case['omit'] ?? [] as $key) {
            unset($payload[$key]);
        }
        try {
            app(InquiryValidation::class)->validate($payload);
            $this->assertTrue($case['valid']);
        } catch (ValidationException) {
            $this->assertFalse($case['valid']);
        }
    }

    public static function sharedCases(): array
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 2).'/contracts/inquiry-validation.v1.cases.json'), true, flags: JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($cases as $case) {
            $result[$case['name']] = [$case];
        }

        return $result;
    }

    public function test_unicode_limits_strict_json_integer_and_js_whitespace_match_the_contract(): void
    {
        $validator = app(InquiryValidation::class);
        $valid = ['property_id' => 1, 'contact_name' => str_repeat('😀', 150), 'contact_email' => 'a@example.test', 'message' => str_repeat('😀', 2000)];
        $this->assertSame($valid['message'], $validator->validate($valid)['message']);
        foreach ([['contact_name', str_repeat('😀', 151)], ['message', str_repeat('😀', 2001)], ['property_id', '1'], ['property_id', 1.5], ['property_id', 9007199254740992], ['contact_email', "a\u{FEFF}@example.test"]] as [$field, $bad]) {
            try {
                $validator->validate(array_replace($valid, [$field => $bad]));
                $this->fail('Invalid contract value accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertSame("\u{000B}Name\u{000B}", $validator->validate(array_replace($valid, ['contact_name' => "\u{000B}Name\u{000B}"]))['contact_name']);
        $this->assertSame("a\u{0085}@example.test", $validator->validate(array_replace($valid, ['contact_email' => "a\u{0085}@example.test"]))['contact_email']);
    }

    public function test_http_normalization_is_ascii_only_and_invalid_messages_are_422(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        $payload = $this->payload($property);
        $payload['contact_name'] = "\u{000B}Name\u{000B}";
        $this->postJson('/api/v1/inquiries', $payload)->assertCreated()->assertJsonPath('data.contact_name', $payload['contact_name']);
        $payload['message'] = str_repeat('😀', 2001);
        $this->postJson('/api/v1/inquiries', $payload)->assertUnprocessable()->assertJsonValidationErrors('message');
    }

    public function test_pinned_artifact_hashes_and_version_are_consistent(): void
    {
        $manifest = json_decode(file_get_contents(base_path('contracts/inquiry-v1.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertSame($manifest['validation_sha256'], hash_file('sha256', base_path('contracts/inquiry-validation.v1.json')));
        $this->assertSame($manifest['cases_sha256'], hash_file('sha256', base_path('contracts/inquiry-validation.v1.cases.json')));
        $this->assertSame('laravel_session', config('session.cookie'));
    }

    public function test_creation_rate_limit_returns_contract_429_and_retry_header(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated();
        }
        $this->postJson('/api/v1/inquiries', $this->payload($property))->assertTooManyRequests()
            ->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertDatabaseCount('inquiries', 1);
    }

    public function test_credentialed_cors_allows_idempotency_header_and_exposes_retry_result_headers(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token,idempotency-key'])->options('/api/v1/inquiries')
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('idempotency-key', strtolower($response->headers->get('Access-Control-Allow-Headers')));
    }

    public function test_successful_key_has_no_expiry_and_original_result_cannot_be_rewritten(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        $first = $this->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated();
        $this->travel(400)->days();
        $replay = $this->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->expectException(QueryException::class);
        DB::table('inquiry_idempotency_results')->update(['response_body' => '{}']);
    }

    public function test_equivalent_json_integer_notation_has_the_same_idempotency_fingerprint(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        $payload = $this->payload($property);
        $first = $this->postJson('/api/v1/inquiries', $payload)->assertCreated();
        $payload['property_id'] = (float) $property->id;
        $replay = $this->postJson('/api/v1/inquiries', $payload, [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertDatabaseCount('inquiries', 1);
    }
}
