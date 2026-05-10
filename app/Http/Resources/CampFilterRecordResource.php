<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampFilterRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'criteria' => $this->criteria,
            'snapshot' => $this->snapshot,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
