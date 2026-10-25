<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Existing facts/ownership/visibility are preserved; absent details remain unknown. */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('location', 150)->nullable();
            $table->string('address')->nullable();
            $table->boolean('address_is_public')->default(false);
            $table->string('property_type', 30)->nullable();
            $table->string('transaction_type', 10)->nullable();
            $table->decimal('price_amount', 14, 2)->nullable();
            $table->string('price_period', 10)->nullable();
            $table->char('price_currency', 3)->default('PHP');
            $table->decimal('area_sqm', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('features')->default('[]');
        });
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION property_features_valid_v1(value jsonb) RETURNS boolean
            LANGUAGE plpgsql IMMUTABLE STRICT AS $$
            DECLARE item jsonb; text_value text;
            BEGIN
                IF jsonb_typeof(value) <> 'array' THEN RETURN false; END IF;
                IF jsonb_array_length(value) > 20 THEN RETURN false; END IF;
                FOR item IN SELECT jsonb_array_elements(value) LOOP
                    IF jsonb_typeof(item) <> 'string' THEN RETURN false; END IF;
                    text_value := item #>> '{}';
                    IF char_length(text_value) > 150 OR char_length(btrim(text_value, E' \t\r\n')) = 0 THEN RETURN false; END IF;
                END LOOP;
                RETURN true;
            END $$;
            ALTER TABLE properties
                ADD CONSTRAINT properties_title_nonblank CHECK (char_length(btrim(title, E' \t\r\n')) > 0) NOT VALID,
                ADD CONSTRAINT properties_location_nonblank CHECK (location IS NULL OR char_length(btrim(location, E' \t\r\n')) > 0),
                ADD CONSTRAINT properties_address_nonblank CHECK (address IS NULL OR char_length(btrim(address, E' \t\r\n')) > 0),
                ADD CONSTRAINT properties_address_approval CHECK (NOT address_is_public OR address IS NOT NULL),
                ADD CONSTRAINT properties_type_supported CHECK (property_type IS NULL OR property_type IN ('office', 'retail', 'warehouse', 'commercial-building')),
                ADD CONSTRAINT properties_transaction_supported CHECK (transaction_type IS NULL OR transaction_type IN ('sale', 'lease')),
                ADD CONSTRAINT properties_price_nonnegative CHECK (price_amount IS NULL OR price_amount BETWEEN 0 AND 999999999999.99),
                ADD CONSTRAINT properties_currency_supported CHECK (price_currency = 'PHP'),
                ADD CONSTRAINT properties_price_basis CHECK (
                    (price_amount IS NULL AND price_period IS NULL) OR
                    (price_amount IS NOT NULL AND price_period IS NOT NULL AND transaction_type IS NOT NULL AND
                        ((transaction_type = 'sale' AND price_period = 'total') OR (transaction_type = 'lease' AND price_period = 'month')))
                ),
                ADD CONSTRAINT properties_area_positive CHECK (area_sqm IS NULL OR area_sqm BETWEEN 0.01 AND 9999999999.99),
                ADD CONSTRAINT properties_description_bounded CHECK (description IS NULL OR (char_length(description) <= 10000 AND char_length(btrim(description, E' \t\r\n')) > 0)),
                ADD CONSTRAINT properties_features_bounded CHECK (property_features_valid_v1(features));
            SQL);
        Schema::create('property_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path', 200)->unique();
            $table->smallInteger('position');
            $table->string('caption', 200)->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->unique(['property_id', 'position']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE property_images
                ADD CONSTRAINT property_images_position_bounded CHECK (position BETWEEN 0 AND 9),
                ADD CONSTRAINT property_images_caption_nonblank CHECK (caption IS NULL OR char_length(btrim(caption, E' \t\r\n')) > 0),
                ADD CONSTRAINT property_images_local_path CHECK (
                    storage_path ~ ('^properties/' || property_id::text || '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(jpg|jpeg|png|webp|avif)$')
                );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('property_images');
        DB::unprepared(<<<'SQL'
            ALTER TABLE properties
                DROP CONSTRAINT properties_title_nonblank,
                DROP CONSTRAINT properties_location_nonblank,
                DROP CONSTRAINT properties_address_nonblank,
                DROP CONSTRAINT properties_address_approval,
                DROP CONSTRAINT properties_type_supported,
                DROP CONSTRAINT properties_transaction_supported,
                DROP CONSTRAINT properties_price_nonnegative,
                DROP CONSTRAINT properties_price_basis,
                DROP CONSTRAINT properties_currency_supported,
                DROP CONSTRAINT properties_area_positive,
                DROP CONSTRAINT properties_description_bounded,
                DROP CONSTRAINT properties_features_bounded;
            DROP FUNCTION property_features_valid_v1(jsonb);
            SQL);
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn(['location', 'address', 'address_is_public', 'property_type', 'transaction_type', 'price_amount', 'price_period', 'price_currency', 'area_sqm', 'description', 'features']);
        });
    }
};
