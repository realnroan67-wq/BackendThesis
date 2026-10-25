<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_credentials_create_a_rotated_session_that_reads_profile_then_logs_out(): void
    {
        config(['session.driver' => 'database']);
        $this->withCredentials();
        $user = User::factory()->businessUser()->create();
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $oldId = session()->getId();
        $login = $this->postJson('/login', ['email' => $user->email, 'password' => 'password']);
        $login->assertOk()->assertExactJson(['message' => 'Authenticated.']);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertAuthenticatedAs($user, 'web');
        $id = session()->getId();
        $this->assertDatabaseHas('sessions', ['id' => $id, 'user_id' => $user->id]);
        $this->useSessionCookie($login);
        $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/me')
            ->assertOk()->assertExactJson(['data' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'business_user',
            ]])->assertHeader('Cache-Control', 'no-store, private');
        $logout = $this->postJson('/logout');
        $logout->assertOk()->assertExactJson(['message' => 'Logged out.']);
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
        $this->useSessionCookie($logout);
        $this->getJson('/api/v1/me')->assertUnauthorized();
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

    public function test_invalid_credentials_do_not_create_authenticated_session(): void
    {
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
    }

    public function test_empty_login_is_rejected(): void
    {
        $this->postJson('/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertGuest('web');
    }

    public function test_me_and_logout_require_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/logout')->assertUnauthorized();
    }

    public function test_repeated_invalid_logins_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_trusted_origin_gets_credentialed_cors_but_untrusted_origin_does_not(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')->getJson('/sanctum/csrf-cookie')
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->withHeader('Origin', 'https://untrusted.example')->getJson('/sanctum/csrf-cookie')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_login_rejects_missing_csrf_when_production_verification_is_enabled(): void
    {
        $this->enforceCsrf();
        $this->postJson('/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])
            ->assertStatus(419);
    }

    public function test_login_accepts_matching_csrf_and_rejects_incorrect_csrf(): void
    {
        $this->enforceCsrf();
        $user = User::factory()->create();
        $this->withSession(['_token' => 'known-session-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'incorrect')
            ->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
        $this->withSession(['_token' => 'known-session-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'known-session-csrf')
            ->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_session_cookie_is_http_only_and_csrf_cookie_is_readable_with_local_same_site_defaults(): void
    {
        config(['session.secure' => false, 'session.domain' => null, 'session.same_site' => 'lax']);
        $response = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $sessionCookie = $response->getCookie(config('session.cookie'), false);
        $csrfCookie = $response->getCookie('XSRF-TOKEN', false);
        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($csrfCookie);
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertFalse($csrfCookie->isHttpOnly());
        $this->assertSame('lax', $sessionCookie->getSameSite());
        $this->assertNull($sessionCookie->getDomain());
        $this->assertFalse($sessionCookie->isSecure());

        config(['session.secure' => true]);
        $secure = $this->getJson('/sanctum/csrf-cookie')->getCookie(config('session.cookie'), false);
        $this->assertTrue($secure->isSecure());
    }

    public function test_real_login_cookies_and_csrf_protect_inquiry_creation(): void
    {
        config(['features.inquiry_submission_enabled' => true]);
        $this->enforceCsrf();
        config(['session.driver' => 'database']);
        $this->withCredentials()->withHeader('Origin', 'http://localhost:5173');
        $user = User::factory()->businessUser()->create();
        $property = Property::factory()->acceptingInquiries()->create();
        $csrf = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $this->useSessionCookie($csrf);
        $this->withHeader('X-XSRF-TOKEN', rawurldecode($csrf->getCookie('XSRF-TOKEN', false)->getValue()));
        $login = $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->useSessionCookie($login);
        $payload = ['property_id' => $property->id, 'contact_name' => 'Cookie business', 'contact_email' => 'contact@example.test', 'message' => 'Please arrange a viewing for our business.'];
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $this->withHeader('X-XSRF-TOKEN', 'invalid')->postJson('/api/v1/inquiries', $payload)
            ->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        $this->assertDatabaseCount('inquiries', 0);
        $this->withHeader('X-XSRF-TOKEN', rawurldecode($login->getCookie('XSRF-TOKEN', false)->getValue()));
        $this->postJson('/api/v1/inquiries', $payload)->assertCreated()->assertJsonPath('data.business_user_id', $user->id);
    }

    public function test_missing_session_on_stateful_inquiry_request_is_contract_401(): void
    {
        $this->enforceCsrf();
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/inquiries', [])
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    private function enforceCsrf(): void
    {
        // Override only the testing exemption; preserve all actual verification logic.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        }
    }
}
