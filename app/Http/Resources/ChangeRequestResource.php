<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'family_id' => $this->family_id,
            'family' => $this->when(
                $this->relationLoaded('family') && $this->family,
                fn () => [
                    'id' => $this->family->id,
                    'head_name' => $this->family->head_name,
                    'national_id' => $this->family->national_id,
                ]
            ),
            'requested_by' => $this->requested_by,
            'reviewed_by' => $this->reviewed_by,
            'status' => $this->status,
            'type' => $this->type,
            'payload' => $this->payload,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

