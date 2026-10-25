<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Explicit profile allowlist; never serialize credentials or session metadata. */
class UserResource extends JsonResource
{
    /** @return array{id: int, name: string, email: string, role: string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
        ];
    }
}
