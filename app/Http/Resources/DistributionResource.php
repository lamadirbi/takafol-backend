<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'family_id' => $this->family_id,
            'package_type_id' => $this->package_type_id,
            'package_label' => $this->package_label,
            'camp_filter_record_id' => $this->camp_filter_record_id,
            'camp_filter_record' => $this->when(
                $this->relationLoaded('campFilterRecord') && $this->campFilterRecord,
                fn () => [
                    'id' => $this->campFilterRecord->id,
                    'name' => $this->campFilterRecord->name,
                    'created_at' => $this->campFilterRecord->created_at,
                ]
            ),
            'status' => $this->status,
            'delivered_at' => $this->delivered_at,
            'administered_by' => $this->administered_by,
            'package_type' => new PackageTypeResource($this->whenLoaded('packageType')),
            'family' => new FamilyResource($this->whenLoaded('family')),
            'administered_by_user' => new UserResource($this->whenLoaded('administeredBy')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
