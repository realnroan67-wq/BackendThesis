<?php

namespace App\Models;

use Database\Factories\PropertyImageFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Trusted metadata only, not proof of upload or approval. No image-management API exists. */
#[Guarded(['*'])]
class PropertyImage extends Model
{
    /** @use HasFactory<PropertyImageFactory> */
    use HasFactory;

    protected $attributes = ['is_public' => false];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (PropertyImage $image): void {
            if (is_string($image->caption) && trim($image->caption, " \t\r\n") === '') {
                throw ValidationException::withMessages(['caption' => 'Use a nonblank caption or null.']);
            }
            $id = preg_quote((string) $image->property_id, '/');
            Validator::make($image->getAttributes(), [
                'property_id' => ['required', 'integer', 'min:1'],
                'storage_path' => ['required', 'string', 'max:200', 'regex:/\Aproperties\/'.$id.'\/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(jpg|jpeg|png|webp|avif)\z/'],
                'position' => ['required', 'integer', 'min:0', 'max:9'],
                'caption' => ['nullable', 'string', 'max:200', 'not_regex:/\A[ \t\r\n]*\z/u'],
                'is_public' => ['required', 'boolean'],
            ])->validate();
        });
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
