<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\InquiryProviderGrant;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real processes need committed fixtures: each test owns a disposable PostgreSQL schema. */
class InquiryConcurrencyTest extends TestCase
{
    private string $schema;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.inquiry_submission_enabled' => true]);
        $this->schema = 'inquiry_test_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        Artisan::call('migrate', ['--database' => 'pgsql', '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (isset($this->schema)) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                config(['database.connections.pgsql.search_path' => 'public']);
                DB::purge('pgsql');
                if (config('database.connections.pgsql.database') !== 'horizon_realty_test'
                    || preg_match('/\Ainquiry_test_[a-f0-9]{16}\z/', $this->schema) !== 1) {
                    throw new \RuntimeException('Refusing to clean a non-test schema.');
                }
                DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function startWorker(array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $process = new Process([PHP_BINARY, base_path('tests/Support/inquiry-worker.php')], base_path(), [
            'INQUIRY_SUBMISSION_ENABLED' => 'true', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => 'horizon_realty_test', 'DB_USERNAME' => 'horizon_test', 'DB_PASSWORD' => $connection['password'],
            'DB_SCHEMA' => $this->schema, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'PHP_INI_SCAN_DIR' => base_path('.runtime/php'),
        ], json_encode($input, JSON_THROW_ON_ERROR), 20);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function workerResult(Process $process): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitForDatabaseLock(string $tag, Process $process): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $row = DB::selectOne('SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = \'Lock\') AS waiting', [$tag]);
            if ($row->waiting) {
                $this->assertTrue(true);

                return;
            }
            if (! $process->isRunning()) {
                $this->fail('Worker ended before the expected database lock: '.$process->getOutput());
            }
            // Bounded polling waits for a real lock signal, not a guessed race delay.
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach the expected PostgreSQL lock.');
    }

    private function attempt(Property $property): array
    {
        return ['operation' => 'create', 'tag' => $this->schema.'_create', 'user_id' => User::factory()->businessUser()->create()->id,
            'key' => (string) Str::uuid(), 'payload' => ['property_id' => $property->id, 'contact_name' => 'Parallel business',
                'contact_email' => 'parallel@example.test', 'message' => 'Please arrange a business viewing for this property.']];
    }

    public function test_simultaneous_duplicate_http_requests_create_once_and_matching_retry_replays(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $attempt = $this->attempt($property);
        DB::beginTransaction();
        Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
        $first = $this->startWorker($attempt);
        $this->waitForDatabaseLock($attempt['tag'], $first);
        $other = $attempt;
        $other['tag'] = $this->schema.'_duplicate';
        $overlapping = $this->workerResult($this->startWorker($other));
        $this->assertSame(409, $overlapping['status']);
        $this->assertSame('IDEMPOTENCY_IN_PROGRESS', json_decode($overlapping['body'], true)['code']);
        DB::commit();
        $committed = $this->workerResult($first);
        $this->assertSame(201, $committed['status'], $committed['body']);
        $retry = $this->workerResult($this->startWorker($other));
        $this->assertSame(201, $retry['status']);
        $this->assertSame('true', $retry['replayed']);
        $this->assertSame($committed['body'], $retry['body']);
        $this->assertDatabaseCount('inquiries', 1);
        $this->assertDatabaseCount('inquiry_provider_grants', 1);
        $this->assertDatabaseCount('inquiry_idempotency_results', 1);
    }

    public function test_creation_waits_for_transfer_then_uses_the_committed_new_owner_for_both_records(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $newOwner = User::factory()->propertyProvider()->create();
        $attempt = $this->attempt($property);
        DB::beginTransaction();
        DB::table('properties')->where('id', $property->id)->update(['provider_id' => $newOwner->id]);
        $worker = $this->startWorker($attempt);
        $this->waitForDatabaseLock($attempt['tag'], $worker);
        DB::commit();
        $response = $this->workerResult($worker);
        $this->assertSame(201, $response['status'], $response['body']);
        $data = json_decode($response['body'], true)['data'];
        $this->assertSame($newOwner->id, $data['provider_id']);
        $this->assertDatabaseHas('inquiry_provider_grants', ['inquiry_id' => $data['id'], 'provider_id' => $newOwner->id]);
    }

