<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class InquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = [];
        foreach (['id', 'property_id', 'business_user_id', 'provider_id'] as $field) {
            $id = (int) $this->{$field};
            if ($id < 1 || $id > 9007199254740991) {
                throw new LogicException('An inquiry ID exceeds the public JSON integer range.');
            }
            $result[$field] = $id;
        }
        foreach (['business_name', 'contact_name', 'contact_email', 'contact_phone', 'message', 'status'] as $field) {
            $result[$field] = $this->{$field};
        }
        $result['created_at'] = $this->created_at->copy()->utc()->toRfc3339String();
        $result['updated_at'] = $this->updated_at->copy()->utc()->toRfc3339String();

        return $result;
    }
}
