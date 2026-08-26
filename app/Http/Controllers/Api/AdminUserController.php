<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $q = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('id');

        if (auth()->user()->isSuper() && auth()->user()->camp_id === null && $request->filled('camp_id')) {
            $q = User::query()
                ->withoutGlobalScopes()
                ->where('role', User::ROLE_ADMIN)
                ->where('camp_id', (int) $request->camp_id)
                ->orderBy('id');
        }

        $users = $q->with('camp:id,primary_admin_user_id')->get();

        return UserResource::collection($users)->response();
    }

    public function store(Request $request): JsonResponse
    {
        if (! auth()->user()->canAddCampAdmins()) {
            return response()->json(['message' => 'غير مصرح لك بإضافة مسؤولين. المسؤول الرئيسي للمخيم فقط يمكنه ذلك.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'is_super' => ['boolean'],
            'camp_id' => ['nullable', 'integer', 'exists:camps,id'],
        ]);

        $creator = $request->user();
        $campId = null;
        if ($creator->isSuper() && $creator->camp_id === null) {
            $campId = isset($validated['camp_id']) ? (int) $validated['camp_id'] : null;
        } elseif ($creator->camp_id !== null) {
            $campId = (int) $creator->camp_id;
        }

        $user = User::query()->create([
            'national_id' => 'ADMIN_'.time().'_'.rand(100, 999),
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?? null,
            'password' => $validated['password'],
            'role' => User::ROLE_ADMIN,
            'is_super' => $validated['is_super'] ?? false,
            'camp_id' => $campId,
        ]);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(User $user): JsonResponse
    {
        if (! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isPrimaryCampAdmin()) {
            return response()->json(['message' => 'لا يمكن حذف المسؤول الرئيسي للمخيم.'], 422);
        }

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك الخاص.'], 422);
        }

        if ($user->isSuper() && User::withoutGlobalScopes()->where('role', User::ROLE_ADMIN)->where('is_super', true)->count() <= 1) {
            return response()->json(['message' => 'لا يمكن حذف آخر مسؤول فائق.'], 422);
        }

        if (! auth()->user()->canDeleteCampAdmin($user)) {
            return response()->json(['message' => 'غير مصرح لك بحذف هذا المسؤول.'], 403);
        }

        $user->delete();

        return response()->json(null, 204);
    }
}
