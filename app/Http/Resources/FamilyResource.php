<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'head_name' => $this->head_name,
            'head_gender' => $this->head_gender,
            'national_id' => $this->national_id,
            'phone' => $this->phone,
            'social_status' => $this->social_status,
            'financial_status' => $this->financial_status,
            'spouse_name' => $this->spouse_name,
            'spouse_national_id' => $this->spouse_national_id,
            'total_members' => $this->total_members,
            'file_status' => $this->file_status,
            'original_governorate' => $this->original_governorate,
            'original_neighborhood' => $this->original_neighborhood,
            'extra_data' => $this->extra_data ?: (object) [],
            'profile_complete' => $this->profileComplete(),
            'login_serial' => $this->when(
                $this->relationLoaded('user') && $this->user,
                fn () => User::defaultSerialFromId((int) $this->user->id)
            ),
            'members' => FamilyMemberResource::collection($this->whenLoaded('members')),
            'distributions' => DistributionResource::collection($this->whenLoaded('distributions')),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
