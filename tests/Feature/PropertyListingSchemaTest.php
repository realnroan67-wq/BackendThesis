<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyImage;
use App\Support\PropertyListingValidation;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropertyListingSchemaTest extends TestCase
{
    use DatabaseTransactions;

    /** Explicit fixture facts only; this is never a production backfill. */
    private function listing(): array
    {
        return ['title' => 'Schema fixture office', 'location' => 'Butuan City',
            'address' => 'Approved fixture address', 'address_is_public' => true,
            'property_type' => 'office', 'transaction_type' => 'lease',
            'price_amount' => '120000.10', 'price_period' => 'month', 'price_currency' => 'PHP',
            'area_sqm' => '420.25', 'description' => 'Office space description for schema testing.',
            'features' => ['Flexible interior layout', 'Parking availability']];
    }

    public function test_additive_migration_preserves_legacy_records_and_ownership_without_invented_facts(): void
    {
        $migration = require database_path('migrations/2026_10_18_190229_add_public_listing_fields_to_properties_table.php');
        $migration->down();
        $property = Property::factory()->acceptingInquiries()->create();
        $before = $property->fresh()->getRawOriginal();
        $migration->up();
        $after = $property->fresh();
        foreach ($before as $field => $value) {
            $this->assertSame($value, $after->getRawOriginal($field));
        }
        foreach (['location', 'address', 'property_type', 'transaction_type', 'price_amount', 'price_period', 'area_sqm', 'description'] as $field) {
            $this->assertNull($after->{$field});
        }
        $this->assertSame([], $after->features);
        $this->assertSame('PHP', $after->price_currency);
        $this->assertFalse($after->address_is_public);
        $this->assertCount(0, $after->images);
        $migration->down();
        $this->assertSame($before, $property->fresh()->getRawOriginal());
        $this->assertFalse(Schema::hasColumn('properties', 'price_amount'));
        $migration->up();
    }

    public function test_legacy_blank_titles_are_not_rewritten_and_new_blank_titles_are_rejected(): void
    {
        $migration = require database_path('migrations/2026_10_18_190229_add_public_listing_fields_to_properties_table.php');
        $migration->down();
        $property = Property::factory()->create();
        DB::table('properties')->where('id', $property->id)->update(['title' => '']);
        $migration->up();
        $this->assertSame('', $property->fresh()->title);
        try {
            DB::transaction(fn () => DB::table('properties')->where('id', $property->id)->update(['title' => " \t "]));
            $this->fail('Blank titles must be rejected on new writes.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_exact_decimals_and_public_facts_are_persisted_and_serialized_without_private_fields(): void
    {
        $property = Property::factory()->acceptingInquiries()->create($this->listing());
        PropertyImage::factory()->for($property)->publiclyApproved()->create(['position' => 4, 'caption' => 'Office interior']);
        $first = PropertyImage::factory()->for($property)->publiclyApproved()->create(['position' => 1, 'caption' => 'Entrance']);
        PropertyImage::factory()->for($property)->create(['position' => 0, 'caption' => 'PRIVATE UNAPPROVED IMAGE']);
        $data = $this->getJson('/api/v1/properties/'.$property->id)->assertOk()->json('data');
        foreach (['location', 'address', 'property_type', 'transaction_type', 'description', 'features'] as $field) {
            $this->assertSame($this->listing()[$field], $data[$field]);
        }
        $this->assertSame('120000.10', $data['price_amount']);
        $this->assertSame('420.25', $data['area_sqm']);
        $this->assertSame('month', $data['price_period']);
        $this->assertSame('PHP', $data['price_currency']);
        $this->assertSame('/storage/'.$first->storage_path, $data['images'][0]['url']);
        $this->assertSame(['Entrance', 'Office interior'], array_column($data['images'], 'caption'));
        $this->assertSame(['url', 'caption'], array_keys($data['images'][0]));
        foreach (['provider_id', 'address_is_public', 'is_published', 'created_at', 'updated_at'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $this->assertSame($data, $this->getJson('/api/v1/properties')->assertOk()->json('data.0'));
    }

    public function test_unapproved_address_and_images_stay_private_and_unpublished_listings_stay_hidden(): void
    {
        $property = Property::factory()->acceptingInquiries()->create([...$this->listing(), 'address_is_public' => false]);
        PropertyImage::factory()->for($property)->create();
        $this->getJson('/api/v1/properties/'.$property->id)->assertOk()->assertJsonPath('data.address', null)->assertJsonPath('data.images', []);
        $property->forceFill(['is_published' => false])->save();
        $this->getJson('/api/v1/properties/'.$property->id)->assertNotFound();
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_descriptive_mass_assignment_cannot_publish_approve_or_change_ownership(): void
    {
        $property = Property::factory()->create();
        $owner = $property->provider_id;
        $property->fill([...$this->listing(), 'provider_id' => 999, 'is_published' => true, 'accepts_inquiries' => true])->save();
        $property->refresh();
        $this->assertSame($owner, $property->provider_id);
        $this->assertFalse($property->is_published);
        $this->assertFalse($property->accepts_inquiries);
        $this->assertFalse($property->address_is_public);
        $this->assertNull($property->address);
        $this->assertSame('120000.10', $property->price_amount);
        $image = PropertyImage::factory()->for($property)->create();
        try {
            $image->fill(['is_public' => true, 'property_id' => 999, 'caption' => 'Not authorized'])->save();
            $this->fail('Image approval must not be mass assigned.');
        } catch (MassAssignmentException) {
            $this->assertTrue(true);
        }
        $this->assertFalse($image->fresh()->is_public);
        $this->assertSame($property->id, $image->fresh()->property_id);
        $this->assertNull($image->fresh()->caption);
    }

    public function test_an_unsupported_currency_is_rejected_instead_of_silently_becoming_a_peso_price(): void
    {
        $property = Property::factory()->create($this->listing());
        $this->expectException(ValidationException::class);
        $property->fill(['price_currency' => 'USD'])->save();
    }

    #[DataProvider('invalidListingValues')]
    public function test_model_validation_rejects_invalid_listing_values(array $patch): void
    {
        $property = Property::factory()->create($this->listing());
        $this->expectException(ValidationException::class);
        $property->forceFill($patch)->save();
    }

    public static function invalidListingValues(): array
    {
        return [
            [['title' => '  ']], [['title' => str_repeat('a', 256)]],
            [['location' => ' ']], [['location' => str_repeat('a', 151)]],
            [['address' => str_repeat('a', 256)]], [['address' => null, 'address_is_public' => true]],
            [['property_type' => 'mansion']], [['transaction_type' => 'rent']],
            [['price_amount' => '-0.01']], [['price_amount' => '1000000000000.00']],
            [['price_amount' => 'NaN']], [['price_amount' => 'Infinity']], [['price_amount' => '1e3']],
            [['price_amount' => '0.001']], [['price_amount' => 1.1]],
            [['price_period' => 'year']], [['transaction_type' => 'sale', 'price_period' => 'month']],
            [['price_period' => null]], [['price_amount' => null]], [['transaction_type' => null]],
            [['price_currency' => 'USD']], [['area_sqm' => '0']], [['area_sqm' => '-1']],
            [['area_sqm' => '0.001']], [['area_sqm' => '10000000000']],
            [['description' => ' ']], [['description' => str_repeat('a', 10001)]],
            [['features' => array_fill(0, 21, 'Feature')]], [['features' => ['a' => 'Feature']]],
            [['features' => null]], [['features' => ' ']], [['features' => [null]]], [['features' => [123]]], [['features' => [' ']]],
            [['features' => [str_repeat('a', 151)]]],
        ];
    }

    #[DataProvider('invalidDatabaseValues')]
    public function test_database_constraints_reject_invalid_raw_writes(array $patch): void
    {
        $property = Property::factory()->create($this->listing());
        try {
            DB::transaction(fn () => DB::table('properties')->where('id', $property->id)->update($patch));
            $this->fail('A raw invalid listing write passed PostgreSQL constraints.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], ['23514', '23502', '22001', '22003', '22P02']);
        }
        $this->assertSame('120000.10', $property->fresh()->price_amount);
    }

    public static function invalidDatabaseValues(): array
    {
        return [
            [['title' => ' ']], [['title' => str_repeat('a', 256)]],
            [['location' => ' ']], [['location' => str_repeat('a', 151)]],
            [['address' => ' ']], [['address' => null, 'address_is_public' => true]],
            [['property_type' => 'mansion']], [['transaction_type' => 'rent']],
            [['price_amount' => '-0.01']], [['price_amount' => 'NaN']], [['price_amount' => 'Infinity']],
            [['price_amount' => '1000000000000']], [['price_currency' => 'USD']],
            [['price_period' => 'year']], [['price_period' => null]], [['price_amount' => null]],
            [['transaction_type' => null]], [['transaction_type' => 'sale', 'price_period' => 'month']],
            [['area_sqm' => '0']], [['area_sqm' => '-1']], [['area_sqm' => 'NaN']],
            [['area_sqm' => '10000000000']], [['description' => str_repeat('a', 10001)]],
            [['description' => ' ']], [['features' => null]], [['features' => '{}']], [['features' => 'null']],
            [['features' => '[1]']], [['features' => '[null]']], [['features' => '[" "]']],
            [['features' => json_encode([str_repeat('a', 151)])]],
            [['features' => json_encode(array_fill(0, 21, 'Feature'))]],
        ];
    }

    public function test_valid_boundary_values_and_price_periods_round_trip_as_exact_strings(): void
    {
        $property = Property::factory()->create([...$this->listing(), 'transaction_type' => 'sale', 'price_period' => 'total',
            'price_amount' => '999999999999.99', 'area_sqm' => '9999999999.99',
            'title' => str_repeat('a', 255), 'location' => str_repeat('a', 150),
            'description' => str_repeat('a', 10000), 'features' => array_fill(0, 20, str_repeat('😀', 150))]);
        $this->assertSame('999999999999.99', $property->fresh()->price_amount);
        $this->assertSame('9999999999.99', $property->fresh()->area_sqm);
        $property->forceFill(['transaction_type' => 'lease', 'price_period' => 'month', 'price_amount' => '0', 'area_sqm' => '0.01'])->save();
        $this->assertSame('0.00', $property->fresh()->price_amount);
        $this->assertSame('0.01', $property->fresh()->area_sqm);
        app(PropertyListingValidation::class)->validate(['title' => 'Unknown facts allowed', 'price_amount' => null, 'price_period' => null]);
        $this->assertTrue(true);
    }

    #[DataProvider('invalidImageValues')]
    public function test_image_paths_and_metadata_are_validated_in_model_and_database(array $patch): void
    {
        $image = PropertyImage::factory()->create();
        try {
            $image->forceFill($patch)->save();
            $this->fail('Invalid image metadata passed model validation.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        try {
            DB::transaction(fn () => DB::table('property_images')->where('id', $image->id)->update($patch));
            $this->fail('Invalid image metadata passed database constraints.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], ['23514', '22001']);
        }
    }

    public static function invalidImageValues(): array
    {
        return array_map(fn (array $patch): array => [$patch], [
            ['position' => -1], ['position' => 10], ['caption' => ' '], ['caption' => str_repeat('a', 201)],
            ['storage_path' => 'https://example.test/image.jpg'], ['storage_path' => '//evil.test/image.jpg'],
            ['storage_path' => 'properties/1/../../secret.jpg'], ['storage_path' => 'properties/1/%2e%2e/file.jpg'],
            ['storage_path' => 'properties/1/file.svg'], ['storage_path' => 'properties/1/file.webp?token=private'],
            ['storage_path' => 'properties/999999/9c873ac6-d464-4aad-9420-147c041b6b55.webp'],
        ]);
    }

    public function test_database_bounds_image_count_and_enforces_unique_positions_and_storage_paths(): void
    {
        $property = Property::factory()->create();
        for ($position = 0; $position < 10; $position++) {
            PropertyImage::factory()->for($property)->create(['position' => $position]);
        }
        $this->assertCount(10, $property->images);
        $image = $property->images->first();
        try {
            DB::transaction(fn () => PropertyImage::factory()->for($property)->create(['position' => 0]));
            $this->fail('Duplicate image positions must not be saved.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('property_images')->insert([
                'property_id' => $property->id, 'storage_path' => $image->storage_path, 'position' => 10,
            ]));
            $this->fail('An eleventh image must not be saved.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], ['23505', '23514']);
        }
    }

    public function test_public_image_eager_loading_does_not_scale_queries_per_listing(): void
    {
        Property::factory()->acceptingInquiries()->count(4)->create()->each(
            fn (Property $property) => PropertyImage::factory()->for($property)->publiclyApproved()->create()
        );
        $imageQueries = 0;
        DB::listen(function ($query) use (&$imageQueries): void {
            if (str_contains($query->sql, 'from "property_images"')) {
                $imageQueries++;
            }
        });
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame(1, $imageQueries);
    }
}
