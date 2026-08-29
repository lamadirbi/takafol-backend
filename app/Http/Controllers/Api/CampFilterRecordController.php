<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampFilterRecordResource;
use App\Models\CampFilterRecord;
use App\Models\Family;
use App\Models\FamilyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CampFilterRecordController extends Controller
{
    private const SNAPSHOT_LIMIT = 500;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max(1, (int) $request->input('per_page', 20)), 200);

        $records = CampFilterRecord::query()
            ->latest()
            ->paginate($perPage);

        $records->getCollection()->transform(function (CampFilterRecord $record) {
            $record->setAttribute('snapshot', $record->summarySnapshot());
            $record->syncOriginalAttribute('snapshot');

            return $record;
        });

        return CampFilterRecordResource::collection($records)->response();
    }

    public function show(Request $request, CampFilterRecord $campFilterRecord): JsonResponse
    {
        return (new CampFilterRecordResource($campFilterRecord))->response();
    }

    public function update(Request $request, CampFilterRecord $campFilterRecord): JsonResponse
    {
        $snapshot = $campFilterRecord->snapshot ?? [];
        $snapshot = $this->normalizeReceivedForRecord($campFilterRecord, $snapshot);

        $validated = $request->validate([
            // تخزين بسيط وثابت: قائمة IDs مستلمة (بدون مفاتيح رقمية تسبب مشاكل JSON)
            // مهم: نريد السماح بمصفوفة فارغة عند "التراجع عن التسليم"
            // وأيضاً دعم تحديثات جزئية (مثلاً تخزين اسم الطرد/قفل الإرسال).
            'received_ids' => ['sometimes', 'array'],
            'received_ids.*' => ['integer', 'min:1'],
            'active_package_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notify_locked' => ['sometimes', 'boolean'],
            // جديد: قائمة الطرود التي تم إرسال إشعارها (لإدارة عدة إشعارات).
            'sent_package_labels' => ['sometimes', 'array'],
            'sent_package_labels.*' => ['string', 'max:255'],
        ]);

        if (array_key_exists('received_ids', $validated)) {
            $snapshot['received_ids'] = collect($validated['received_ids'])
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        }

        if (array_key_exists('active_package_label', $validated)) {
            $label = $validated['active_package_label'];
            $snapshot['active_package_label'] = $label !== null ? trim((string) $label) : null;
        }

        if (array_key_exists('notify_locked', $validated)) {
            $snapshot['notify_locked'] = (bool) $validated['notify_locked'];
        }

        if (array_key_exists('sent_package_labels', $validated)) {
            $snapshot['sent_package_labels'] = collect($validated['sent_package_labels'])
                ->map(fn ($v) => trim((string) $v))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values()
                ->all();
        }

        // نتخلص من الشكل القديم لتفادي رجوعه كـ array وإرباك الواجهة
        unset($snapshot['received']);

        $campFilterRecord->update(['snapshot' => $snapshot]);

        return (new CampFilterRecordResource($campFilterRecord->fresh()))->response();
    }

    /**
     * تطبيع received: بعض السجلات القديمة كانت تخزّن received كمصفوفة (0..n-1).
     * هذا لا يتوافق مع الواجهة التي تستخدم familyId أو memberId كمفاتيح.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function normalizeReceivedForRecord(CampFilterRecord $record, array $snapshot): array
    {
        // الشكل الجديد (received_ids) إن كان موجوداً
        $receivedIds = $snapshot['received_ids'] ?? null;
        if (is_array($receivedIds)) {
            $snapshot['received_ids'] = collect($receivedIds)
                ->map(fn ($v) => is_numeric($v) ? (int) $v : null)
                ->filter()
                ->unique()
                ->values()
                ->all();
            unset($snapshot['received']);

            return $snapshot;
        }

        $received = $snapshot['received'] ?? null;
        if (! is_array($received)) {
            $snapshot['received_ids'] = [];
            unset($snapshot['received']);

            return $snapshot;
        }

        // إذا كانت List (0..n-1) نحولها إلى map بناءً على snapshot.families وترتيبها
        if (array_is_list($received)) {
            $criteria = is_array($record->criteria) ? $record->criteria : [];
            $scope = (string) ($criteria['filter_scope'] ?? 'family');
            $families = is_array($snapshot['families'] ?? null) ? ($snapshot['families'] ?? []) : [];

            $ids = [];
            $idx = 0;

            if ($scope === 'members') {
                foreach ($families as $fam) {
                    $members = is_array($fam['members'] ?? null) ? ($fam['members'] ?? []) : [];
                    foreach ($members as $m) {
                        $mid = $m['id'] ?? null;
                        if ($mid === null || $mid === '') {
                            continue;
                        }
                        if ((bool) ($received[$idx] ?? false)) {
                            $ids[] = (int) $mid;
                        }
                        $idx++;
                    }
                }
            } else {
                foreach ($families as $fam) {
                    $fid = $fam['id'] ?? null;
                    if ($fid === null || $fid === '') {
                        continue;
                    }
                    if ((bool) ($received[$idx] ?? false)) {
                        $ids[] = (int) $fid;
                    }
                    $idx++;
                }
            }

            $snapshot['received_ids'] = collect($ids)->unique()->values()->all();
            unset($snapshot['received']);
        } else {
            // received كان map (قديم) - نحول true keys إلى received_ids
            $ids = collect($received)
                ->filter(fn ($v) => (bool) $v)
                ->keys()
                ->map(fn ($k) => is_numeric($k) ? (int) $k : null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $snapshot['received_ids'] = $ids;
            unset($snapshot['received']);
        }

        return $snapshot;
    }

    /**
     * معاينة نتائج الفلترة دون حفظ في قاعدة البيانات.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->mergeNormalizedFilterRequest($request);
        $criteria = $this->validateFilterCriteria($request);
        $criteria['filter_scope'] = $criteria['filter_scope'] ?? 'family';
        $snapshot = $this->buildSnapshotFromCriteria($criteria);

        return response()->json([
            'data' => [
                'preview' => true,
                'snapshot' => $snapshot,
                'criteria' => $criteria,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mergeNormalizedFilterRequest($request);
        $validated = $this->validateStoreFilterRequest($request);
        $name = trim((string) $validated['name']);
        unset($validated['name']);
        $validated['filter_scope'] = $validated['filter_scope'] ?? 'family';

        $snapshot = $this->buildSnapshotFromCriteria($validated);

        $record = CampFilterRecord::query()->create([
            'user_id' => $request->user()->id,
            'name' => $name,
            'criteria' => $validated,
            'snapshot' => $snapshot,
        ]);

        return (new CampFilterRecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    private function mergeNormalizedFilterRequest(Request $request): void
    {
        $normalized = collect($request->all())
            ->map(fn ($v) => $v === '' ? null : $v)
            ->all();
        $request->merge($normalized);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function buildSnapshotFromCriteria(array $criteria): array
    {
        $fake = Request::create('/', 'POST', $criteria);
        $families = Family::queryForAdminFilters($fake)
            ->orderBy('id')
            ->limit(self::SNAPSHOT_LIMIT)
            ->get();

        $membersTotal = $families->sum(fn (Family $f) => $f->members->count());

        return [
            'generated_at' => now()->toIso8601String(),
            'limit_applied' => self::SNAPSHOT_LIMIT,
            'families_count' => $families->count(),
            'members_count' => $membersTotal,
            'received_ids' => [],
            'active_package_label' => null,
            'notify_locked' => false,
            'sent_package_labels' => [],
            'families' => $families->map(function (Family $f) {
                return [
                    'id' => $f->id,
                    'head_name' => $f->head_name,
                    'national_id' => $f->national_id,
                    'phone' => $f->phone,
                    'social_status' => $f->social_status,
                    'financial_status' => $f->financial_status,
                    'total_members' => $f->total_members,
                    'file_status' => $f->file_status,
                    'members' => $f->members->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'age' => $m->age,
                        'gender' => $m->gender,
                        'relationship' => $m->relationship,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterCriteriaRules(): array
    {
        return [
            'filter_scope' => ['nullable', 'string', Rule::in(['family', 'members'])],
            'social_status' => ['nullable', 'string', Rule::in(['married', 'widowed', 'divorced', 'abandoned', 'separated', 'single'])],
            'social_statuses' => ['nullable', 'array'],
            'social_statuses.*' => ['string', Rule::in(['married', 'widowed', 'divorced', 'abandoned', 'separated', 'single'])],
            'financial_status' => ['nullable', 'string', Rule::in(['low', 'medium', 'good'])],
            'members_min' => ['nullable', 'integer', 'min:0'],
            'members_max' => ['nullable', 'integer', 'min:0'],
            'has_newborn' => ['nullable', 'boolean'],
            'member_is_newborn' => ['nullable', 'boolean'],
            'child_age_min' => ['nullable', 'integer', 'min:0', 'max:150'],
            'child_age_max' => ['nullable', 'integer', 'min:0', 'max:150'],
            'member_gender' => ['nullable', 'string', 'in:male,female'],
            'member_relationship' => ['nullable', 'string', Rule::in(FamilyMember::allowedRelationships())],
            'member_relationships' => ['nullable', 'array'],
            'member_relationships.*' => ['string', Rule::in(FamilyMember::allowedRelationships())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilterCriteria(Request $request): array
    {
        return Validator::make($request->all(), $this->filterCriteriaRules())->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStoreFilterRequest(Request $request): array
    {
        return Validator::make($request->all(), array_merge(
            [
                'name' => ['required', 'string', 'min:1', 'max:160'],
            ],
            $this->filterCriteriaRules()
        ))->validate();
    }

    public function destroy(Request $request, CampFilterRecord $campFilterRecord): JsonResponse
    {
        $campFilterRecord->delete();

        return response()->json(null, 204);
    }
}
