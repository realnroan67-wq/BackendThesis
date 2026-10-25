<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PropertyDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_browse_and_detail_use_only_the_public_allowlist(): void
    {
        $property = Property::factory()->acceptingInquiries()->create(['title' => 'Published business space']);
        $property->setAttribute('internal_review_notes', 'PRIVATE REVIEW NOTES');
        $property->setRelation('provider', $property->provider);
        $this->assertSame(['id', 'title', 'location', 'address', 'property_type', 'transaction_type', 'price_amount', 'price_currency', 'price_period', 'area_sqm', 'description', 'features', 'images', 'accepts_inquiries'], array_keys((new PropertyResource($property))->resolve()));
        $expected = ['id' => $property->id, 'title' => $property->title, 'location' => null, 'address' => null, 'property_type' => null, 'transaction_type' => null, 'price_amount' => null, 'price_currency' => 'PHP', 'price_period' => null, 'area_sqm' => null, 'description' => null, 'features' => [], 'images' => [], 'accepts_inquiries' => true];
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonPath('data', [$expected])
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 12)->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/properties/'.$property->id)->assertOk()->assertExactJson(['data' => $expected])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_publication_and_inquiry_availability_are_independent_of_review_claims(): void
    {
        $open = Property::factory()->acceptingInquiries()->create();
        $closed = Property::factory()->create(['is_published' => true, 'accepts_inquiries' => false]);
        Property::factory()->create(['is_published' => false, 'accepts_inquiries' => true]);
        Property::factory()->create();
        $response = $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$open->id, $closed->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/properties/'.$closed->id)->assertOk()->assertJsonPath('data.accepts_inquiries', false);
        $this->assertStringNotContainsString('review', $response->getContent());
        $this->assertStringNotContainsString('verified', $response->getContent());
    }

    public function test_unpublished_and_unknown_properties_have_the_same_404_for_guests_and_owners(): void
    {
        $hidden = Property::factory()->create(['title' => 'SECRET UNPUBLISHED TITLE']);
        $expected = ['message' => 'Property not found.', 'code' => 'PROPERTY_NOT_FOUND'];
        $this->getJson('/api/v1/properties/'.$hidden->id)->assertNotFound()->assertExactJson($expected);
        $this->getJson('/api/v1/properties/9007199254740991')->assertNotFound()->assertExactJson($expected);
        $this->actingAs($hidden->provider, 'web')->getJson('/api/v1/properties/'.$hidden->id)
            ->assertNotFound()->assertExactJson($expected);
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    #[DataProvider('roles')]
    public function test_public_reads_do_not_depend_on_the_callers_role_or_grant_management_rights(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $property = Property::factory()->acceptingInquiries()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/properties')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/properties/'.$property->id)->assertOk();
        $this->assertFalse(Gate::forUser($user)->allows('update', $property));
    }

    public static function roles(): array
    {
        return [[UserRole::ADMINISTRATOR], [UserRole::PROPERTY_PROVIDER], [UserRole::BUSINESS_USER]];
    }

    #[DataProvider('invalidOwnerRoles')]
    public function test_an_owner_role_change_immediately_removes_public_visibility(UserRole $role): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->getJson('/api/v1/properties/'.$property->id)->assertOk();
        User::query()->whereKey($property->provider_id)->update(['role' => $role]);
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/properties/'.$property->id)->assertNotFound()
            ->assertExactJson(['message' => 'Property not found.', 'code' => 'PROPERTY_NOT_FOUND']);
    }

    public static function invalidOwnerRoles(): array
    {
        return [[UserRole::ADMINISTRATOR], [UserRole::BUSINESS_USER]];
    }

    public function test_unpublishing_hides_the_listing_on_the_next_request(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $this->getJson('/api/v1/properties/'.$property->id)->assertOk();
        $property->forceFill(['is_published' => false])->save();
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/properties/'.$property->id)->assertNotFound();
    }

    public function test_pagination_is_bounded_stable_and_counts_only_visible_records(): void
    {
        $properties = Property::factory()->acceptingInquiries()->count(5)->create();
        Property::factory()->count(3)->create();
        $first = $this->getJson('/api/v1/properties?per_page=2')->assertOk()->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)->assertJsonCount(2, 'data');
        $second = $this->getJson('/api/v1/properties?page=2&per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($properties->take(2)->pluck('id')->all(), array_column($first->json('data'), 'id'));
        $this->assertSame($properties->slice(2, 2)->pluck('id')->all(), array_column($second->json('data'), 'id'));
        $this->assertStringContainsString('per_page=2', $first->json('links.next'));
        $this->getJson('/api/v1/properties?page=4&per_page=2')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 5);
        $this->getJson('/api/v1/properties?per_page=50&page=10000')->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_or_unsupported_query_parameters_are_rejected(string $query, string $field): void
    {
        $this->getJson('/api/v1/properties?'.$query)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors($field)->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function invalidQueries(): array
    {
        return [
            ['page=0', 'page'], ['page=-1', 'page'], ['page=1.5', 'page'], ['page=10001', 'page'],
            ['page[]=1', 'page'], ['page=', 'page'], ['page=999999999999999999999999', 'page'],
            ['per_page=0', 'per_page'], ['per_page=51', 'per_page'], ['per_page=all', 'per_page'],
            ['per_page[]=12', 'per_page'], ['per_page=', 'per_page'],
            ['provider_id=1', 'query'], ['include=provider', 'query'], ['is_published=false', 'query'],
            ['q=office', 'query'], ['sort=provider_id', 'sort'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_identifiers_do_not_reach_postgresql_or_leak_binding_details(string $id): void
    {
        $this->getJson('/api/v1/properties/'.$id)->assertNotFound()
            ->assertExactJson(['message' => 'Property not found.', 'code' => 'PROPERTY_NOT_FOUND']);
    }

    public static function invalidIds(): array
    {
        return array_map(fn (string $id): array => [$id], ['0', '-1', '01', '1.0', '1e2', 'abc', '9007199254740992', str_repeat('9', 100)]);
    }

    public function test_public_identifiers_stay_safe_for_javascript_clients(): void
    {
        $safe = Property::factory()->acceptingInquiries()->create(['id' => 9007199254740991]);
        $unsafe = Property::factory()->acceptingInquiries()->create(['id' => 9007199254740992]);
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $safe->id);
        $this->getJson('/api/v1/properties/'.$safe->id)->assertOk()->assertJsonPath('data.id', $safe->id);
        $this->getJson('/api/v1/properties/'.$unsafe->id)->assertNotFound();
    }

    public function test_get_json_bodies_cannot_override_query_pagination(): void
    {
        Property::factory()->acceptingInquiries()->count(3)->create();
        $this->json('GET', '/api/v1/properties?per_page=2', ['per_page' => 50, 'page' => 2, 'include' => 'provider'])
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 2);
    }

    public function test_reads_do_not_change_properties_owners_or_inquiry_records(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        $before = $property->fresh()->getRawOriginal();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/\A\s*(?:insert|update|delete|alter|create|drop)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $this->getJson('/api/v1/properties')->assertOk();
        $this->getJson('/api/v1/properties/'.$property->id)->assertOk();
        $this->assertSame([], $writes);
        $this->assertSame($before, $property->fresh()->getRawOriginal());
    }

    public function test_unexpected_errors_hide_database_and_provider_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Property::factory()->acceptingInquiries()->create();
        Property::retrieved(function (): void {
            throw new RuntimeException('PRIVATE DATABASE PASSWORD AND REVIEW NOTES');
        });
        $this->getJson('/api/v1/properties')->assertStatus(500)->assertExactJson([
            'message' => 'Unable to load properties.', 'code' => 'SERVER_ERROR',
        ])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_property_routes_expose_only_get_and_head_and_registration_remains_gated(): void
    {
        $property = Property::factory()->acceptingInquiries()->create();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/v1/properties/'.$property->id, ['title' => 'Forged title', 'is_published' => false])
                ->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
        }
        $this->postJson('/api/v1/properties', [])->assertStatus(405);
        $this->assertSame($property->title, $property->fresh()->title);
        $this->postJson('/register', [])->assertStatus(503)->assertJsonPath('code', 'REGISTRATION_DISABLED');
    }
}
