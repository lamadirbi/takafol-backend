<?php

use App\Models\Camp;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Camp::query()->whereNull('primary_admin_user_id')->get() as $camp) {
            $first = User::withoutGlobalScopes()
                ->where('camp_id', $camp->id)
                ->where('role', User::ROLE_ADMIN)
                ->orderBy('id')
                ->first();
            if ($first) {
                $camp->update(['primary_admin_user_id' => $first->id]);
            }
        }
    }

    public function down(): void
    {
        // لا نعيد المسح — العمود يبقى
    }
};
