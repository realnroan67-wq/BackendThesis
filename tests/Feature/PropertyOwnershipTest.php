<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropertyOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Test harness only: no property management API is published in C1.
        Route::patch('/api/testing/properties/{property}', function (Request $request, Property $property) {
            $property->fill($request->validate(['title' => ['required', 'string', 'max:255']]))->save();

            return response()->json(['title' => $property->title]);
        })->middleware(['api', 'auth:sanctum', 'can:update,property']);
    }

    public function test_provider_relationships_and_management_scope_exclude_other_owners(): void
    {
        $provider = User::factory()->propertyProvider()->create();
        $own = $provider->properties()->create(['title' => 'Owner listing']);
        Property::factory()->create();
        $this->assertTrue($own->provider->is($provider));
        $this->assertSame([$own->id], $provider->properties()->pluck('id')->all());
        $this->assertSame([$own->id], Property::query()->ownedBy($provider)->pluck('id')->all());
    }

    #[DataProvider('roles')]
    public function test_permission_matrix_uses_real_owner_and_role(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $property = Property::factory()->create();
        $own = $role === UserRole::PROPERTY_PROVIDER
            ? Property::factory()->for($user, 'provider')->create()
            : $property;
        $gate = Gate::forUser($user);
        $isProvider = $role === UserRole::PROPERTY_PROVIDER;
        $this->assertSame($isProvider, $gate->allows('viewAny', Property::class));
        $this->assertSame($isProvider, $gate->allows('create', Property::class));
        $this->assertSame($isProvider, $gate->allows('view', $own));
        $this->assertSame($isProvider, $gate->allows('update', $own));
        $this->assertFalse($gate->allows('view', $property));
        $this->assertFalse($gate->allows('update', $property));
        $this->assertFalse($gate->allows('delete', $own));
        $this->assertFalse($gate->allows('transferOwnership', $own));
    }

    public static function roles(): array
    {
        return [
            'administrator' => [UserRole::ADMINISTRATOR],
            'business user' => [UserRole::BUSINESS_USER],
            'provider' => [UserRole::PROPERTY_PROVIDER],
        ];
    }

    public function test_owner_cannot_be_changed_through_mass_assignment(): void
    {
        $property = Property::factory()->create();
        $other = User::factory()->propertyProvider()->create();
        $property->fill(['provider_id' => $other->id, 'title' => 'Updated title'])->save();
        $this->assertDatabaseHas('properties', [
            'id' => $property->id, 'provider_id' => $property->provider_id, 'title' => 'Updated title',
        ]);
        $this->assertNotSame($other->id, $property->fresh()->provider_id);
    }

    #[DataProvider('nonProviderRoles')]
    public function test_non_provider_cannot_be_associated_as_owner(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->expectException(ValidationException::class);
        $user->properties()->create(['title' => 'Invalid owner']);
    }

    public static function nonProviderRoles(): array
    {
        return [[UserRole::ADMINISTRATOR], [UserRole::BUSINESS_USER]];
    }

    public function test_missing_owner_is_rejected_by_model(): void
    {
        $this->expectException(ValidationException::class);
        Property::create(['title' => 'No assigned owner', 'provider_id' => 999999]);
    }

    public function test_owner_foreign_key_blocks_nonexistent_users_even_for_raw_inserts(): void
    {
        $this->expectException(QueryException::class);
        DB::table('properties')->insert(['provider_id' => 999999, 'title' => 'Nonexistent owner']);
    }

    public function test_referenced_provider_cannot_be_deleted_and_listing_is_preserved(): void
    {
        $property = Property::factory()->create();
        try {
            DB::transaction(fn () => $property->provider->delete());
            $this->fail('Deleting a referenced provider must be restricted.');
        } catch (QueryException $exception) {
            $this->assertSame('23001', $exception->getCode());
        }
        $this->assertDatabaseHas('properties', ['id' => $property->id, 'provider_id' => $property->provider_id]);
        $this->assertDatabaseHas('users', ['id' => $property->provider_id]);
    }

    public function test_forged_in_memory_owner_does_not_grant_access(): void
    {
        $property = Property::factory()->create();
        $other = User::factory()->propertyProvider()->create();
        $property->provider_id = $other->id;
        $this->assertFalse(Gate::forUser($other)->allows('update', $property));
    }

    public function test_policy_rechecks_stored_ownership_after_a_database_change(): void
    {
        $property = Property::factory()->create();
        $original = $property->provider;
        $other = User::factory()->propertyProvider()->create();
        // Simulate a future trusted transfer without implementing that workflow.
        DB::table('properties')->where('id', $property->id)->update(['provider_id' => $other->id]);
        $this->assertFalse(Gate::forUser($original)->allows('update', $property));
        $this->assertTrue(Gate::forUser($other)->allows('update', $property));
    }

    public function test_role_revocation_removes_access_even_with_a_stale_user_object(): void
    {
        $property = Property::factory()->create();
        $provider = $property->provider;
        DB::table('users')->where('id', $provider->id)->update(['role' => 'business_user']);
        $this->assertFalse(Gate::forUser($provider)->allows('create', Property::class));
        $this->assertFalse(Gate::forUser($provider)->allows('update', $property));
        $this->assertSame([], Property::query()->ownedBy($provider)->pluck('id')->all());
    }

    public function test_direct_ownership_transfer_is_blocked_until_authorized_workflow_exists(): void
    {
        $property = Property::factory()->create();
        $originalId = $property->provider_id;
        $property->provider()->associate(User::factory()->propertyProvider()->create());
        try {
            $property->save();
            $this->fail('A direct reassignment must not silently transfer ownership.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('provider_id', $exception->errors());
        }
        $this->assertDatabaseHas('properties', ['id' => $property->id, 'provider_id' => $originalId]);
    }

    public function test_unsaved_records_and_guest_have_no_management_access(): void
    {
        $user = User::factory()->propertyProvider()->make();
        $property = Property::factory()->make();
        $this->assertFalse(Gate::forUser($user)->allows('create', Property::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $property));
        $this->assertFalse(Gate::forUser(null)->allows('create', Property::class));
    }

    public function test_guest_http_update_returns_401(): void
    {
        $property = Property::factory()->create();
        $this->patchJson('/api/testing/properties/'.$property->id, ['title' => 'Denied'])->assertUnauthorized();
        $this->assertDatabaseHas('properties', ['id' => $property->id, 'title' => $property->title]);
    }

    public function test_cross_provider_http_update_returns_403_without_changing_listing(): void
    {
        $property = Property::factory()->create();
        $this->actingAs(User::factory()->propertyProvider()->create(), 'web')
            ->patchJson('/api/testing/properties/'.$property->id, ['title' => 'Denied'])->assertForbidden();
        $this->assertDatabaseHas('properties', ['id' => $property->id, 'title' => $property->title]);
    }

    public function test_owner_http_update_persists_title_without_accepting_another_provider(): void
    {
        $property = Property::factory()->create();
        $other = User::factory()->propertyProvider()->create();
        $this->actingAs($property->provider, 'web')
            ->patchJson('/api/testing/properties/'.$property->id, ['title' => 'Allowed', 'provider_id' => $other->id])
            ->assertOk()->assertExactJson(['title' => 'Allowed']);
        $this->assertDatabaseHas('properties', [
            'id' => $property->id, 'title' => 'Allowed', 'provider_id' => $property->provider_id,
        ]);
    }
}
