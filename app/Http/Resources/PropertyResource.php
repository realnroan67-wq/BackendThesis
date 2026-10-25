<?php

namespace App\Http\Resources;

use App\Models\PropertyImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** Explicit public facts only; publication never implies administrative or legal review. */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $id = (int) $this->id;
        if ($id < 1 || $id > 9007199254740991) {
            throw new LogicException('A property ID exceeds the public JSON integer range.');
        }

        return [
            'id' => $id,
            'title' => $this->title,
            'location' => $this->location,
            'address' => $this->address_is_public ? $this->address : null,
            'property_type' => $this->property_type,
            'transaction_type' => $this->transaction_type,
            'price_amount' => $this->price_amount,
            'price_currency' => $this->price_currency,
            'price_period' => $this->price_period,
            'area_sqm' => $this->area_sqm,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'images' => $this->publicImages->map(fn (PropertyImage $image): array => [
                'url' => '/storage/'.$image->storage_path,
                'caption' => $image->caption,
            ])->all(),
            'accepts_inquiries' => (bool) $this->accepts_inquiries,
        ];
    }
}
