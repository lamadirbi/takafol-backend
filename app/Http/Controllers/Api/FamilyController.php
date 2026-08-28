<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFamilyRequest;
use App\Http\Resources\FamilyResource;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\FamilyFormSchema;
use Illuminate\Database\Eloquent\Builder;
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
        $withMembers = strtolower(trim((string) $request->input('filter_scope', 'family'))) === 'members';
        $query = Family::queryForAdminFilters($request, $withMembers);

        if (! $withMembers) {
            $query->withProfileCompletenessCounts();
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search', ''));
            if ($s !== '') {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $query->where(function (Builder $q) use ($s, $like) {
                    if (preg_match('/^\d+$/', $s) === 1) {
                        $q->where('national_id', $s)
                            ->orWhere('national_id', 'like', addcslashes($s, '%_\\').'%')
                            ->orWhere('head_name', 'like', $like);
                    } else {
                        $q->where('national_id', 'like', $like)
                            ->orWhere('head_name', 'like', $like);
                    }
                });
            }
        }

        $perPage = min(max(1, (int) $request->input('per_page', 15)), 200);
        $families = $query->orderBy('id')->paginate($perPage);

        return FamilyResource::collection($families)->response();
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'families' => Family::query()->count(),
            'members' => (int) Family::query()->sum('total_members'),
        ]);
    }

    /**
     * جاهزية معايير الفلترة حسب حقول المخيم (ملف الاستيراد) وامتلاء البيانات.
     */
    public function filterReadiness(FamilyFormSchema $schema): JsonResponse
    {
        $enabled = array_values(array_map(
            static fn (array $field): string => (string) $field['key'],
            $schema->enabledFields()
        ));

        $families = Family::query()->count();
        $members = FamilyMember::query()->count();

        return response()->json([
            'enabled_keys' => $enabled,
            'families' => $families,
            'members' => $members,
            'filled' => [
                'social_status' => Family::query()
                    ->whereNotNull('social_status')
                    ->where('social_status', '!=', '')
                    ->count(),
                'total_members' => Family::query()->where('total_members', '>', 0)->count(),
                'member_age' => FamilyMember::query()
                    ->where(function (Builder $q) {
                        $q->whereNotNull('date_of_birth')->orWhereNotNull('age');
                    })
                    ->count(),
                'member_gender' => FamilyMember::query()
                    ->whereNotNull('gender')
                    ->whereNotIn('gender', ['', FamilyMember::GENDER_UNKNOWN])
                    ->count(),
                'member_relationship' => FamilyMember::query()
                    ->whereNotNull('relationship')
                    ->where('relationship', '!=', '')
                    ->count(),
                'children' => FamilyMember::query()
                    ->whereIn('relationship', ['ابن', 'ابنة'])
                    ->count(),
            ],
        ]);
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
            $extracted = app(FamilyFormSchema::class)->extractFamilyAttributes($data);
            $columns = $extracted['columns'];
            $extra = $extracted['extra_data'];

            $family = Family::query()->create([
                'user_id' => $user->id,
                'head_name' => $data['head_name'],
                'head_gender' => $columns['head_gender'] ?? ($data['head_gender'] ?? null),
                'national_id' => $familyNationalId,
                'phone' => $columns['phone'] ?? ($data['phone'] ?? null),
                'social_status' => $columns['social_status'] ?? ($data['social_status'] ?? null),
                'financial_status' => $columns['financial_status'] ?? ($data['financial_status'] ?? null),
                'spouse_name' => $columns['spouse_name'] ?? ($data['spouse_name'] ?? null),
                'spouse_national_id' => $columns['spouse_national_id'] ?? ($data['spouse_national_id'] ?? null),
                'original_governorate' => $columns['original_governorate'] ?? ($data['original_governorate'] ?? null),
                'original_neighborhood' => $columns['original_neighborhood'] ?? ($data['original_neighborhood'] ?? null),
                'total_members' => $columns['total_members'] ?? ($data['total_members'] ?? 0),
                'extra_data' => $extra !== [] ? $extra : null,
            ]);

            $createdCount = 0;
            if (! empty($data['members'])) {
                $members = $data['members'];
                if ($extracted['has_date_of_birth'] && isset($members[0]) && is_array($members[0])) {
                    $members[0]['date_of_birth'] = $members[0]['date_of_birth'] ?? $extracted['date_of_birth'];
                    if (empty($members[0]['gender']) && ! empty($columns['head_gender'])) {
                        $members[0]['gender'] = $columns['head_gender'];
                    }
                }
                $created = $family->members()->createMany(array_map(static fn (array $member): array => [
                    'name' => $member['name'],
                    'date_of_birth' => $member['date_of_birth'] ?? null,
                    'age' => $member['age'] ?? null,
                    'relationship' => $member['relationship'] ?? null,
                    'gender' => $member['gender'] ?? FamilyMember::GENDER_UNKNOWN,
                ], $members));
                $createdCount = $created->count();
            } elseif ($extracted['has_date_of_birth'] || ! empty($data['head_name'])) {
                $family->members()->create([
                    'name' => $data['head_name'],
                    'relationship' => 'رب الأسرة',
                    'gender' => $columns['head_gender'] ?? FamilyMember::GENDER_UNKNOWN,
                    'date_of_birth' => $extracted['date_of_birth'],
                    'age' => null,
                ]);
                $createdCount = 1;
            }

            $family->update(['total_members' => $createdCount]);

            return $family->load([Family::ADMIN_USER_EAGER, 'members']);
        });

        return (new FamilyResource($family))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Family $family): FamilyResource
    {
        $family->load([
            Family::ADMIN_USER_EAGER,
            'members' => Family::constrainMemberListColumns(...),
            'distributions.packageType:id,name,description',
            'distributions.campFilterRecord:id,name,created_at',
        ]);

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
            'date_of_birth' => ['nullable', 'date'],
            'extra_data' => ['nullable', 'array'],
        ]);

        $extracted = app(FamilyFormSchema::class)->extractFamilyAttributes($validated);
        $patch = array_intersect_key($validated, array_flip([
            'head_name',
            'head_gender',
            'national_id',
            'phone',
            'social_status',
            'financial_status',
            'spouse_name',
            'spouse_national_id',
            'original_governorate',
            'original_neighborhood',
            'total_members',
        ]));
        foreach ($extracted['columns'] as $key => $value) {
            $patch[$key] = $value;
        }
        if ($extracted['extra_data'] !== [] || array_key_exists('extra_data', $validated)) {
            $patch['extra_data'] = array_merge($family->extra_data ?? [], $extracted['extra_data']);
        }

        $family->loadMissing('user');
        if (isset($patch['head_name'])) {
            $family->user?->update(['name' => $patch['head_name']]);
        }

        $family->update($patch);
        if ($extracted['has_date_of_birth'] || array_key_exists('head_gender', $patch)) {
            $head = $family->members()->where('relationship', 'رب الأسرة')->first();
            if ($head) {
                $headPatch = [];
                if ($extracted['has_date_of_birth']) {
                    $headPatch['date_of_birth'] = $extracted['date_of_birth'];
                    $headPatch['age'] = null;
                }
                if (array_key_exists('head_gender', $patch) && $patch['head_gender']) {
                    $headPatch['gender'] = $patch['head_gender'];
                }
                if (isset($patch['head_name'])) {
                    $headPatch['name'] = $patch['head_name'];
                }
                if ($headPatch !== []) {
                    $head->update($headPatch);
                }
            }
        }
        $family->load(['user', 'members']);

        return new FamilyResource($family);
    }

    public function destroy(Family $family): JsonResponse
    {
        DB::transaction(function () use ($family) {
            $family->loadMissing('user');
            $user = $family->user;
            $family->delete();
            $user?->delete();
        });

        return response()->json(null, 204);
    }
}
