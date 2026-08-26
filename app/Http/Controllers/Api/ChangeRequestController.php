<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChangeRequestResource;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChangeRequestController extends Controller
{
    public function __construct(private readonly WebPushService $webPush) {}

    /**
     * @return array<string, mixed>
     */
    private function validateChangePayload(Request $request): array
    {
        return $request->validate([
            'payload' => ['required', 'array'],
            'payload.family' => ['nullable', 'array'],
            'payload.family.head_name' => ['nullable', 'string', 'max:255'],
            'payload.family.head_gender' => ['nullable', 'string', 'max:16'],
            'payload.family.phone' => ['nullable', 'string', 'max:32'],
            'payload.family.social_status' => ['nullable', 'string', 'max:64'],
            'payload.family.spouse_name' => ['nullable', 'string', 'max:255'],
            'payload.family.spouse_national_id' => ['nullable', 'string', 'max:32'],
            'payload.family.original_governorate' => ['nullable', 'string', 'max:64'],
            'payload.family.original_neighborhood' => ['nullable', 'string', 'max:64'],
            'payload.members' => ['nullable', 'array'],
            'payload.members.add' => ['nullable', 'array'],
            'payload.members.add.*.name' => ['required_with:payload.members.add', 'string', 'max:255'],
            'payload.members.add.*.relationship' => ['nullable', 'string', 'max:64'],
            'payload.members.add.*.gender' => ['nullable', 'string', 'max:16'],
            'payload.members.add.*.date_of_birth' => ['nullable', 'date'],
            'payload.members.update' => ['nullable', 'array'],
            'payload.members.update.*.id' => ['required_with:payload.members.update', 'integer'],
            'payload.members.update.*.name' => ['nullable', 'string', 'max:255'],
            'payload.members.update.*.relationship' => ['nullable', 'string', 'max:64'],
            'payload.members.update.*.gender' => ['nullable', 'string', 'max:16'],
            'payload.members.update.*.date_of_birth' => ['nullable', 'date'],
            'payload.members.delete' => ['nullable', 'array'],
            'payload.members.delete.*' => ['integer'],
        ]);
    }

    private function notifyAdminsOfChangeRequest(Family $family, ChangeRequest $changeRequest, bool $isUpdate): void
    {
        $head = trim((string) ($family->head_name ?? ''));
        if ($head === '') {
            $head = 'عائلة';
        }
        $title = $isUpdate ? 'تم تحديث طلب تعديل بيانات' : 'طلب تعديل بيانات جديد';
        $body = $isUpdate
            ? "{$head} — طلب #{$changeRequest->id} (محدّث)"
            : "{$head} — طلب #{$changeRequest->id} بانتظار المراجعة";
        $this->webPush->notifyCampAdmins((int) $family->camp_id, $title, $body, '/admin/change-requests', [
            'type' => 'change_request',
            'change_request_id' => $changeRequest->id,
            'family_id' => $family->id,
        ]);
    }

    /**
     * قائمة طلبات العائلة الحالية (لرب الأسرة).
     */
    public function familyIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $family = $user->family()->first();
        if (! $family) {
            return response()->json(['data' => []]);
        }

        $items = ChangeRequest::query()
            ->where('family_id', $family->id)
            ->latest('id')
            ->paginate(20);

        return ChangeRequestResource::collection($items)->response();
    }

    /**
     * إنشاء طلب تعديل (رب الأسرة).
     *
     * payload مثال:
     * {
     *   "family": { "phone": "...", "social_status": "married" },
     *   "members": {
     *     "add": [{ "name": "...", "relationship": "...", "gender": "male", "date_of_birth": "2000-01-01" }],
     *     "update": [{ "id": 123, "name": "...", "date_of_birth": "..." }],
     *     "delete": [123, 124]
     *   }
     * }
     */
    public function familyStore(Request $request): JsonResponse
    {
        $user = $request->user();
        $family = $user->family()->first();
        if (! $family) {
            abort(403);
        }

        $data = $this->validateChangePayload($request);

        $cr = ChangeRequest::query()->create([
            'family_id' => $family->id,
            'requested_by' => $user->id,
            'status' => ChangeRequest::STATUS_PENDING,
            'type' => 'family_profile',
            'payload' => $data['payload'],
        ]);

        $this->notifyAdminsOfChangeRequest($family, $cr, false);

        return (new ChangeRequestResource($cr))
            ->response()
            ->setStatusCode(201);
    }

    public function familyUpdate(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $user = $request->user();
        $family = $user->family()->first();
        if (! $family || $changeRequest->family_id !== $family->id) {
            abort(403);
        }
        if ($changeRequest->status !== ChangeRequest::STATUS_PENDING) {
            return response()->json(['message' => 'لا يمكن تعديل طلب غير قيد الانتظار.'], 422);
        }

        $data = $this->validateChangePayload($request);

        $changeRequest->update([
            'payload' => $data['payload'],
            'requested_by' => $user->id,
        ]);

        $fresh = $changeRequest->fresh();
        $this->notifyAdminsOfChangeRequest($family, $fresh, true);

        return (new ChangeRequestResource($fresh))->response();
    }

    /**
     * قائمة الطلبات (للأدمن).
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $q = ChangeRequest::query()
            ->with(['family:id,head_name,national_id'])
            ->latest('id');
        if ($status) {
            $q->where('status', $status);
        }

        return ChangeRequestResource::collection($q->paginate(30))->response();
    }

    /**
     * موافقة الأدمن وتطبيق الطلب على قاعدة البيانات.
     */
    public function adminApprove(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        if ($changeRequest->status !== ChangeRequest::STATUS_PENDING) {
            return response()->json(['message' => 'هذا الطلب ليس قيد الانتظار.'], 422);
        }

        $note = $request->input('review_note');

        DB::transaction(function () use ($request, $changeRequest, $note) {
            $family = Family::query()->lockForUpdate()->findOrFail($changeRequest->family_id);
            $family->loadMissing('user');
            $payload = is_array($changeRequest->payload) ? $changeRequest->payload : [];

            // تحديث بيانات العائلة
            $familyPatch = is_array($payload['family'] ?? null) ? ($payload['family'] ?? []) : [];
            $familyAllowed = array_intersect_key($familyPatch, array_flip([
                'head_name',
                'head_gender',
                'phone',
                'social_status',
                'spouse_name',
                'spouse_national_id',
                'original_governorate',
                'original_neighborhood',
            ]));
            if (array_key_exists('head_name', $familyAllowed)) {
                $family->user?->update(['name' => $familyAllowed['head_name']]);
            }
            if (count($familyAllowed)) {
                $family->update($familyAllowed);
            }

            // عمليات أفراد الأسرة
            $members = is_array($payload['members'] ?? null) ? ($payload['members'] ?? []) : [];
            $adds = is_array($members['add'] ?? null) ? ($members['add'] ?? []) : [];
            $updates = is_array($members['update'] ?? null) ? ($members['update'] ?? []) : [];
            $deletes = is_array($members['delete'] ?? null) ? ($members['delete'] ?? []) : [];

            foreach ($adds as $m) {
                if (! is_array($m)) {
                    continue;
                }
                $family->members()->create([
                    'name' => (string) ($m['name'] ?? ''),
                    'relationship' => $m['relationship'] ?? null,
                    'gender' => $m['gender'] ?? FamilyMember::GENDER_UNKNOWN,
                    'date_of_birth' => $m['date_of_birth'] ?? null,
                    'age' => null,
                ]);
            }

            foreach ($updates as $m) {
                if (! is_array($m)) {
                    continue;
                }
                $id = (int) ($m['id'] ?? 0);
                if (! $id) {
                    continue;
                }
                $member = $family->members()->where('id', $id)->first();
                if (! $member) {
                    continue;
                }
                $patch = array_intersect_key($m, array_flip(['name', 'relationship', 'gender', 'date_of_birth']));
                if (array_key_exists('date_of_birth', $patch) && ($patch['date_of_birth'] === '' || $patch['date_of_birth'] === null)) {
                    $patch['date_of_birth'] = null;
                }
                $patch['age'] = null;
                $member->update($patch);
            }

            if (count($deletes)) {
                $ids = collect($deletes)->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
                if (count($ids)) {
                    $headIds = $family->members()
                        ->where('relationship', 'رب الأسرة')
                        ->whereIn('id', $ids)
                        ->pluck('id')
                        ->all();
                    $ids = array_values(array_diff($ids, $headIds));
                    if (count($ids)) {
                        $family->members()->whereIn('id', $ids)->delete();
                    }
                }
            }

            $family->update(['total_members' => $family->members()->count()]);

            $changeRequest->update([
                'status' => ChangeRequest::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'review_note' => $note ? (string) $note : null,
                'reviewed_at' => now(),
            ]);
        });

        return (new ChangeRequestResource($changeRequest->fresh()))->response();
    }

    public function adminReject(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        if ($changeRequest->status !== ChangeRequest::STATUS_PENDING) {
            return response()->json(['message' => 'هذا الطلب ليس قيد الانتظار.'], 422);
        }
        $note = $request->input('review_note');

        $changeRequest->update([
            'status' => ChangeRequest::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'review_note' => $note ? (string) $note : null,
            'reviewed_at' => now(),
        ]);

        return (new ChangeRequestResource($changeRequest->fresh()))->response();
    }
}
