<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real processes need committed fixtures: each test owns a disposable PostgreSQL schema. */
class RegistrationConcurrencyTest extends TestCase
{
    private string $schema;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.registration_enabled' => true]);
        $this->schema = 'registration_test_'.bin2hex(random_bytes(8));
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
                    || preg_match('/\Aregistration_test_[a-f0-9]{16}\z/', $this->schema) !== 1) {
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/registration-worker.php')], base_path(), [
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

    private function payload(): array
    {
        return ['name' => 'Concurrent QA', 'email' => 'concurrent@example.test',
            'password' => 'Unique concurrency passphrase 74!', 'password_confirmation' => 'Unique concurrency passphrase 74!',
            'account_type' => 'business_user'];
    }

    public function test_overlapping_normalized_duplicate_registration_creates_one_identity_and_intent(): void
    {
        $lock = random_int(100000, 99999999);
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lock]);
        $firstInput = ['operation' => 'register', 'tag' => $this->schema.'_first', 'payload' => $this->payload(), 'notification_lock' => $lock];
        $first = $this->startWorker($firstInput);
        $this->waitForDatabaseLock($firstInput['tag'], $first);
        $secondInput = ['operation' => 'register', 'tag' => $this->schema.'_second', 'payload' => array_replace($this->payload(), ['email' => ' Concurrent@Example.test '])];
        $second = $this->startWorker($secondInput);
        $this->waitForDatabaseLock($secondInput['tag'], $second);
        DB::commit();
        $one = $this->workerResult($first);
        $two = $this->workerResult($second);
        $this->assertSame(202, $one['status']);
        $this->assertSame(202, $two['status']);
        $this->assertSame($one['body'], $two['body']);
        $this->assertSame(1, User::where('email', 'concurrent@example.test')->count());
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
        $this->assertDatabaseCount('onboarding_audit_events', 0);
    }

    public function test_overlapping_verification_commits_one_timestamp_and_audit(): void
    {
        $user = User::factory()->selfRegisteredBusiness()->unverified()->create();
        $lock = random_int(100000, 99999999);
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lock]);
        $input = ['operation' => 'verify', 'user_id' => $user->id, 'tag' => $this->schema.'_verify_first', 'audit_lock' => $lock];
        $first = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $first);
        $input['tag'] = $this->schema.'_verify_second';
        unset($input['audit_lock']);
        $second = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $second);
        DB::commit();
        $this->assertSame(200, $this->workerResult($first)['status']);
        $this->assertSame(200, $this->workerResult($second)['status']);
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertDatabaseCount('onboarding_audit_events', 1);
    }

    public function test_overlapping_resends_coalesce_under_user_lock(): void
    {
        $user = User::factory()->unverified()->create();
        $lock = random_int(100000, 99999999);
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lock]);
        $input = ['operation' => 'resend', 'user_id' => $user->id, 'tag' => $this->schema.'_resend_first', 'notification_lock' => $lock];
        $first = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $first);
        $input['tag'] = $this->schema.'_resend_second';
        unset($input['notification_lock']);
        $second = $this->startWorker($input);
        $this->waitForDatabaseLock($input['tag'], $second);
        DB::commit();
        $this->assertSame(202, $this->workerResult($first)['status']);
        $this->assertSame(202, $this->workerResult($second)['status']);
        $this->assertDatabaseCount('onboarding_notification_outbox', 1);
    }
}
