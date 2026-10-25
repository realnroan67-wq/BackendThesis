<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\OnboardingException;
use App\Models\Inquiry;
use App\Models\OnboardingAuditEvent;
use App\Models\OnboardingNotification;
use App\Models\Property;
use App\Models\ProviderApplication;
use App\Models\User;
use App\Notifications\ProviderApplicationDecision;
use App\Services\OnboardingDeliveryService;
use App\Services\ProviderApplicationService;
use App\Support\AccountEligibility;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderApplicationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['business_name' => ' Fictitious Provider ', 'business_location' => ' QA Location ',
            'application_message' => ' We would like to list our commercial properties. '], $changes);
    }

    private function administrator(): User
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin, 'web')->postJson('/api/v1/admin/password-confirmation', ['password' => 'password'])->assertNoContent();

        return $admin;
    }

    private function decide(ProviderApplication $application, array $body): TestResponse
    {
        return $this->patchJson('/api/v1/admin/provider-applications/'.$application->id.'/decision', $body);
    }

    public function test_creation_matching_retry_conflict_and_attempt_limit(): void
    {
        config(['features.registration_enabled' => true]);
        $user = User::factory()->providerApplicant()->create();
        $this->actingAs($user, 'web')->postJson('/api/v1/provider-applications', $this->payload())->assertCreated()
            ->assertJsonPath('data.business_name', 'Fictitious Provider')->assertJsonMissingPath('data.applicant_user_id');
        $this->postJson('/api/v1/provider-applications', $this->payload(['business_name' => 'Fictitious Provider']))->assertOk();
        $this->postJson('/api/v1/provider-applications', $this->payload(['business_name' => 'Different']))->assertStatus(409)->assertJsonPath('code', 'APPLICATION_ALREADY_EXISTS');
        $this->postJson('/api/v1/provider-applications', $this->payload())->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('provider_applications', 1);
        $this->assertDatabaseCount('onboarding_audit_events', 0);
        $this->assertFalse(AccountEligibility::allows($user, UserRole::PROPERTY_PROVIDER));
        $this->assertFalse(AccountEligibility::allows($user, UserRole::BUSINESS_USER));
    }

    public function test_default_gate_blocks_new_and_matching_application_without_writes(): void
    {
        $application = ProviderApplication::factory()->create();
        $this->actingAs($application->applicant, 'web')->postJson('/api/v1/provider-applications', ['role' => 'administrator'])
            ->assertStatus(503)->assertJsonPath('code', 'REGISTRATION_DISABLED')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('provider_applications', 1);
        $this->assertDatabaseCount('onboarding_notification_outbox', 0);
        $this->assertFalse(config('features.inquiry_submission_enabled'));
    }

    #[DataProvider('ineligibleApplicants')]
    public function test_applicant_eligibility_precedes_gate_and_payload(string $state, string $code): void
    {
        $user = match ($state) {
            'business' => User::factory()->businessUser()->create(),
            'provider' => User::factory()->propertyProvider()->create(),
            'administrator' => User::factory()->administrator()->create(),
            'unverified' => User::factory()->providerApplicant()->unverified()->create(),
        };
        $this->actingAs($user, 'web')->postJson('/api/v1/provider-applications', [])->assertForbidden()->assertJsonPath('code', $code);
        $this->assertDatabaseCount('provider_applications', 0);
    }

    public static function ineligibleApplicants(): array
    {
        return [['business', 'FORBIDDEN'], ['provider', 'FORBIDDEN'], ['administrator', 'FORBIDDEN'], ['unverified', 'EMAIL_VERIFICATION_REQUIRED']];
    }

    #[DataProvider('invalidApplications')]
    public function test_strict_application_validation(array $changes): void
    {
        config(['features.registration_enabled' => true]);
        $this->actingAs(User::factory()->providerApplicant()->create(), 'web')->postJson('/api/v1/provider-applications', $this->payload($changes))
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('provider_applications', 0);
    }

    public static function invalidApplications(): array
    {
        return [[['role' => 'property_provider']], [['applicant_user_id' => 1]], [['status' => 'approved']],
            [['business_name' => str_repeat('x', 151)]], [['business_location' => str_repeat('x', 256)]],
            [['application_message' => 'short']], [['application_message' => str_repeat('?', 2001)]], [['business_name' => ['nested']]]];
    }

    public function test_approval_is_atomic_audited_and_grants_no_existing_ownership_or_inquiry_access(): void
    {
        $application = ProviderApplication::factory()->create();
        $property = Property::factory()->create();
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $admin = $this->administrator();
        $this->decide($application, ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.decided_by');
        $user = $application->applicant->refresh();
        $this->assertSame(UserRole::PROPERTY_PROVIDER, $user->role);
        $this->assertTrue(AccountEligibility::allows($user, UserRole::PROPERTY_PROVIDER));
        $this->assertFalse(Gate::forUser($user)->allows('update', $property));
        $this->assertFalse(Gate::forUser($user)->allows('view', $inquiry));
        $this->assertDatabaseHas('onboarding_audit_events', ['application_id' => $application->id, 'actor_user_id' => $admin->id, 'event' => 'provider_approved']);
        $this->assertDatabaseHas('onboarding_notification_outbox', ['application_id' => $application->id, 'notification_kind' => 'provider_decision', 'sent_at' => null]);
        $this->assertDatabaseCount('inquiry_provider_grants', 1);
        Notification::assertNothingSent();
        $this->actingAs($user, 'web')->getJson('/api/v1/onboarding')->assertJsonPath('data.can_access_provider_area', true);
    }

    public function test_final_rejection_retry_and_conflict_preserve_history_and_restrictions(): void
    {
        $application = ProviderApplication::factory()->create();
        $this->administrator();
        $body = ['decision' => 'rejected', 'public_reason' => 'Please supply a suitable business application.'];
        $first = $this->decide($application, $body)->assertOk()->json();
        $before = $application->refresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $this->assertSame($first, $this->decide($application, $body)->assertOk()->json());
        $this->assertSame($before, $application->refresh()->getRawOriginal());
        $this->decide($application, ['decision' => 'approved'])->assertStatus(409)->assertJsonPath('code', 'APPLICATION_DECISION_CONFLICT');
        $this->decide($application, ['decision' => 'rejected', 'public_reason' => 'Changed reason'])->assertStatus(409);
        $this->assertDatabaseCount('onboarding_audit_events', 1);
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
        $this->assertSame(UserRole::BUSINESS_USER, $application->applicant->refresh()->role);
        $this->assertFalse(AccountEligibility::allows($application->applicant, UserRole::BUSINESS_USER));
        $this->assertFalse(AccountEligibility::allows($application->applicant, UserRole::PROPERTY_PROVIDER));
    }

    public function test_repeated_approval_preserves_original_reviewer_time_and_single_audit(): void
    {
        $application = ProviderApplication::factory()->create();
        $original = $this->administrator();
        $this->decide($application, ['decision' => 'approved'])->assertOk();
        $before = $application->refresh()->getRawOriginal();
        $this->administrator();
        $this->decide($application, ['decision' => 'approved'])->assertOk();
        $this->assertSame($before, $application->refresh()->getRawOriginal());
        $this->assertSame($original->id, $application->decided_by);
        $this->assertDatabaseCount('onboarding_audit_events', 1);
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
    }

    #[DataProvider('invalidDecisions')]
    public function test_decision_allowlist_and_validation(array $body): void
    {
        $application = ProviderApplication::factory()->create();
        $this->administrator();
        $this->decide($application, $body)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertSame('pending', $application->refresh()->status);
    }

    public static function invalidDecisions(): array
    {
        return [[['decision' => 'pending']], [['decision' => 'approved', 'public_reason' => 'extra']], [['decision' => 'rejected']],
            [['decision' => 'rejected', 'public_reason' => '<b>No</b>']], [['decision' => 'rejected', 'public_reason' => str_repeat('x', 1001)]],
            [['decision' => 'approved', 'decided_by' => 1]], [['decision' => ' approved ']], [['decision' => ['approved']]]];
    }

    public function test_confirmation_requires_current_password_and_regenerates_session_without_echo(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin, 'web')->getJson('/api/v1/admin/provider-applications')->assertOk();
        $old = session()->getId();
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'wrong'])->assertUnprocessable()->assertJsonPath('code', 'PASSWORD_CONFIRMATION_FAILED');
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'password', 'actor_id' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'password'])->assertNoContent();
        $this->assertNotSame($old, session()->getId());
        $this->assertStringNotContainsString($admin->password, json_encode(session()->get('onboarding.administrator_confirmation')));
    }

    public function test_missing_expired_future_other_user_and_password_changed_confirmation_fail(): void
    {
        $application = ProviderApplication::factory()->create();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin, 'web');
        $this->decide($application, ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'REAUTHENTICATION_REQUIRED');
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'password'])->assertNoContent();
        $this->travel(601)->seconds();
        $this->decide($application, ['decision' => 'approved'])->assertForbidden();
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'password'])->assertNoContent();
        $proof = session()->get('onboarding.administrator_confirmation');
        session()->put('onboarding.administrator_confirmation', array_replace($proof, ['confirmed_at' => now()->timestamp + 1]));
        $this->decide($application, ['decision' => 'approved'])->assertForbidden();
        session()->put('onboarding.administrator_confirmation', $proof);
        $this->actingAs(User::factory()->administrator()->create(), 'web');
        $this->decide($application, ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($admin, 'web');
        $admin->forceFill(['password' => Hash::make('New QA credential 74!')])->save();
        $this->decide($application, ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'REAUTHENTICATION_REQUIRED');
    }

    public function test_logout_clears_confirmation(): void
    {
        $application = ProviderApplication::factory()->create();
        $admin = $this->administrator();
        $this->postJson('/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->decide($application, ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'REAUTHENTICATION_REQUIRED');
    }

    public function test_current_role_and_explicit_ability_are_both_required(): void
    {
        $application = ProviderApplication::factory()->create();
        $admin = $this->administrator();
        Gate::define('review-provider-applications', fn () => false);
        $this->decide($application, ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        Gate::define('review-provider-applications', fn () => true);
        $admin->forceFill(['role' => UserRole::BUSINESS_USER])->save();
        $this->decide($application, ['decision' => 'approved'])->assertForbidden();
        $this->getJson('/api/v1/admin/provider-applications')->assertForbidden();
    }

    #[DataProvider('failureStages')]
    public function test_decision_failures_roll_back_role_application_audit_and_outbox(string $stage): void
    {
        $application = ProviderApplication::factory()->create();
        $this->administrator();
        ($stage === 'audit' ? OnboardingAuditEvent::class : OnboardingNotification::class)::creating(fn () => throw new \RuntimeException('PRIVATE QA CONTACT'));
        $this->decide($application, ['decision' => 'approved'])->assertStatus(500)->assertJsonPath('code', 'REGISTRATION_UNAVAILABLE')->assertDontSee('PRIVATE QA');
        $this->assertSame('pending', $application->refresh()->status);
        $this->assertSame(UserRole::BUSINESS_USER, $application->applicant->refresh()->role);
        $this->assertDatabaseCount('onboarding_audit_events', 0);
        $this->assertDatabaseCount('onboarding_notification_outbox', 0);
    }

    public static function failureStages(): array
    {
        return [['audit'], ['outbox']];
    }

    public function test_unverified_applicant_cannot_be_approved(): void
    {
        $application = ProviderApplication::factory()->create();
        $application->applicant->forceFill(['email_verified_at' => null])->save();
        $this->administrator();
        $this->decide($application, ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('pending', $application->refresh()->status);
    }

    public function test_guests_applicants_and_other_roles_cannot_review_or_decide(): void
    {
        $application = ProviderApplication::factory()->create();
        $this->getJson('/api/v1/admin/provider-applications')->assertUnauthorized();
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'password'])->assertUnauthorized();
        $this->decide($application, ['decision' => 'approved'])->assertUnauthorized();
        $this->postJson('/api/v1/provider-applications', $this->payload())->assertUnauthorized();
        foreach ([$application->applicant, User::factory()->businessUser()->create(), User::factory()->propertyProvider()->create(), User::factory()->administrator()->unverified()->create()] as $user) {
            $this->actingAs($user, 'web')->getJson('/api/v1/admin/provider-applications/'.$application->id)->assertForbidden();
            $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'password'])->assertForbidden();
            $this->decide($application, ['decision' => 'approved'])->assertForbidden();
        }
        $this->assertSame('pending', $application->refresh()->status);
    }

    public function test_review_scope_pagination_allowlists_and_missing_ids(): void
    {
        $first = ProviderApplication::factory()->create(['submitted_at' => now()->subMinute()]);
        $last = ProviderApplication::factory()->create();
        ProviderApplication::factory()->rejected()->create();
        $outside = ProviderApplication::factory()->create(['applicant_user_id' => User::factory()->businessUser()->create()->id]);
        $this->administrator();
        $this->getJson('/api/v1/admin/provider-applications?status=pending&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $last->id)->assertJsonMissingPath('data.0.applicant');
        $this->getJson('/api/v1/admin/provider-applications?status=pending&per_page=1&page=2')->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/admin/provider-applications/'.$last->id)->assertOk()->assertJsonPath('data.applicant.id', $last->applicant_user_id)
            ->assertJsonMissingPath('data.applicant.password')->assertJsonMissingPath('data.decided_by');
        foreach (['per_page=101', 'include=password', 'status=other'] as $query) {
            $this->getJson('/api/v1/admin/provider-applications?'.$query)->assertUnprocessable();
        }
        foreach ([$outside->id, '9999999999999999999999', 'not-an-id'] as $id) {
            $this->getJson('/api/v1/admin/provider-applications/'.$id)->assertNotFound()->assertJsonPath('code', 'APPLICATION_NOT_FOUND');
        }
    }

    public function test_self_review_is_not_allowed(): void
    {
        $admin = $this->administrator();
        $application = ProviderApplication::factory()->create(['applicant_user_id' => $admin->id]);
        $this->decide($application, ['decision' => 'approved'])->assertNotFound()->assertJsonPath('code', 'APPLICATION_NOT_FOUND');
        $this->assertSame('pending', $application->refresh()->status);
    }

    public function test_password_and_decision_rate_limits_include_noops(): void
    {
        $application = ProviderApplication::factory()->create();
        $this->actingAs(User::factory()->administrator()->create(), 'web');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'wrong'])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/admin/password-confirmation', ['password' => 'password'])->assertNoContent();
        for ($i = 0; $i < 30; $i++) {
            $this->decide($application, ['decision' => 'approved'])->assertOk();
        }
        $this->decide($application, ['decision' => 'approved'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('onboarding_audit_events', 1);
    }

    public function test_decision_outbox_retries_without_duplicate_audit_or_synchronous_mail(): void
    {
        $application = ProviderApplication::factory()->create();
        $this->administrator();
        $this->decide($application, ['decision' => 'rejected', 'public_reason' => 'Not suitable for this platform.'])->assertOk();
        Notification::assertNothingSent();
        $intent = OnboardingNotification::sole();
        config(['mail.default' => 'log']);
        $this->assertFalse(app(OnboardingDeliveryService::class)->deliver($intent->id));
        $this->assertSame('DELIVERY_UNAVAILABLE', $intent->refresh()->last_error_code);
        $this->travel(61)->seconds();
        config(['mail.default' => 'smtp']);
        $this->artisan('onboarding:deliver')->assertSuccessful();
        Notification::assertSentTo($application->applicant, ProviderApplicationDecision::class, fn ($notification) => $notification->status === 'rejected');
        $this->assertNotNull($intent->refresh()->sent_at);
        $this->assertDatabaseCount('onboarding_audit_events', 1);
    }

    public function test_direct_service_and_csrf_cannot_bypass_protections(): void
    {
        $user = User::factory()->providerApplicant()->create();
        try {
            app(ProviderApplicationService::class)->submit($user, $this->payload());
            $this->fail('Default-off gate must protect direct service calls.');
        } catch (OnboardingException $exception) {
            $this->assertSame('REGISTRATION_DISABLED', $exception->errorCode);
        }
        $this->actingAs($user, 'web');
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/api/v1/provider-applications', $this->payload(), ['Origin' => 'http://localhost:5173'])
                ->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_approved_matching_retry_stays_idempotent_and_unverified_provider_uses_exact_error(): void
    {
        config(['features.registration_enabled' => true]);
        $application = ProviderApplication::factory()->approved()->create();
        $user = $application->applicant;
        $payload = $application->only(['business_name', 'business_location', 'application_message']);
        $this->actingAs($user, 'web')->postJson('/api/v1/provider-applications', $payload)->assertOk();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/api/v1/provider-applications', $payload)->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
        $this->assertDatabaseCount('provider_applications', 1);
    }

    public function test_admin_verification_revocation_and_pending_forged_provider_role_remain_restricted(): void
    {
        $application = ProviderApplication::factory()->create();
        $admin = $this->administrator();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->decide($application, ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $applicant = $application->applicant;
        $applicant->forceFill(['role' => 'property_provider'])->save();
        config(['features.registration_enabled' => true]);
        $this->actingAs($applicant, 'web')->postJson('/api/v1/provider-applications', $this->payload())->assertForbidden();
        $this->assertFalse(AccountEligibility::allows($applicant, UserRole::PROPERTY_PROVIDER));
    }

    public function test_direct_decision_service_rejects_non_reviewers_and_missing_confirmation(): void
    {
        $application = ProviderApplication::factory()->create();
        foreach ([$application->applicant, User::factory()->administrator()->create()] as $actor) {
            try {
                app(ProviderApplicationService::class)->decide($actor, app('session')->driver(), (string) $application->id, ['decision' => 'approved']);
                $this->fail('Direct service writes require the same participant and session authorization.');
            } catch (OnboardingException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame('pending', $application->refresh()->status);
    }
}
