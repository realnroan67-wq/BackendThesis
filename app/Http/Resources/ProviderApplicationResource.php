<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Participant/reviewer allowlist; never serialize role, decision actor, audit or notification metadata. */
class ProviderApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        foreach ([$this->id, $request->routeIs('api.v1.admin.provider-applications.show') ? $this->applicant->id : $this->id] as $id) {
            if ((int) $id < 1 || (int) $id > 9007199254740991) {
                throw new \LogicException('An application identifier exceeds the public JSON integer range.');
            }
        }
        $result = [
            'id' => $this->id, 'business_name' => $this->business_name, 'business_location' => $this->business_location,
            'application_message' => $this->application_message, 'status' => $this->status,
            'submitted_at' => $this->submitted_at->utc()->toRfc3339String(),
            'decided_at' => $this->decided_at?->utc()->toRfc3339String(), 'public_reason' => $this->public_reason,
        ];
        if ($request->routeIs('api.v1.admin.provider-applications.show')) {
            $result['applicant'] = ['id' => $this->applicant->id, 'name' => $this->applicant->name, 'email' => $this->applicant->email];
        }

        return $result;
    }
}
