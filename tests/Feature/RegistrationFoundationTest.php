<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\InquiryException;
use App\Exceptions\OnboardingException;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\OnboardingAuditEvent;
use App\Models\Property;
use App\Models\ProviderApplication;
use App\Models\User;
use App\Services\InquiryService;
use App\Services\InquiryStatusService;
use App\Support\AccountEligibility;
use App\Support\RegistrationAccountFactory;
use App\Support\RegistrationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegistrationFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_gate_is_independent_and_does_not_expose_registration_or_send_mail(): void
    {
        $this->assertFalse(config('features.registration_enabled'));
        config(['features.inquiry_submission_enabled' => true]);
        $before = User::count();
        try {
            app(RegistrationAccountFactory::class)->make('QA Contact', 'qa@example.test', 'a sufficiently long passphrase', 'business_user');
            $this->fail('Registration must remain gated.');
        } catch (OnboardingException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('REGISTRATION_DISABLED', $exception->errorCode);
        }
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('onboarding_notification_outbox', 0);
        $this->postJson('/register', [])->assertStatus(503)->assertJsonPath('code', 'REGISTRATION_DISABLED');
        $this->postJson('/api/v1/provider-applications', [])->assertUnauthorized();
    }

    #[DataProvider('disabledGateValues')]
    public function test_malformed_registration_configuration_fails_closed(mixed $value): void
    {
        config(['features.registration_enabled' => $value]);
        $this->expectException(OnboardingException::class);
        RegistrationGate::ensureEnabled();
    }

    public static function disabledGateValues(): array
    {
        return [[null], ['true'], ['false'], [1], [false]];
    }

    #[DataProvider('accountTypes')]
    public function test_unsaved_account_builder_derives_role_origin_and_unverified_state(string $type, string $origin): void
    {
        config(['features.registration_enabled' => true]);
        $password = 'a long Unicode passphrase '.str_repeat('?', 30);
        $user = app(RegistrationAccountFactory::class)->make(' QA Contact ', ' QA.Identity@Example.test ', $password, $type);
        $this->assertFalse($user->exists);
        $this->assertSame(UserRole::BUSINESS_USER, $user->role);
        $this->assertSame($origin, $user->onboarding_origin);
        $this->assertSame('qa.identity@example.test', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertInstanceOf(MustVerifyEmail::class, $user);
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(AccountEligibility::allows($user, UserRole::BUSINESS_USER));
        $this->assertFalse(config('features.inquiry_submission_enabled'));
    }

    public static function accountTypes(): array
    {
        return [['business_user', 'self_business'], ['property_provider', 'self_provider']];
    }

    public function test_client_like_mass_assignment_cannot_set_verification_origin_or_role(): void
    {
        $user = User::create(['name' => 'QA Contact', 'email' => 'QA@Example.test', 'password' => 'a sufficiently long password',
            'role' => 'administrator', 'onboarding_origin' => 'provisioned', 'email_verified_at' => now()]);
        $user->refresh();
        $this->assertSame(UserRole::BUSINESS_USER, $user->role);
        $this->assertSame('self_business', $user->onboarding_origin);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse(Gate::forUser($user)->allows('access-business-area'));
    }

    public function test_account_builder_rejects_administrator_intent_and_invalid_passwords_without_persistence(): void
    {
        config(['features.registration_enabled' => true]);
        foreach ([['administrator', 'a sufficiently long password'], ['business_user', 'short'], ['business_user', str_repeat('a', 129)]] as [$type,$password]) {
            try {
                app(RegistrationAccountFactory::class)->make('QA', 'qa@example.test', $password, $type);
                $this->fail('Invalid account attributes must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_bcrypt_login_rehashes_only_after_correct_password_and_argon_login_works(): void
    {
        $legacy = password_hash('existing legacy password', PASSWORD_BCRYPT, ['cost' => 4]);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => $legacy]);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->assertSame($legacy, $user->fresh()->password);
        $this->postJson('/login', ['email' => strtoupper($user->email), 'password' => 'existing legacy password'])->assertOk();
        $newHash = $user->fresh()->password;
        $this->assertSame('argon2id', password_get_info($newHash)['algoName']);
        $this->assertTrue(Hash::check('existing legacy password', $newHash));
        $this->postJson('/logout')->assertOk();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'existing legacy password'])->assertOk();
        $this->assertSame($newHash, $user->fresh()->password);
        $this->assertFalse(Hash::check('anything', hash('sha256', 'anything')));
        $this->assertFalse(Hash::check('anything', password_hash('anything', PASSWORD_ARGON2I, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])));
    }

    public function test_passwords_beyond_bcrypt_limit_are_not_truncated_or_trimmed(): void
    {
        $password = ' '.str_repeat('x', 90).' first suffix ';
        $hash = Hash::make($password);
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertFalse(Hash::check(trim($password), $hash));
        $this->assertFalse(Hash::check(substr($password, 0, 72).'different suffix', $hash));
    }

    #[DataProvider('restrictedStates')]
    public function test_restricted_users_lose_area_record_scope_and_direct_service_access(string $state): void
    {
        config(['features.inquiry_submission_enabled' => true]);
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        if ($state === 'unverified_business') {
            $actor = $inquiry->sender;
            DB::table('users')->where('id', $actor->id)->update(['email_verified_at' => null]);
        } elseif ($state === 'unverified_provider') {
            $actor = $inquiry->historicalProvider;
            DB::table('users')->where('id', $actor->id)->update(['email_verified_at' => null]);
        } else {
            $application = $state === 'rejected' ? ProviderApplication::factory()->rejected()->create() : ProviderApplication::factory()->create();
            $actor = $application->applicant;
            InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $actor->id]);
        }
        foreach (['access-business-area', 'access-provider-area', 'access-administration', 'review-provider-applications'] as $ability) {
            $this->assertFalse(Gate::forUser($actor)->allows($ability));
        }
        $this->assertFalse(Gate::forUser($actor)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($actor)->allows('updateStatus', $inquiry));
        $this->assertSame([], Inquiry::query()->visibleTo($actor)->pluck('id')->all());
        $this->assertSame([], Inquiry::query()->forProvider($actor)->pluck('id')->all());
        $this->assertSame([], Property::query()->ownedBy($actor)->pluck('id')->all());
        $this->actingAs($actor, 'web')->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/inquiries')->assertForbidden();
        $this->getJson('/api/v1/provider/inquiries')->assertForbidden();
        $this->postJson('/api/v1/inquiries', [])->assertForbidden();
        $this->patchJson('/api/v1/provider/inquiries/'.$inquiry->id.'/status', ['status' => 'closed'])->assertForbidden();
        try {
            app(InquiryService::class)->create($actor, [], (string) Str::uuid());
            $this->fail('Direct creation must reject restricted users.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            app(InquiryStatusService::class)->close($actor, (string) $inquiry->id, 'closed');
            $this->fail('Direct close must reject restricted users.');
        } catch (InquiryException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('inquiries', ['id' => $inquiry->id, 'status' => 'pending']);
        $this->assertDatabaseCount('inquiry_status_changes', 0);
        $this->assertDatabaseCount('inquiry_idempotency_results', 0);
    }

    public static function restrictedStates(): array
    {
        return [['unverified_business'], ['unverified_provider'], ['pending'], ['rejected']];
    }

    public function test_approved_provider_requires_verified_email_and_current_grant_and_cannot_act_as_sender(): void
    {
        $application = ProviderApplication::factory()->approved()->create();
        $provider = $application->applicant->fresh();
        $inquiry = Inquiry::factory()->create();
        $this->assertTrue(AccountEligibility::allows($provider, UserRole::PROPERTY_PROVIDER));
        $this->assertFalse(Gate::forUser($provider)->allows('create', Inquiry::class));
        $this->assertFalse(Gate::forUser($provider)->allows('view', $inquiry));
        InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $provider->id]);
        $this->assertTrue(Gate::forUser($provider)->allows('view', $inquiry));
        DB::table('users')->where('id', $provider->id)->update(['email_verified_at' => null]);
        $this->assertFalse(Gate::forUser($provider)->allows('view', $inquiry));
        $this->assertFalse(Gate::forUser($provider)->allows('access-provider-area'));
    }

    public function test_verified_applicant_with_forged_provider_role_but_no_approval_stays_restricted(): void
    {
        $actor = User::factory()->providerApplicant()->create();
        DB::table('users')->where('id', $actor->id)->update(['role' => 'property_provider']);
        $this->assertFalse(AccountEligibility::allows($actor, UserRole::PROPERTY_PROVIDER));
        $this->assertFalse(Gate::forUser($actor)->allows('access-provider-area'));
    }

    public function test_only_verified_provisioned_administrators_receive_review_eligibility(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('review-provider-applications'));
        $this->assertFalse(Gate::forUser($admin)->allows('view', Inquiry::factory()->create()));
        DB::table('users')->where('id', $admin->id)->update(['email_verified_at' => null]);
        $this->assertFalse(Gate::forUser($admin)->allows('review-provider-applications'));
    }

    #[DataProvider('invalidApplications')]
    public function test_database_rejects_invalid_application_states_even_with_query_builder(array $changes): void
    {
        $application = ProviderApplication::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('provider_applications')->where('id', $application->id)->update($changes);
    }

    public static function invalidApplications(): array
    {
        return [[['status' => 'approved']], [['status' => 'rejected']], [['status' => 'responded']], [['public_reason' => 'private note']], [['application_message' => 'edited snapshot']]];
    }

    public function test_application_uniqueness_is_database_enforced(): void
    {
        $application = ProviderApplication::factory()->create();
        $this->expectException(QueryException::class);
        ProviderApplication::factory()->create(['applicant_user_id' => $application->applicant_user_id]);
    }

    public function test_final_decision_cannot_be_rewritten(): void
    {
        $application = ProviderApplication::factory()->approved()->create();
        $this->expectException(QueryException::class);
        DB::table('provider_applications')->where('id', $application->id)->update(['decided_at' => now()->addMinute()]);
    }

    public function test_audit_is_append_only_and_verification_event_unique(): void
    {
        $audit = OnboardingAuditEvent::factory()->create();
        foreach (['update', 'delete', 'duplicate'] as $operation) {
            try {
                DB::transaction(function () use ($audit, $operation): void {
                    if ($operation === 'update') {
                        DB::table('onboarding_audit_events')->where('id', $audit->id)->update(['occurred_at' => now()->addMinute()]);
                    } elseif ($operation === 'delete') {
                        DB::table('onboarding_audit_events')->where('id', $audit->id)->delete();
                    } else {
                        OnboardingAuditEvent::factory()->create(['subject_user_id' => $audit->subject_user_id, 'actor_user_id' => $audit->subject_user_id]);
                    }
                });
                $this->fail('Audit changes must be rejected.');
            } catch (QueryException) {
                $this->assertDatabaseCount('onboarding_audit_events', 1);
            }
        }
    }

    public function test_origin_cannot_be_changed_to_gain_provisioned_privileges(): void
    {
        $user = User::factory()->providerApplicant()->create();
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['onboarding_origin' => 'provisioned']);
    }

    public function test_canonical_email_uniqueness_and_no_role_upgrade_from_public_origin(): void
    {
        $user = User::factory()->selfRegisteredBusiness()->create(['email' => 'Canonical@Example.test']);
        $this->assertSame('canonical@example.test', $user->email);
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['role' => 'administrator']);
    }

    public function test_query_builder_cannot_insert_noncanonical_email(): void
    {
        $attributes = User::factory()->raw(['email' => 'Upper@Example.test']);
        $this->expectException(QueryException::class);
        DB::table('users')->insert($attributes);
    }

    public function test_verified_self_business_can_access_only_business_workflows(): void
    {
        $user = User::factory()->selfRegisteredBusiness()->create();
        $this->assertTrue(Gate::forUser($user)->allows('access-business-area'));
        $this->assertFalse(Gate::forUser($user)->allows('access-provider-area'));
        $this->assertTrue(Gate::forUser($user)->allows('create', Inquiry::class));
        $this->assertFalse(Gate::forUser($user)->allows('review-provider-applications'));
    }

    public function test_unverified_existing_user_can_still_sign_in_refresh_profile_and_sign_out(): void
    {
        $user = User::factory()->unverified()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/v1/inquiries')->assertForbidden();
        $this->postJson('/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_equivalent_email_requests_cannot_create_two_identities(): void
    {
        User::factory()->create(['email' => 'Identity@Example.test']);
        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'identity@example.test']);
    }

    public function test_decision_requires_reviewer_other_than_applicant_at_database_boundary(): void
    {
        $application = ProviderApplication::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('provider_applications')->where('id', $application->id)->update([
            'status' => 'approved', 'decided_at' => now(), 'decided_by' => $application->applicant_user_id]);
    }

    public function test_onboarding_audit_cannot_reference_another_applicant_or_missing_old_state(): void
    {
        $application = ProviderApplication::factory()->approved()->create();
        foreach (['other_subject', 'missing_previous_state'] as $case) {
            try {
                DB::transaction(function () use ($application, $case): void {
                    DB::table('onboarding_audit_events')->insert(['subject_user_id' => $case === 'other_subject' ? User::factory()->create()->id : $application->applicant_user_id,
                        'application_id' => $application->id, 'actor_user_id' => $application->decided_by, 'event' => 'provider_approved',
                        'old_state' => $case === 'missing_previous_state' ? null : 'pending', 'new_state' => 'approved', 'occurred_at' => now()]);
                });
                $this->fail('Cross-account or malformed audit must fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('onboarding_audit_events', 0);
            }
        }
    }

    #[DataProvider('invalidOutboxValues')]
    public function test_notification_schema_accepts_no_arbitrary_payload_or_invalid_delivery_state(array $changes): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('onboarding_notification_outbox')->insert([...['subject_user_id' => $user->id, 'notification_kind' => 'email_verification',
            'deduplication_key' => (string) Str::uuid(), 'attempt_count' => 0, 'next_attempt_at' => now()], ...$changes]);
    }

    public static function invalidOutboxValues(): array
    {
        return [[['attempt_count' => -1]], [['notification_kind' => 'arbitrary_mail']], [['notification_kind' => 'provider_decision']],
            [['last_error_code' => 'raw exception with contact data']], [['password' => 'must not be a column']]];
    }

    public function test_registration_gate_uses_production_cached_configuration_without_enabling_inquiry_creation(): void
    {
        $relative = 'bootstrap/cache/registration-gate-'.bin2hex(random_bytes(12)).'.php';
        $path = base_path($relative);
        $environment = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_CONFIG_CACHE' => $relative,
            'REGISTRATION_ENABLED' => 'false', 'INQUIRY_SUBMISSION_ENABLED' => 'false', 'PHP_INI_SCAN_DIR' => base_path('.runtime/php')];
        $read = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode([config("features.registration_enabled"),config("features.inquiry_submission_enabled")]);';
        try {
            (new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment))->mustRun();
            $environment['REGISTRATION_ENABLED'] = 'true';
            $cached = new Process([PHP_BINARY, '-r', $read], base_path(), $environment);
            $cached->mustRun();
            $this->assertSame('[false,false]', trim($cached->getOutput()));
            (new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment))->mustRun();
            $enabled = new Process([PHP_BINARY, '-r', $read], base_path(), $environment);
            $enabled->mustRun();
            $this->assertSame('[true,false]', trim($enabled->getOutput()));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_unverified_provider_listing_is_unavailable_for_public_discovery_and_creation(): void
    {
        config(['features.inquiry_submission_enabled' => true]);
        $property = Property::factory()->acceptingInquiries()->create();
        DB::table('users')->where('id', $property->provider_id)->update(['email_verified_at' => null]);
        $this->assertFalse(Property::query()->publiclyVisible()->whereKey($property->id)->exists());
        $this->getJson('/api/v1/properties/'.$property->id)->assertNotFound();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/inquiries', ['property_id' => $property->id, 'contact_name' => 'QA Contact',
                'contact_email' => 'contact@example.test', 'message' => 'Please arrange a business viewing.'])
            ->assertNotFound()->assertJsonPath('code', 'PROPERTY_UNAVAILABLE');
        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('inquiry_provider_grants', 0);
        $this->assertDatabaseCount('inquiry_idempotency_results', 0);
    }
}
