<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Property;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_filters_apply_to_the_entire_visible_dataset_before_pagination(): void
    {
        Property::factory()->acceptingInquiries()->count(14)->create(['property_type' => 'retail']);
        $matches = Property::factory()->acceptingInquiries()->count(3)->create([
            'title' => 'Target office', 'property_type' => 'office', 'transaction_type' => 'lease',
            'price_amount' => '999999999999.98', 'price_period' => 'month',
        ]);
        Property::factory()->acceptingInquiries()->create(['title' => 'Target office', 'property_type' => 'office', 'transaction_type' => 'sale', 'price_amount' => '1.00', 'price_period' => 'total']);
        Property::factory()->create(['title' => 'Target office', 'property_type' => 'office']);
        $query = 'search=target&property_type=office&transaction_type=lease&max_price=999999999999.98&sort=price-low&per_page=2';
        $first = $this->getJson('/api/v1/properties?'.$query)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->assertSame($matches->take(2)->pluck('id')->all(), array_column($first->json('data'), 'id'));
        $second = $this->getJson($first->json('links.next'))->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
        $this->assertSame($matches->last()->id, $second->json('data.0.id'));
    }

    public function test_prices_use_exact_numeric_comparison_with_unknowns_last_and_stable_ties(): void
    {
        $unknown = Property::factory()->acceptingInquiries()->create(['transaction_type' => 'sale']);
        $low = Property::factory()->acceptingInquiries()->create(['transaction_type' => 'sale', 'price_amount' => '999999999999.98', 'price_period' => 'total']);
        $high = Property::factory()->acceptingInquiries()->create(['transaction_type' => 'sale', 'price_amount' => '999999999999.99', 'price_period' => 'total']);
        $tie = Property::factory()->acceptingInquiries()->create(['transaction_type' => 'sale', 'price_amount' => '999999999999.98', 'price_period' => 'total']);
        $this->getJson('/api/v1/properties?transaction_type=sale&max_price=999999999999.98')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $low->id)->assertJsonPath('data.1.id', $tie->id);
        foreach (['price-low' => [$low->id, $tie->id, $high->id, $unknown->id], 'price-high' => [$high->id, $low->id, $tie->id, $unknown->id]] as $sort => $expected) {
            $response = $this->getJson('/api/v1/properties?transaction_type=sale&sort='.$sort)->assertOk();
            $this->assertSame($expected, array_column($response->json('data'), 'id'));
        }
    }

    public function test_search_uses_literal_public_text_and_cannot_infer_private_addresses(): void
    {
        $private = Property::factory()->acceptingInquiries()->create(['title' => 'Ordinary', 'address' => 'Secretneedle', 'address_is_public' => false]);
        $public = Property::factory()->acceptingInquiries()->create(['address' => 'Secretneedle', 'address_is_public' => true]);
        $description = Property::factory()->acceptingInquiries()->create(['description' => 'SECRETNEEDLE']);
        Property::factory()->create(['title' => 'Secretneedle']);
        $changed = Property::factory()->acceptingInquiries()->create(['location' => 'Secretneedle']);
        $changed->provider->forceFill(['role' => UserRole::BUSINESS_USER])->save();
        $response = $this->getJson('/api/v1/properties?search=secretneedle')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$public->id, $description->id], array_column($response->json('data'), 'id'));
        $this->assertNotContains($private->id, array_column($response->json('data'), 'id'));
        $literal = Property::factory()->acceptingInquiries()->create(['title' => '100%_ literal']);
        $this->getJson('/api/v1/properties?search='.urlencode('%_'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $literal->id);
        $this->getJson('/api/v1/properties?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_name_order_is_case_insensitive_and_ties_use_backend_id(): void
    {
        $z = Property::factory()->acceptingInquiries()->create(['title' => 'Zebra']);
        $a = Property::factory()->acceptingInquiries()->create(['title' => 'alpha']);
        $tie = Property::factory()->acceptingInquiries()->create(['title' => 'Alpha']);
        $response = $this->getJson('/api/v1/properties?sort=name')->assertOk();
        $this->assertSame([$a->id, $tie->id, $z->id], array_column($response->json('data'), 'id'));
    }

    #[DataProvider('invalidSearches')]
    public function test_invalid_search_contract_is_rejected(string $query, string $field): void
    {
        $this->getJson('/api/v1/properties?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidSearches(): array
    {
        return [
            ['search[]=x', 'search'], ['search=', 'search'], ['search='.str_repeat('x', 151), 'search'],
            ['property_type=house', 'property_type'], ['transaction_type=rent', 'transaction_type'],
            ['max_price=12', 'transaction_type'], ['sort=price-low', 'transaction_type'],
            ['sort=price-high', 'transaction_type'], ['sort=unknown', 'sort'],
            ['transaction_type=sale&max_price=-1', 'max_price'],
            ['transaction_type=sale&max_price=1.001', 'max_price'],
            ['transaction_type=sale&max_price=1e5', 'max_price'],
            ['transaction_type=sale&max_price=1000000000000', 'max_price'],
            ['transaction_type=sale&max_price[]=1', 'max_price'],
        ];
    }
}
