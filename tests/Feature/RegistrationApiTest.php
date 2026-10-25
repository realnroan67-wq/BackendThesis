<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\OnboardingException;
use App\Models\OnboardingAuditEvent;
use App\Models\OnboardingNotification;
use App\Models\User;
use App\Notifications\OnboardingVerifyEmail;
use App\Services\EmailVerificationService;
use App\Services\OnboardingDeliveryService;
use App\Services\RegistrationService;
use App\Support\AccountEligibility;
use App\Support\RegistrationValidation;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.registration_enabled' => true, 'app.url' => 'http://localhost']);
        Notification::fake();
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => ' QA Contact ', 'email' => ' QA.Onboarding@Example.test ',
            'password' => 'Unique river meadow passphrase 74!',
            'password_confirmation' => 'Unique river meadow passphrase 74!', 'account_type' => 'business_user',
        ], $changes);
    }

    private function link(User $user, int $minutes = 60, array $changes = []): string
    {
        $url = clone app('url');
        $url->forceRootUrl(config('app.url'));
        $url->forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));

        return $url->temporarySignedRoute('verification.verify', now()->addMinutes($minutes), array_replace([
            'id' => $user->id, 'hash' => sha1($user->email),
        ], $changes));
    }

    public function test_new_account_and_notification_intent_are_atomic_without_login_or_mail(): void
    {
        $this->postJson('/register', $this->payload())->assertStatus(202)->assertExactJson([
            'message' => 'If this request can be processed, check your email or sign in to continue.',
            'code' => 'REGISTRATION_REQUEST_ACCEPTED',
        ])->assertHeader('Cache-Control', 'no-store, private');
        $user = User::where('email', 'qa.onboarding@example.test')->sole();
        $this->assertSame('QA Contact', $user->name);
        $this->assertSame(UserRole::BUSINESS_USER, $user->role);
        $this->assertSame('self_business', $user->onboarding_origin);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($this->payload()['password'], $user->password));
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);
        $this->assertDatabaseHas('onboarding_notification_outbox', ['subject_user_id' => $user->id, 'notification_kind' => 'email_verification', 'attempt_count' => 0, 'sent_at' => null]);
        $this->assertDatabaseCount('provider_applications', 0);
        $this->assertGuest('web');
        Notification::assertNothingSent();
        $this->assertFalse(config('features.inquiry_submission_enabled'));
    }

    public function test_provider_intention_never_grants_provider_or_business_access(): void
    {
        $this->postJson('/register', $this->payload(['account_type' => 'property_provider']))->assertStatus(202);
        $user = User::where('email', 'qa.onboarding@example.test')->sole();
        $this->assertSame('self_provider', $user->onboarding_origin);
        $this->assertSame(UserRole::BUSINESS_USER, $user->role);
        $this->actingAs($user, 'web')->getJson($this->link($user))->assertOk();
        $this->assertFalse(AccountEligibility::allows($user, UserRole::BUSINESS_USER));
        $this->assertFalse(AccountEligibility::allows($user, UserRole::PROPERTY_PROVIDER));
        $this->getJson('/api/v1/inquiries')->assertForbidden();
        $this->getJson('/api/v1/provider/inquiries')->assertForbidden();
    }

    public function test_duplicate_request_cannot_take_over_or_notify_an_existing_identity(): void
    {
        $user = User::factory()->create(['email' => 'qa.onboarding@example.test']);
        $before = $user->refresh()->getRawOriginal();
        $duplicate = $this->postJson('/register', $this->payload(['account_type' => 'property_provider']))->assertStatus(202);
        $new = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->postJson('/register', $this->payload(['email' => 'another@example.test']))->assertStatus(202);
        $this->assertSame($new->json(), $duplicate->json());
        $this->assertSame($before, $user->refresh()->getRawOriginal());
        $this->assertDatabaseMissing('onboarding_notification_outbox', ['subject_user_id' => $user->id]);
        Notification::assertNothingSent();
        $this->assertGuest('web');
    }

    public function test_duplicate_unverified_account_does_not_create_repeated_intents(): void
    {
        $this->postJson('/register', $this->payload())->assertStatus(202);
        $this->postJson('/register', $this->payload(['password' => 'A different unique password 93!', 'password_confirmation' => 'A different unique password 93!']))->assertStatus(202);
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
        $this->assertTrue(Hash::check($this->payload()['password'], User::where('email', 'qa.onboarding@example.test')->sole()->password));
    }

    public function test_outbox_failure_rolls_back_account_creation_with_safe_error(): void
    {
        OnboardingNotification::creating(function (): void {
            throw new \RuntimeException('Sensitive transport@example.test secret');
        });
        $before = User::count();
        $this->postJson('/register', $this->payload())->assertStatus(500)
            ->assertExactJson(['message' => 'Registration is temporarily unavailable.', 'code' => 'REGISTRATION_UNAVAILABLE'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('onboarding_notification_outbox', 0);
    }

    public function test_authenticated_registration_precedes_gate_and_validation(): void
    {
        config(['features.registration_enabled' => false]);
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/register', ['role' => 'administrator'])
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_AUTHENTICATED');
    }

    public function test_disabled_gate_rejects_requests_without_writes_but_does_not_block_onboarding(): void
    {
        config(['features.registration_enabled' => false]);
        $before = User::count();
        $this->postJson('/register', $this->payload())->assertStatus(503)->assertJsonPath('code', 'REGISTRATION_DISABLED');
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('onboarding_notification_outbox', 0);
        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/onboarding')->assertOk();
        $this->postJson('/email/verification-notification')->assertStatus(202);
        $this->getJson($this->link($user))->assertOk();
        Notification::assertNothingSent();
    }

    #[DataProvider('fixtureCases')]
    public function test_versioned_validation_fixtures_match_http_boundary(array $case): void
    {
        $response = $this->postJson('/register', $case['input']);
        if ($case['valid']) {
            $response->assertStatus(202);
            if (isset($case['normalized'])) {
                $this->assertDatabaseHas('users', $case['normalized']);
            }
        } else {
            $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
            $this->assertDatabaseMissing('users', ['email' => 'qa.contact@example.test']);
        }
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function fixtureCases(): array
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../../contracts/registration-validation-v1.fixtures.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column(array_map(fn (array $case): array => [$case['name'], [$case]], $cases), 1, 0);
    }

    #[DataProvider('invalidBodies')]
    public function test_malformed_json_and_body_size_are_rejected(string $content): void
    {
        $this->call('POST', '/register', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $content)
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('onboarding_notification_outbox', 0);
    }

    public static function invalidBodies(): array
    {
        return [['{bad'], ['[]'], ['null'], ['"text"'], [str_repeat(' ', 16385).'{}']];
    }

    public function test_validation_preserves_password_bytes_and_exact_unicode_boundaries(): void
    {
        $password = '  '.str_repeat('?', 15).'  ';
        $this->postJson('/register', $this->payload(['password' => $password, 'password_confirmation' => $password]))->assertStatus(202);
        $hash = User::where('email', 'qa.onboarding@example.test')->sole()->password;
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertFalse(Hash::check(trim($password), $hash));
        $validator = app(RegistrationValidation::class);
        $this->expectException(ValidationException::class);
        $validator->validate($this->payload(['name' => str_repeat('?', 151)]));
    }

    public function test_verified_business_access_is_fresh_and_requires_no_role_upgrade(): void
    {
        $user = User::factory()->selfRegisteredBusiness()->unverified()->create();
        $this->assertFalse(AccountEligibility::allows($user, UserRole::BUSINESS_USER));
        $this->actingAs($user, 'web')->getJson($this->link($user))->assertOk();
        $this->assertTrue(AccountEligibility::allows($user, UserRole::BUSINESS_USER));
        $this->getJson('/api/v1/onboarding')->assertOk()->assertJsonPath('data.can_access_business_area', true);
        $this->getJson('/api/v1/inquiries')->assertOk();
        $this->assertSame(UserRole::BUSINESS_USER, $user->refresh()->role);
    }

    public function test_valid_verification_replay_preserves_timestamp_and_single_audit(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $link = $this->link($user);
        $this->actingAs($user, 'web')->getJson($link)->assertExactJson(['message' => 'Email verified.', 'code' => 'EMAIL_VERIFIED'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $timestamp = $user->refresh()->getRawOriginal('email_verified_at');
        $this->travel(2)->minutes();
        $this->getJson($link)->assertOk();
        $this->assertSame($timestamp, $user->refresh()->getRawOriginal('email_verified_at'));
        $this->assertDatabaseCount('onboarding_audit_events', 1);
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_verification_never_changes_state(string $kind): void
    {
        $user = User::factory()->unverified()->create();
        $link = match ($kind) {
            'expired' => $this->link($user, -1),
            'hash' => $this->link($user, changes: ['hash' => sha1('different@example.test')]),
            'account' => $this->link(User::factory()->unverified()->create()),
            'signature' => $this->link($user).'tampered',
            'origin' => str_replace('http://localhost', 'http://untrusted.test', $this->link($user)),
            'changed-email' => $this->link($user),
        };
        if ($kind === 'changed-email') {
            $user->email = 'updated@example.test';
            $user->save();
        }
        $this->actingAs($user, 'web')->getJson($link)->assertForbidden()->assertJsonPath('code', 'VERIFICATION_LINK_INVALID');
        $this->assertNull($user->refresh()->email_verified_at);
        $this->assertDatabaseCount('onboarding_audit_events', 0);
    }

    public static function invalidLinks(): array
    {
        return array_map(fn (string $kind): array => [$kind], ['expired', 'hash', 'account', 'signature', 'origin', 'changed-email']);
    }

    public function test_verification_audit_failure_rolls_back_timestamp(): void
    {
        $user = User::factory()->unverified()->create();
        OnboardingAuditEvent::creating(fn () => throw new \RuntimeException('do not expose'));
        $this->actingAs($user, 'web')->getJson($this->link($user))->assertStatus(500)->assertJsonPath('code', 'REGISTRATION_UNAVAILABLE');
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_guest_and_invalid_csrf_errors_are_json_and_no_store(): void
    {
        $user = User::factory()->unverified()->create();
        $this->get($this->link($user))->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/email/verification-notification')->assertUnauthorized();
        $this->getJson('/api/v1/onboarding')->assertUnauthorized();
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/register', $this->payload())->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH')->assertHeader('Cache-Control', 'no-store, private');
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_resend_is_generic_coalesced_and_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'web')->postJson('/email/verification-notification')->assertStatus(202);
        $this->postJson('/email/verification-notification')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/email/verification-notification')->assertStatus(202);
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
        $verified = User::factory()->create();
        $this->travel(61)->seconds();
        $this->actingAs($verified, 'web')->postJson('/email/verification-notification')->assertStatus(202)->assertJsonPath('code', 'VERIFICATION_REQUEST_ACCEPTED');
        $this->assertDatabaseMissing('onboarding_notification_outbox', ['subject_user_id' => $verified->id]);
    }

    public function test_email_rate_limit_uses_canonical_identity_and_opaque_key(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])->postJson('/register', $this->payload())->assertStatus(202);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson('/register', $this->payload(['email' => 'qa.onboarding@example.test']))
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $limits = RateLimiter::limiter('registration')(Request::create('/register', 'POST', $this->payload()));
        $this->assertStringNotContainsString('example.test', $limits[1]->key);
    }

    public function test_registration_ip_limit_is_independent_of_email(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/register', $this->payload(['email' => 'qa'.$i.'@example.test']))->assertStatus(202);
        }
        $this->postJson('/register', $this->payload(['email' => 'qa6@example.test']))->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_verification_handler_is_bounded(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user);
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user, 'web')->getJson($link)->assertOk();
        }
        $this->getJson($link)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_own_onboarding_uses_only_allowlisted_current_state(): void
    {
        $user = User::factory()->providerApplicant()->unverified()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/onboarding')->assertExactJson(['data' => [
            'id' => $user->id, 'onboarding_origin' => 'self_provider', 'email_verified' => false,
            'can_access_business_area' => false, 'can_access_provider_area' => false, 'application' => null,
        ]])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonMissingPath('data.onboarding_origin');
        $this->postJson('/api/v1/provider-applications')->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
    }

    public function test_mail_outage_is_retryable_and_never_verifies_or_leaks_payload(): void
    {
        $this->postJson('/register', $this->payload())->assertStatus(202);
        $intent = OnboardingNotification::sole();
        config(['mail.default' => 'smtp']);
        Notification::shouldReceive('sendNow')->andThrow(new \RuntimeException('recipient@example.test signature=SECRET'));
        $this->assertFalse(app(OnboardingDeliveryService::class)->deliver($intent->id));
        $intent->refresh();
        $this->assertSame(1, $intent->attempt_count);
        $this->assertNull($intent->sent_at);
        $this->assertSame('DELIVERY_UNAVAILABLE', $intent->last_error_code);
        $this->assertNull(User::findOrFail($intent->subject_user_id)->email_verified_at);
        $this->assertStringNotContainsString('SECRET', $intent->toJson());
        $this->assertFalse(app(OnboardingDeliveryService::class)->deliver($intent->id));
    }

    public function test_delivery_retry_uses_same_intent_and_stops_after_transport_acceptance(): void
    {
        $this->postJson('/register', $this->payload())->assertStatus(202);
        $intent = OnboardingNotification::sole();
        config(['mail.default' => 'smtp']);
        $service = app(OnboardingDeliveryService::class);
        $this->assertTrue($service->deliver($intent->id));
        $this->assertFalse($service->deliver($intent->id));
        Notification::assertSentTo(User::findOrFail($intent->subject_user_id), OnboardingVerifyEmail::class, 1);
        $this->assertSame(1, $intent->refresh()->attempt_count);
        $this->assertNotNull($intent->sent_at);
    }

    public function test_retry_exhaustion_is_bounded_and_explicit_resend_creates_fresh_intent(): void
    {
        $user = User::factory()->unverified()->create();
        app(EmailVerificationService::class)->resend($user);
        $intent = OnboardingNotification::sole();
        config(['mail.default' => 'log']);
        $service = app(OnboardingDeliveryService::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->deliver($intent->id));
            $this->travel(61)->minutes();
        }
        $this->assertSame(5, $intent->refresh()->attempt_count);
        $this->assertSame('RETRY_EXHAUSTED', $intent->last_error_code);
        $this->assertNull($intent->sent_at);
        $this->assertFalse($service->deliver($intent->id));
        app(EmailVerificationService::class)->resend($user);
        $this->assertDatabaseCount('onboarding_notification_outbox', 2);
        Notification::assertNothingSent();
    }

    public function test_worker_skips_verified_users_and_outputs_counts_only(): void
    {
        $user = User::factory()->unverified()->create();
        app(EmailVerificationService::class)->resend($user);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->artisan('onboarding:deliver', ['--limit' => 20])->expectsOutputToContain('Processed 0 intents; transport accepted 0.')->assertSuccessful();
        Notification::assertNothingSent();
        $intent = OnboardingNotification::sole();
        $this->assertFalse(app(OnboardingDeliveryService::class)->deliver($intent->id));
        $this->assertNull($intent->refresh()->sent_at);
        $this->assertSame(0, $intent->attempt_count);
        $this->assertNull($intent->last_error_code);
        $this->artisan('onboarding:deliver', ['--limit' => 101])->assertExitCode(2);
    }

    public function test_generated_link_uses_configured_origin_and_sixty_minute_expiry(): void
    {
        $user = User::factory()->unverified()->create();
        config(['app.url' => 'https://accounts.example.test']);
        $message = (new OnboardingVerifyEmail)->toMail($user);
        $this->assertStringStartsWith('https://accounts.example.test/api/v1/auth/email/verify/'.$user->id.'/', $message->actionUrl);
        parse_str(parse_url($message->actionUrl, PHP_URL_QUERY), $query);
        $this->assertSame(now()->addMinutes(60)->timestamp, (int) $query['expires']);
        $this->assertArrayHasKey('signature', $query);
        foreach (['https://user@example.test', 'https://user:password@example.test', 'https://accounts.example.test/path'] as $origin) {
            config(['app.url' => $origin]);
            try {
                (new OnboardingVerifyEmail)->toMail($user);
                $this->fail('Verification origin must not contain credentials or a path.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Invalid verification origin.', $exception->getMessage());
            }
        }
    }

    public function test_service_gate_cannot_be_bypassed_by_direct_invocation(): void
    {
        config(['features.registration_enabled' => false]);
        $this->expectException(OnboardingException::class);
        app(RegistrationService::class)->register($this->payload());
    }

    public function test_wrong_origin_link_is_rejected_even_with_an_authentic_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $url = clone app('url');
        $url->forceRootUrl('http://untrusted.test');
        $url->forceScheme('http');
        $link = $url->temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user, 'web')->getJson($link)->assertForbidden()->assertJsonPath('code', 'VERIFICATION_LINK_INVALID');
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_logged_in_web_session_can_open_mail_link_without_spa_origin_or_referrer(): void
    {
        $user = User::factory()->selfRegisteredBusiness()->unverified()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->get($this->link($user), ['Referer' => 'https://mail.example.test/'])->assertOk()->assertJsonPath('code', 'EMAIL_VERIFIED');
        $this->postJson('/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->link($user))->assertUnauthorized();
    }

    public function test_resend_account_hourly_limit_survives_minute_cooldowns(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'web');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/email/verification-notification')->assertStatus(202);
            $this->travel(61)->seconds();
        }
        $this->postJson('/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
    }

    public function test_resend_ip_limit_applies_across_accounts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs(User::factory()->unverified()->create(), 'web')->postJson('/email/verification-notification')->assertStatus(202);
        }
        $this->actingAs(User::factory()->unverified()->create(), 'web')->postJson('/email/verification-notification')
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('onboarding_notification_outbox', 20);
    }

    public function test_later_resend_does_not_revoke_an_earlier_valid_link(): void
    {
        $user = User::factory()->unverified()->create();
        $earlier = $this->link($user);
        config(['mail.default' => 'smtp']);
        app(EmailVerificationService::class)->resend($user);
        app(OnboardingDeliveryService::class)->deliver(OnboardingNotification::sole()->id);
        $this->travel(2)->minutes();
        app(EmailVerificationService::class)->resend($user);
        $this->assertDatabaseCount('onboarding_notification_outbox', 2);
        $this->actingAs($user, 'web')->getJson($earlier)->assertOk();
        $this->assertDatabaseCount('onboarding_audit_events', 1);
    }

    public function test_pinned_contract_manifest_and_openapi_describe_implemented_onboarding_routes(): void
    {
        $manifest = json_decode(file_get_contents(base_path('contracts/registration-v1.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $file => $digest) {
            $this->assertSame($digest, hash_file('sha256', base_path('contracts/'.$file)));
        }
        $contract = json_decode(file_get_contents(base_path('contracts/registration-api-v1.openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(9, $contract['paths']);
        $this->assertSame('1.0.0', $contract['info']['version']);
        $schema = $contract['paths']['/register']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['business_user', 'property_provider'], $schema['properties']['account_type']['enum']);
        $this->assertSame(15, $schema['properties']['password']['minLength']);
    }

    public function test_verification_event_occurs_once_after_commit_and_never_on_rollback(): void
    {
        // Independent nested transaction callbacks can be asserted without committing the test's fixture transaction.
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);
        $service = app(EmailVerificationService::class);
        $service->verify($user, (string) $user->id, sha1($user->email));
        $service->verify($user, (string) $user->id, sha1($user->email));
        $this->assertDatabaseCount('onboarding_audit_events', 1);
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_registration_and_resend_preflights_allow_only_the_configured_frontend(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);
        foreach (['/register', '/email/verification-notification'] as $path) {
            $this->withHeaders(['Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type,x-xsrf-token'])
                ->options($path)->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
            $this->withHeaders(['Origin' => 'https://untrusted.example.test', 'Access-Control-Request-Method' => 'POST'])
                ->options($path)->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        }
        $this->assertFalse(config('features.inquiry_submission_enabled'));
    }
}
