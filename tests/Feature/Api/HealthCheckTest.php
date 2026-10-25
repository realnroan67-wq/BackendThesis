<?php

namespace Tests\Feature\Api;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /** A real test-database query proves readiness; no RefreshDatabase/reset is needed. */
    public function test_health_returns_200_when_postgresql_is_connected(): void
    {
        $database = DB::selectOne('SELECT current_database() AS name, current_user AS role');
        $this->assertSame('horizon_realty_test', $database->name);
        $this->assertSame('horizon_test', $database->role);

        $this->getJson('/api/v1/health')->assertOk()->assertExactJson([
            'status' => 'ok',
            'service' => 'horizon-realty-api',
            'database' => 'connected',
        ])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_test_role_cannot_connect_to_development_database(): void
    {
        $connection = config('database.connections.pgsql');
        $connection['database'] = 'horizon_realty';
        config(['database.connections.forbidden_development' => $connection]);

        try {
            DB::connection('forbidden_development')->select('SELECT 1');
            $this->fail('The test role must not connect to the development database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('permission denied for database', $exception->getMessage());
        } finally {
            DB::purge('forbidden_development');
        }
    }
}
