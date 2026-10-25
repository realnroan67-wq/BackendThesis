<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserRoleAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only route exercises the reusable middleware without publishing an API.
        Route::get('/api/testing/administrator', fn () => response()->json(['allowed' => true]))
            ->middleware(['auth:sanctum', 'can:access-administration']);
    }

    public function test_new_users_default_to_business_user_without_accepting_input_roles(): void
    {
        $attributes = User::factory()->raw();
        $attributes['role'] = 'administrator';
        $user = User::create($attributes);
        $this->assertSame(UserRole::BUSINESS_USER, $user->fresh()->role);
        $user->fill(['role' => 'property_provider', 'name' => 'Updated contact'])->save();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'business_user', 'name' => 'Updated contact']);
    }

    public function test_database_defaults_role_for_inserts_without_a_role(): void
    {
        $attributes = User::factory()->raw();
        unset($attributes['role']);
        $id = DB::table('users')->insertGetId($attributes);
        $this->assertSame(UserRole::BUSINESS_USER, User::findOrFail($id)->role);
    }

    #[DataProvider('invalidRoles')]
    public function test_database_rejects_invalid_roles_even_when_bypassing_mass_assignment(?string $role): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['role' => $role]);
    }

    public static function invalidRoles(): array
    {
        return [['super_admin'], [null]];
    }

    #[DataProvider('roles')]
    public function test_roles_are_persisted_and_only_allow_the_corresponding_area(UserRole $role, string $allowedAbility): void
    {
        $user = match ($role) {
            UserRole::ADMINISTRATOR => User::factory()->administrator()->create(),
            UserRole::PROPERTY_PROVIDER => User::factory()->propertyProvider()->create(),
            UserRole::BUSINESS_USER => User::factory()->businessUser()->create(),
        };
        $this->assertSame($role, $user->fresh()->role);
        foreach (['access-administration', 'access-provider-area', 'access-business-area'] as $ability) {
            $this->assertSame($ability === $allowedAbility, Gate::forUser($user)->allows($ability));
        }
        $other = User::factory()->create();
        $this->assertTrue(Gate::forUser($user)->allows('view', $user));
        $this->assertFalse(Gate::forUser($user)->allows('view', $other));
        $this->assertFalse(Gate::forUser($user)->allows('updateRole', $other));
        $this->assertFalse(Gate::forUser($user)->allows('unconfigured-inquiry-access'));
    }

    public static function roles(): array
    {
        return [
            'administrator' => [UserRole::ADMINISTRATOR, 'access-administration'],
            'provider' => [UserRole::PROPERTY_PROVIDER, 'access-provider-area'],
            'business user' => [UserRole::BUSINESS_USER, 'access-business-area'],
        ];
    }

    public function test_guest_protected_api_access_returns_401(): void
    {
        $this->getJson('/api/testing/administrator')->assertUnauthorized();
    }

    public function test_wrong_role_returns_403_through_authorization_middleware(): void
    {
        $this->actingAs(User::factory()->businessUser()->create(), 'web')
            ->getJson('/api/testing/administrator')->assertForbidden();
    }

    public function test_permitted_role_passes_authorization_middleware(): void
    {
        $this->actingAs(User::factory()->administrator()->create(), 'web')
            ->getJson('/api/testing/administrator')->assertOk()->assertExactJson(['allowed' => true]);
    }

    public function test_public_registration_is_gated_and_cannot_assign_administrator_privileges(): void
    {
        $before = User::count();
        $this->postJson('/register', ['role' => 'administrator'])->assertStatus(503)->assertJsonPath('code', 'REGISTRATION_DISABLED');
        $this->assertSame($before, User::count());
        $this->postJson('/api/v1/register', ['role' => 'administrator'])->assertNotFound();
    }
}
