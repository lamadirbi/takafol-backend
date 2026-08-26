<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'national_id' => $this->national_id,
            'username' => $this->username,
            'login_serial' => User::defaultSerialFromId($this->id),
            'name' => $this->name,
            'email' => $this->when($this->role === User::ROLE_ADMIN, fn () => $this->email),
            'role' => $this->role,
            'camp_id' => $this->when($this->role === User::ROLE_ADMIN, fn () => $this->camp_id),
            'is_super' => (bool) $this->is_super,
            'is_primary_camp_admin' => $this->when(
                $this->role === User::ROLE_ADMIN,
                fn () => $this->resource->isPrimaryCampAdmin()
            ),
            'can_add_camp_admins' => $this->when(
                $this->role === User::ROLE_ADMIN,
                fn () => $this->resource->canAddCampAdmins()
            ),
            'created_at' => $this->created_at,
        ];
    }
}