    public function test_transfer_waits_for_inflight_creation_and_does_not_rewrite_its_recipient_or_grant(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $newOwner = User::factory()->propertyProvider()->create();
        $attempt = $this->attempt($property);
        DB::beginTransaction();
        User::query()->whereKey($property->provider_id)->lockForUpdate()->firstOrFail();
        $creation = $this->startWorker($attempt);
        $this->waitForDatabaseLock($attempt['tag'], $creation);
        $transferTag = $this->schema.'_transfer';
        $transfer = $this->startWorker(['operation' => 'transfer', 'tag' => $transferTag,
            'property_id' => $property->id, 'provider_id' => $newOwner->id]);
        $this->waitForDatabaseLock($transferTag, $transfer);
        DB::commit();
        $response = $this->workerResult($creation);
        $this->assertSame(201, $response['status'], $response['body']);
        $this->assertSame(200, $this->workerResult($transfer)['status']);
        $data = json_decode($response['body'], true)['data'];
        $this->assertSame($property->provider_id, $data['provider_id']);
        $this->assertSame($newOwner->id, $property->fresh()->provider_id);
        $this->assertDatabaseHas('inquiry_provider_grants', ['inquiry_id' => $data['id'], 'provider_id' => $property->provider_id]);
        $this->assertDatabaseMissing('inquiry_provider_grants', ['inquiry_id' => $data['id'], 'provider_id' => $newOwner->id]);
    }

    public function test_concurrent_closes_serialize_to_one_change_and_one_noop_with_identical_responses(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $input = ['operation' => 'close', 'inquiry_id' => $inquiry->id, 'user_id' => $inquiry->provider_id, 'tag' => $this->schema.'_close_first'];
        DB::beginTransaction();
        Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
        $first = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $first);
        $input['tag'] = $this->schema.'_close_second';
        $second = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $second);
        DB::commit();
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);
        $this->assertSame(200, $firstResult['status'], $firstResult['body']);
        $this->assertSame(200, $secondResult['status'], $secondResult['body']);
        $this->assertSame($firstResult['body'], $secondResult['body']);
        $this->assertSame('closed', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_status_changes', 1);
    }

    public function test_revocation_committed_while_close_waits_prevents_the_status_change_and_audit(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $grant = $inquiry->providerGrants()->firstOrFail();
        DB::beginTransaction();
        $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $inquiry->provider_id, 'revocation_reason' => 'Parallel QA revocation'])->save();
        $tag = $this->schema.'_close_revoked';
        $worker = $this->startWorker(['operation' => 'close', 'inquiry_id' => $inquiry->id, 'user_id' => $inquiry->provider_id, 'tag' => $tag]);
        $this->waitForDatabaseLock($tag, $worker);
        DB::commit();
        $result = $this->workerResult($worker);
        $this->assertSame(404, $result['status'], $result['body']);
        $this->assertSame('INQUIRY_NOT_FOUND', json_decode($result['body'], true)['code']);
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }

    public function test_inflight_close_holds_its_grant_until_status_and_audit_commit_before_revocation(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        $grant = $inquiry->providerGrants()->firstOrFail();
        $barrier = random_int(1, 2147483647);
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(?)', [$barrier]);
        $closeTag = $this->schema.'_closing_audit';
        $closing = $this->startWorker(['operation' => 'close', 'inquiry_id' => $inquiry->id, 'user_id' => $inquiry->provider_id, 'tag' => $closeTag, 'audit_lock' => $barrier]);
        $this->waitForDatabaseLock($closeTag, $closing);
        $revokeTag = $this->schema.'_revoke_after_close';
        $revoking = $this->startWorker(['operation' => 'revoke', 'grant_id' => $grant->id, 'user_id' => $inquiry->provider_id, 'tag' => $revokeTag]);
        $this->waitForDatabaseLock($revokeTag, $revoking);
        DB::commit();
        $result = $this->workerResult($closing);
        $this->assertSame(200, $result['status'], $result['body']);
        $this->assertSame(200, $this->workerResult($revoking)['status']);
        $this->assertSame('closed', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_status_changes', 1);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertSame(0, InquiryProviderGrant::query()->active()->count());
        $retry = $this->workerResult($this->startWorker(['operation' => 'close', 'inquiry_id' => $inquiry->id, 'user_id' => $inquiry->provider_id, 'tag' => $this->schema.'_revoked_noop']));
        $this->assertSame(404, $retry['status']);
        $this->assertDatabaseCount('inquiry_status_changes', 1);
    }

    public function test_closing_waits_for_role_change_and_rechecks_the_committed_role(): void
    {
        $inquiry = Inquiry::factory()->withRecipientGrant()->create();
        DB::beginTransaction();
        User::query()->whereKey($inquiry->provider_id)->update(['role' => UserRole::BUSINESS_USER]);
        $tag = $this->schema.'_close_role_change';
        $worker = $this->startWorker(['operation' => 'close', 'inquiry_id' => $inquiry->id, 'user_id' => $inquiry->provider_id, 'tag' => $tag]);
        $this->waitForDatabaseLock($tag, $worker);
        DB::commit();
        $result = $this->workerResult($worker);
        $this->assertSame(403, $result['status'], $result['body']);
        $this->assertSame('pending', $inquiry->fresh()->status);
        $this->assertDatabaseCount('inquiry_status_changes', 0);
    }
}
