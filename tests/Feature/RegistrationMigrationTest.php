<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AccountEligibility;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Legacy migration checks own disposable schemas; never reset a shared or application database. */
class RegistrationMigrationTest extends TestCase
{
    private string $schema;

    private const MIGRATION = '2026_10_21_001110_add_registration_foundation.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'registration_test_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        $paths = array_map(fn (string $file): string => 'database/migrations/'.basename($file),
            array_filter(glob(database_path('migrations/*.php')), fn (string $file): bool => basename($file) < self::MIGRATION));
        Artisan::call('migrate', ['--path' => $paths, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->schema)) {
                if (config('database.connections.pgsql.database') !== 'horizon_realty_test' || config('database.connections.pgsql.username') !== 'horizon_test'
                    || preg_match('/\Aregistration_test_[a-f0-9]{16}\z/', $this->schema) !== 1) {
                    throw new RuntimeException('Unsafe schema cleanup refused.');
                }
                config(['database.connections.pgsql.search_path' => 'public']);
                DB::purge('pgsql');
                DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function legacy(string $email, string $role = 'business_user', bool $verified = false): int
    {
        return DB::table('users')->insertGetId(['name' => 'Legacy QA', 'email' => $email, 'role' => $role, 'password' => Hash::make('legacy password'),
            'email_verified_at' => $verified ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_additive_migration_preserves_identity_verification_and_property_ownership(): void
    {
        $provider = $this->legacy('Provider@Example.test', 'property_provider', true);
        $business = $this->legacy('Business@Example.test');
        $property = DB::table('properties')->insertGetId(['provider_id' => $provider, 'title' => 'Existing QA property']);
        $inquiry = DB::table('inquiries')->insertGetId(['property_id' => $property, 'business_user_id' => $business,
            'provider_id' => $provider, 'contact_name' => 'Legacy contact', 'contact_email' => 'LegacyContact@Example.test',
            'message' => 'Please arrange a business viewing.', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inquiry_provider_grants')->insert(['inquiry_id' => $inquiry, 'provider_id' => $provider, 'granted_at' => now()]);
        $snapshot = (array) DB::table('inquiries')->where('id', $inquiry)->first();
        $before = DB::table('users')->where('id', $provider)->first();
        Artisan::call('migrate', ['--path' => 'database/migrations/'.self::MIGRATION, '--no-interaction' => true]);
        $this->assertDatabaseHas('users', ['id' => $provider, 'email' => 'provider@example.test', 'onboarding_origin' => 'provisioned']);
        $this->assertSame($before->email_verified_at, DB::table('users')->where('id', $provider)->value('email_verified_at'));
        $this->assertNull(DB::table('users')->where('id', $business)->value('email_verified_at'));
        $this->assertSame($provider, DB::table('properties')->where('id', $property)->value('provider_id'));
        $this->assertTrue(AccountEligibility::allows(User::findOrFail($provider), UserRole::PROPERTY_PROVIDER));
        $this->assertFalse(AccountEligibility::allows(User::findOrFail($business), UserRole::BUSINESS_USER));
        $this->assertSame($snapshot, (array) DB::table('inquiries')->where('id', $inquiry)->first());
        $this->assertDatabaseHas('inquiry_provider_grants', ['inquiry_id' => $inquiry, 'provider_id' => $provider, 'revoked_at' => null]);
        $this->assertDatabaseCount('provider_applications', 0);
        $this->assertDatabaseCount('onboarding_audit_events', 0);
    }

    public function test_colliding_legacy_email_stops_without_merging_or_partial_backfill(): void
    {
        $this->legacy('Duplicate@Example.test');
        $this->legacy('duplicate@example.test');
        $this->artisan('registration:preflight')->assertFailed();
        try {
            Artisan::call('migrate', ['--path' => 'database/migrations/'.self::MIGRATION, '--no-interaction' => true]);
            $this->fail('Canonical collision must block migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Canonical email collisions', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertFalse(Schema::hasColumn('users', 'onboarding_origin'));
        $this->assertDatabaseHas('users', ['email' => 'Duplicate@Example.test']);
    }

    public function test_preflight_blocks_an_unverified_last_administrator_without_mutating_legacy_data(): void
    {
        $id = $this->legacy('UnverifiedAdmin@Example.test', 'administrator');
        $before = (array) DB::table('users')->where('id', $id)->first();
        $this->artisan('registration:preflight')->assertFailed();
        $this->assertSame($before, (array) DB::table('users')->where('id', $id)->first());
        $this->assertFalse(Schema::hasColumn('users', 'onboarding_origin'));
    }

    public function test_preflight_passes_with_verified_administrator_and_preserves_all_verification_values(): void
    {
        $this->legacy('VerifiedAdmin@Example.test', 'administrator', true);
        $id = $this->legacy('UnverifiedBusiness@Example.test');
        $this->artisan('registration:preflight')->assertSuccessful();
        $this->assertNull(DB::table('users')->where('id', $id)->value('email_verified_at'));
        $this->assertFalse(Schema::hasColumn('users', 'onboarding_origin'));
    }
}
