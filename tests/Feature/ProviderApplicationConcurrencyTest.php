<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProviderApplication;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real processes need committed fixtures: each test owns a disposable PostgreSQL schema. */
class ProviderApplicationConcurrencyTest extends TestCase
{
    private string $schema;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.registration_enabled' => true]);
        $this->schema = 'application_test_'.bin2hex(random_bytes(8));
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
                    || preg_match('/\Aapplication_test_[a-f0-9]{16}\z/', $this->schema) !== 1) {
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/provider-application-worker.php')], base_path(), [
            'REGISTRATION_ENABLED' => 'true', 'INQUIRY_SUBMISSION_ENABLED' => 'false', 'APP_URL' => 'http://localhost', 'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1', 'ARGON_THREADS' => '1', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
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

    public function test_concurrent_matching_application_submissions_create_once(): void
    {
        $actor = User::factory()->providerApplicant()->create();
        $lock = random_int(100000, 99999999);
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lock]);
        $input = ['operation' => 'application', 'actor_id' => $actor->id, 'tag' => $this->schema.'_first', 'application_lock' => $lock,
            'body' => ['business_name' => 'QA company', 'business_location' => 'QA location', 'application_message' => 'Please consider our provider application.']];
        $first = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $first);
        unset($input['application_lock']);
        $input['tag'] = $this->schema.'_second';
        $second = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $second);
        DB::commit();
        $this->assertSame(201, $this->workerResult($first)['status']);
        $this->assertSame(200, $this->workerResult($second)['status']);
        $this->assertDatabaseCount('provider_applications', 1);
    }

    #[DataProvider('decisions')]
    public function test_overlapping_decisions_are_atomic_and_final(string $secondDecision, int $expected): void
    {
        $application = ProviderApplication::factory()->create();
        $firstAdmin = User::factory()->administrator()->create();
        $secondAdmin = User::factory()->administrator()->create();
        $lock = random_int(100000, 99999999);
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lock]);
        $input = ['operation' => 'decision', 'actor_id' => $firstAdmin->id, 'id' => $application->id,
            'tag' => $this->schema.'_approve', 'audit_lock' => $lock, 'body' => ['decision' => 'approved']];
        $first = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $first);
        $input['actor_id'] = $secondAdmin->id;
        $input['tag'] = $this->schema.'_other';
        unset($input['audit_lock']);
        $input['body'] = $secondDecision === 'approved' ? ['decision' => 'approved'] : ['decision' => 'rejected', 'public_reason' => 'Parallel rejection.'];
        $second = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $second);
        DB::commit();
        $this->assertSame(200, $this->workerResult($first)['status']);
        $this->assertSame($expected, $this->workerResult($second)['status']);
        $this->assertSame('approved', $application->refresh()->status);
        $this->assertSame($firstAdmin->id, $application->decided_by);
        $this->assertSame(UserRole::PROPERTY_PROVIDER, $application->applicant->role);
        $this->assertDatabaseCount('onboarding_audit_events', 1);
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
    }

    public static function decisions(): array
    {
        return [['approved', 200], ['rejected', 409]];
    }

    public function test_administrator_role_change_committed_while_decision_waits_prevents_approval(): void
    {
        $application = ProviderApplication::factory()->create();
        $admin = User::factory()->administrator()->create();
        $barrier = random_int(100000, 99999999);
        DB::select('SELECT pg_advisory_lock(?)', [$barrier]);
        try {
            $input = ['operation' => 'decision', 'actor_id' => $admin->id, 'id' => $application->id,
                'tag' => $this->schema.'_recheck', 'ready_lock' => $barrier, 'body' => ['decision' => 'approved']];
            $worker = $this->startWorker($input);
            $this->waitForDatabaseLock($input['tag'], $worker);
            DB::beginTransaction();
            User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            DB::select('SELECT pg_advisory_unlock(?)', [$barrier]);
            // Wait for the worker to leave its readiness barrier and block on the actor row.
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $row = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock' AND query LIKE '%for update%') AS waiting", [$input['tag']]);
                if ($row->waiting) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($row->waiting);
            User::query()->whereKey($admin->id)->update(['role' => 'business_user']);
            DB::commit();
            $this->assertSame(403, $this->workerResult($worker)['status']);
            $this->assertSame('pending', $application->refresh()->status);
            $this->assertDatabaseCount('onboarding_audit_events', 0);
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [$barrier]);
        }
    }
}
