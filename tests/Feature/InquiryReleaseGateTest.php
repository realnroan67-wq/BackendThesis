<?php

namespace Tests\Feature;

use App\Exceptions\InquiryException;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use App\Services\InquiryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InquiryReleaseGateTest extends TestCase
{
    use DatabaseTransactions;

    private function payload(Property $property): array
    {
        return ['property_id' => $property->id, 'contact_name' => 'QA Contact',
            'contact_email' => 'release-gate@example.test', 'message' => 'Please arrange a business viewing.'];
    }

    private function counts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(),
            ['inquiries', 'inquiry_provider_grants', 'inquiry_idempotency_results', 'inquiry_status_changes']);
    }

    public function test_default_configuration_rejects_direct_http_creation_before_payload_validation(): void
    {
        $this->assertFalse(config('features.inquiry_submission_enabled'));
        $before = $this->counts();
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->postJson('/api/v1/inquiries', [])
            ->assertStatus(503)->assertExactJson(['message' => 'Inquiry submission is currently unavailable.', 'code' => 'INQUIRY_SUBMISSION_DISABLED'])
            ->assertHeaderMissing('Location')->assertHeaderMissing('Idempotency-Replayed');
        $this->assertSame($before, $this->counts());
    }

    #[DataProvider('disabledValues')]
    public function test_disabled_or_malformed_configuration_fails_closed_without_writes(mixed $value): void
    {
        config(['features.inquiry_submission_enabled' => $value]);
        $property = Property::factory()->acceptingInquiries()->create();
        $before = $this->counts();
        $response = $this->actingAs(User::factory()->businessUser()->create(), 'web')
            ->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/inquiries', $this->payload($property))
            ->assertStatus(503)->assertJsonPath('code', 'INQUIRY_SUBMISSION_DISABLED');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->counts());
    }

    public static function disabledValues(): array
    {
        return [[false], [null], ['false'], ['true'], [0], [1]];
    }

    public function test_disabled_gate_preserves_authentication_and_role_precedence(): void
    {
        config(['features.inquiry_submission_enabled' => false]);
        $this->postJson('/api/v1/inquiries', [])->assertUnauthorized();
        foreach (['administrator', 'property_provider'] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')->postJson('/api/v1/inquiries', [])->assertForbidden();
        }
    }

    public function test_service_cannot_bypass_disabled_gate(): void
    {
        config(['features.inquiry_submission_enabled' => false]);
        $before = $this->counts();
        try {
            app(InquiryService::class)->create(User::factory()->businessUser()->create(), [], (string) Str::uuid());
            $this->fail('Disabled service creation must throw.');
        } catch (InquiryException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('INQUIRY_SUBMISSION_DISABLED', $exception->errorCode);
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_explicit_enable_preserves_creation_replay_conflict_and_disable_blocks_existing_keys(): void
    {
        config(['features.inquiry_submission_enabled' => true]);
        $property = Property::factory()->acceptingInquiries()->create();
        $payload = $this->payload($property);
        $this->actingAs(User::factory()->businessUser()->create(), 'web')->withHeader('Idempotency-Key', (string) Str::uuid());
        $first = $this->postJson('/api/v1/inquiries', $payload)->assertCreated();
        $before = $this->counts();
        $this->postJson('/api/v1/inquiries', $payload)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')
            ->assertContent($first->getContent());
        $this->postJson('/api/v1/inquiries', [...$payload, 'message' => 'A different business inquiry message.'])
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        config(['features.inquiry_submission_enabled' => false]);
        $this->postJson('/api/v1/inquiries', $payload)->assertStatus(503)->assertHeaderMissing('Idempotency-Replayed');
        $this->assertSame($before, $this->counts());
    }

    public function test_frontend_disabled_flag_cannot_override_explicit_backend_enablement(): void
    {
        $previous = getenv('VITE_ENABLE_INQUIRY_SUBMISSION');
        putenv('VITE_ENABLE_INQUIRY_SUBMISSION=false');
        try {
            config(['features.inquiry_submission_enabled' => true]);
            $property = Property::factory()->acceptingInquiries()->create();
            $this->actingAs(User::factory()->businessUser()->create(), 'web')
                ->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/inquiries', $this->payload($property))->assertCreated();
            $this->assertDatabaseCount('inquiries', 1);
            $this->assertDatabaseCount('inquiry_provider_grants', 1);
            $this->assertDatabaseCount('inquiry_idempotency_results', 1);
        } finally {
            putenv($previous === false ? 'VITE_ENABLE_INQUIRY_SUBMISSION' : 'VITE_ENABLE_INQUIRY_SUBMISSION='.$previous);
        }
    }

    public function test_disabled_creation_does_not_disable_history_details_inbox_or_close(): void
    {
        config(['features.inquiry_submission_enabled' => false]);
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $this->actingAs($inquiry->sender, 'web')->getJson('/api/v1/inquiries')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/inquiries/'.$inquiry->id)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($inquiry->historicalProvider, 'web')->getJson('/api/v1/provider/inquiries')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/v1/provider/inquiries/'.$inquiry->id.'/status', ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseCount('inquiry_status_changes', 1);
    }

    public function test_production_configuration_cache_freezes_the_explicit_gate_until_rebuilt(): void
    {
        $relative = 'bootstrap/cache/horizon-gate-'.bin2hex(random_bytes(12)).'.php';
        $path = base_path($relative);
        $environment = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_CONFIG_CACHE' => $relative,
            'INQUIRY_SUBMISSION_ENABLED' => 'false', 'PHP_INI_SCAN_DIR' => base_path('.runtime/php')];
        $read = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(config("features.inquiry_submission_enabled"));';
        try {
            (new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment))->mustRun();
            $this->assertFileExists($path);
            $environment['INQUIRY_SUBMISSION_ENABLED'] = 'true';
            $cached = new Process([PHP_BINARY, '-r', $read], base_path(), $environment);
            $cached->mustRun();
            $this->assertSame('false', trim($cached->getOutput()));
            (new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment))->mustRun();
            $enabled = new Process([PHP_BINARY, '-r', $read], base_path(), $environment);
            $enabled->mustRun();
            $this->assertSame('true', trim($enabled->getOutput()));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
