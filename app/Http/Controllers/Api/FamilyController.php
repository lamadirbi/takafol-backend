<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFamilyRequest;
use App\Http\Resources\FamilyResource;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FamilyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Family::queryForAdminFilters($request);

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search', ''));
            if ($s !== '') {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($like) {
                    $q->where('national_id', 'like', $like)
                        ->orWhere('head_name', 'like', $like);
                });
            }
        }

        $perPage = min(max(1, (int) $request->input('per_page', 15)), 200);
        $families = $query->orderBy('id')->paginate($perPage);

        return FamilyResource::collection($families)->response();
    }

    public function store(StoreFamilyRequest $request): JsonResponse
    {
        $data = $request->validated();

        $family = DB::transaction(function () use ($data) {
            $nationalId = (string) $data['national_id'];

            $existingUser = User::withoutGlobalScopes()
                ->where('national_id', $nationalId)
                ->first();

            if ($existingUser) {
                $hasFamily = Family::withoutGlobalScopes()
                    ->where('user_id', $existingUser->id)
                    ->exists();
                if ($hasFamily) {
                    throw ValidationException::withMessages([
                        'national_id' => ['رقم الهوية مسجّل مسبقاً لرب أسرة.'],
                    ]);
                }
                $user = $existingUser;
                $user->name = $data['head_name'];
                $user->role = User::ROLE_FAMILY_HEAD;
                if (App::has('current_camp_id')) {
                    $user->camp_id = App::get('current_camp_id');
                }
                $user->password = User::defaultSerialFromId((int) $user->id);
                $user->save();
            } else {
                $user = User::query()->create([
                    'national_id' => $nationalId,
                    'name' => $data['head_name'],
                    'password' => Str::random(40),
                    'role' => User::ROLE_FAMILY_HEAD,
                ]);

                $user->password = User::defaultSerialFromId((int) $user->id);
                $user->save();
            }

            $familyNationalId = $data['family_national_id'] ?? $data['national_id'];

            $family = Family::query()->create([
                'user_id' => $user->id,
                'head_name' => $data['head_name'],
                'head_gender' => $data['head_gender'] ?? null,
                'national_id' => $familyNationalId,
                'phone' => $data['phone'] ?? null,
                'social_status' => $data['social_status'] ?? null,
                'financial_status' => $data['financial_status'] ?? null,
                'spouse_name' => $data['spouse_name'] ?? null,
                'spouse_national_id' => $data['spouse_national_id'] ?? null,
                'original_governorate' => $data['original_governorate'] ?? null,
                'original_neighborhood' => $data['original_neighborhood'] ?? null,
                'total_members' => $data['total_members'],
            ]);

            if (! empty($data['members'])) {
                foreach ($data['members'] as $member) {
                    $family->members()->create([
                        'name' => $member['name'],
                        'date_of_birth' => $member['date_of_birth'] ?? null,
                        'age' => $member['age'] ?? null,
                        'relationship' => $member['relationship'] ?? null,
                        'gender' => $member['gender'] ?? FamilyMember::GENDER_UNKNOWN,
                    ]);
                }
            }

            $family->update(['total_members' => $family->members()->count()]);

            return $family->load(['user', 'members']);
        });

        return (new FamilyResource($family))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Family $family): FamilyResource
    {
        $family->load(['user', 'members', 'distributions.packageType', 'distributions.campFilterRecord']);

        return new FamilyResource($family);
    }

    public function update(Request $request, Family $family): FamilyResource
    {
        $validated = $request->validate([
            'head_name' => ['sometimes', 'string', 'max:255'],
            'head_gender' => ['nullable', 'string', 'max:16'],
            'national_id' => ['sometimes', 'string', 'max:32', Rule::unique('families', 'national_id')->ignore($family->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'social_status' => ['nullable', 'string', 'max:64'],
            'financial_status' => ['nullable', 'string', Rule::in(['low', 'medium', 'good'])],
            'spouse_name' => ['nullable', 'string', 'max:255'],
            'spouse_national_id' => ['nullable', 'string', 'max:32'],
            'original_governorate' => ['nullable', 'string', 'max:64'],
            'original_neighborhood' => ['nullable', 'string', 'max:64'],
            'total_members' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        if (isset($validated['head_name'])) {
            $family->user?->update(['name' => $validated['head_name']]);
        }

        $family->update($validated);
        $family->load(['user', 'members']);

        return new FamilyResource($family);
    }

    public function destroy(Family $family): JsonResponse
    {
        DB::transaction(function () use ($family) {
            $user = $family->user;
            $family->delete();
            $user?->delete();
        });

        return response()->json(null, 204);
    }
}
