<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthDatabaseFailureTest extends TestCase
{
    public function test_health_returns_503_without_internal_details_when_database_is_unreachable(): void
    {
        config(['app.debug' => true, 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson([
            'status' => 'unavailable',
            'service' => 'horizon-realty-api',
            'database' => 'unavailable',
        ])->assertHeader('Cache-Control', 'no-store, private');
    }
}
