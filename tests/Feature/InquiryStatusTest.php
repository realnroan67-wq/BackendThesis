<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\InquiryException;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\InquiryStatusChange;
use App\Models\User;
use App\Services\InquiryStatusService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InquiryStatusTest extends TestCase
{
    use DatabaseTransactions;

    private function path(Inquiry $inquiry): string
    {
        return '/api/v1/provider/inquiries/'.$inquiry->id.'/status';
    }

    private function signIn(User $actor): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($actor, 'web');
    }

    public function test_active_provider_closes_atomically_with_one_internal_audit_and_an_allowlisted_response(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create(['updated_at' => now()->subDay()]);
        $before = $inquiry->fresh()->getRawOriginal();
        $grant = $inquiry->providerGrants()->first()->getRawOriginal();
        $response = $this->signIn($inquiry->historicalProvider)->patchJson($this->path($inquiry), ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['id', 'property_id', 'business_user_id', 'provider_id', 'business_name', 'contact_name', 'contact_email', 'contact_phone', 'message', 'status', 'created_at', 'updated_at'], array_keys($response->json('data')));
        $this->assertNotSame($before['updated_at'], $inquiry->fresh()->getRawOriginal('updated_at'));
        $this->assertSame(array_diff_key($before, ['status' => true, 'updated_at' => true]), array_diff_key($inquiry->fresh()->getRawOriginal(), ['status' => true, 'updated_at' => true]));
        $this->assertSame($grant, $inquiry->providerGrants()->first()->getRawOriginal());
        $audit = InquiryStatusChange::sole();
        $this->assertSame($inquiry->id, $audit->inquiry_id);
        $this->assertSame($inquiry->provider_id, $audit->actor_id);
        $this->assertSame('pending', $audit->previous_status);
        $this->assertSame('closed', $audit->new_status);
        $this->assertNotNull($audit->changed_at);
        $this->assertTrue($audit->inquiry->is($inquiry));
        $this->assertTrue($audit->actor->is($inquiry->historicalProvider));
        $this->assertStringNotContainsString('actor_id', $response->getContent());
        $this->assertStringNotContainsString('changed_at', $response->getContent());
        $this->assertDatabaseCount('inquiry_idempotency_results', 0);
        $this->signIn($inquiry->sender)->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_repeated_close_is_an_authorized_noop_without_timestamp_or_audit_changes(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $this->signIn($inquiry->historicalProvider);
        $first = $this->patchJson($this->path($inquiry), ['status' => 'closed'])->assertOk();
        $before = $inquiry->fresh()->getRawOriginal();
        $audit = InquiryStatusChange::sole()->getRawOriginal();
        $this->travel(1)->hours();
        $this->patchJson($this->path($inquiry), ['status' => 'closed'])->assertOk()->assertExactJson($first->json());
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertSame($audit, InquiryStatusChange::sole()->getRawOriginal());
    }

    public function test_legacy_closed_inquiry_noop_does_not_invent_a_historical_event(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create(['status' => 'closed']);
        $before = $inquiry->fresh()->getRawOriginal();
        $this->signIn($inquiry->historicalProvider)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertOk();
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    public function test_guest_and_wrong_roles_are_rejected_before_inquiry_disclosure(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $this->patchJson($this->path($inquiry), ['status' => 'closed'])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        foreach ([$inquiry->sender, User::factory()->administrator()->create()] as $actor) {
            InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $actor->id]);
            $this->signIn($actor)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
            $this->patchJson('/api/v1/provider/inquiries/9007199254740991/status', ['status' => 'closed'])->assertForbidden();
        }
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    public function test_historical_recipient_and_current_owner_without_grants_cannot_close(): void
    {
        $inquiry = Inquiry::factory()->create();
        $newOwner = User::factory()->propertyProvider()->create();
        DB::table('properties')->where('id', $inquiry->property_id)->update(['provider_id' => $newOwner->id]);
        $expected = null;
        foreach ([$inquiry->historicalProvider, $newOwner, User::factory()->propertyProvider()->create()] as $actor) {
            $response = $this->signIn($actor)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertNotFound()->assertJsonPath('code', 'INQUIRY_NOT_FOUND');
            $expected ??= $response->json();
            $this->assertSame($expected, $response->json());
        }
        $this->patchJson('/api/v1/provider/inquiries/9007199254740991/status', ['status' => 'closed'])->assertNotFound()->assertExactJson($expected);
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    public function test_explicit_nonhistorical_grant_can_close_and_records_the_actual_actor_without_rewriting_recipient(): void
    {
        $inquiry = Inquiry::factory()->create();
        $actor = User::factory()->propertyProvider()->create();
        InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $actor->id]);
        $this->signIn($actor)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertOk()->assertJsonPath('data.provider_id', $inquiry->provider_id);
        $this->assertSame($actor->id, InquiryStatusChange::sole()->actor_id);
        $this->assertSame($inquiry->provider_id, $inquiry->fresh()->provider_id);
    }

    public function test_property_transfer_and_unpublishing_do_not_remove_an_existing_provider_grant(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $newOwner = User::factory()->propertyProvider()->create();
        DB::table('properties')->where('id', $inquiry->property_id)->update(['provider_id' => $newOwner->id, 'is_published' => false, 'accepts_inquiries' => false]);
        $this->signIn($inquiry->historicalProvider)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertOk();
        $this->assertSame($inquiry->provider_id, $inquiry->fresh()->provider_id);
    }

    #[DataProvider('statuses')]
    public function test_revocation_denies_both_transitions_and_closed_noops_with_cached_relations(string $status): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create(['status' => $status]);
        $actor = $inquiry->historicalProvider;
        $inquiry->load('providerGrants');
        $inquiry->providerGrants->first()->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => 'QA revoked'])->save();
        $before = $inquiry->fresh()->getRawOriginal();
        $this->signIn($actor)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertNotFound();
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    public static function statuses(): array
    {
        return [['pending'], ['closed']];
    }

    public function test_future_grant_and_role_change_cannot_authorize_an_update(): void
    {
        $inquiry = Inquiry::factory()->create();
        $actor = $inquiry->historicalProvider;
        InquiryProviderGrant::factory()->for($inquiry)->create(['provider_id' => $actor->id, 'granted_at' => now()->addDay()]);
        $this->signIn($actor)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertNotFound();
        User::query()->whereKey($actor->id)->update(['role' => UserRole::BUSINESS_USER]);
        $this->patchJson($this->path($inquiry), ['status' => 'closed'])->assertForbidden();
        $this->expectException(InquiryException::class);
        app(InquiryStatusService::class)->close($actor, (string) $inquiry->id, 'closed');
    }

    #[DataProvider('invalidPayloads')]
    public function test_unsupported_statuses_reopening_and_forged_fields_are_rejected(string $initial, array $payload, string $field): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create(['status' => $initial]);
        $before = $inquiry->fresh()->getRawOriginal();
        $this->signIn($inquiry->historicalProvider)->patchJson($this->path($inquiry), $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors($field);
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            ['pending', ['status' => 'responded'], 'status'], ['pending', ['status' => 'pending'], 'status'],
            ['closed', ['status' => 'pending'], 'status'], ['pending', ['status' => null], 'status'],
            ['pending', ['status' => ['closed']], 'status'], ['pending', ['status' => true], 'status'],
            ['pending', ['status' => 'Closed'], 'status'], ['pending', ['status' => ' closed '], 'status'],
            ['pending', ['status' => 'closed', 'provider_id' => 1], 'payload'],
            ['pending', ['status' => 'closed', 'actor_id' => 1], 'payload'],
            ['pending', ['status' => 'closed', 'previous_status' => 'pending'], 'payload'],
            ['pending', ['status' => 'closed', 'grants' => []], 'payload'],
        ];
    }

    public function test_nonobject_or_nonjson_bodies_and_query_override_are_rejected(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $this->signIn($inquiry->historicalProvider);
        foreach (['[]', '"closed"', '{"status":', '{}'] as $body) {
            $this->call('PATCH', $this->path($inquiry), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)->assertUnprocessable();
        }
        $this->call('PATCH', $this->path($inquiry), ['status' => 'closed'], server: ['HTTP_ACCEPT' => 'application/json'])->assertUnprocessable();
        $this->patchJson($this->path($inquiry).'?status=closed', ['status' => 'closed'])->assertUnprocessable()->assertJsonValidationErrors('query');
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_detail_ids_are_safe_404(string $id): void
    {
        $actor = User::factory()->propertyProvider()->create();
        $this->signIn($actor)->patchJson('/api/v1/provider/inquiries/'.$id.'/status', ['status' => 'closed'])
            ->assertNotFound()->assertJsonPath('code', 'INQUIRY_NOT_FOUND')->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function invalidIds(): array
    {
        return array_map(fn (string $id): array => [$id], ['0', '-1', '01', '1.0', 'abc', '9007199254740992']);
    }

    public function test_audit_failure_rolls_back_status_and_timestamp_and_returns_sanitized_error(): void
    {
        config(['app.debug' => true]);
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $before = $inquiry->fresh()->getRawOriginal();
        InquiryStatusChange::creating(function (): void {
            throw new RuntimeException('PRIVATE AUDIT DATABASE FAILURE');
        });
        $this->signIn($inquiry->historicalProvider)->patchJson($this->path($inquiry), ['status' => 'closed'])
            ->assertStatus(500)->assertExactJson(['message' => 'Unable to process the inquiry.', 'code' => 'SERVER_ERROR']);
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    public function test_audit_history_is_immutable_and_only_one_pending_to_closed_event_is_possible(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $this->signIn($inquiry->historicalProvider)->patchJson($this->path($inquiry), ['status' => 'closed'])->assertOk();
        $audit = InquiryStatusChange::sole();
        foreach (['update', 'delete', 'duplicate', 'invalid-transition'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $audit): void {
                    match ($operation) {
                        'update' => DB::table('inquiry_status_changes')->where('id', $audit->id)->update(['actor_id' => $audit->actor_id]),
                        'delete' => DB::table('inquiry_status_changes')->where('id', $audit->id)->delete(),
                        'duplicate' => InquiryStatusChange::factory()->create(['inquiry_id' => $audit->inquiry_id, 'actor_id' => $audit->actor_id]),
                        'invalid-transition' => InquiryStatusChange::factory()->create(['previous_status' => 'closed', 'new_status' => 'pending']),
                    };
                });
                $this->fail('Audit history mutation unexpectedly succeeded.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->errorInfo[0], ['23505', '23514']);
            }
        }
        $this->assertDatabaseCount('inquiry_status_changes', 1);
        $this->assertSame($audit->getRawOriginal(), $audit->fresh()->getRawOriginal());
    }

    public function test_real_spa_session_requires_csrf_and_uses_its_authenticated_actor(): void
    {
        config(['session.driver' => 'database']);
        $this->withCredentials()->withHeader('Origin', 'http://localhost:5173');
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        }
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $actor = $inquiry->historicalProvider;
        $csrf = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $this->useSessionCookie($csrf);
        $this->withHeader('X-XSRF-TOKEN', rawurldecode($csrf->getCookie('XSRF-TOKEN', false)->getValue()));
        $login = $this->postJson('/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
        $this->useSessionCookie($login);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('X-XSRF-TOKEN', 'invalid')->patchJson($this->path($inquiry), ['status' => 'closed'])
            ->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->withHeader('X-XSRF-TOKEN', rawurldecode($login->getCookie('XSRF-TOKEN', false)->getValue()));
        $this->patchJson($this->path($inquiry), ['status' => 'closed'])->assertOk();
        $this->assertSame($actor->id, InquiryStatusChange::sole()->actor_id);
    }

    private function useSessionCookie(TestResponse $response): void
    {
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie);
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}
