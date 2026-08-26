<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDistributionRequest;
use App\Http\Resources\DistributionResource;
use App\Models\CampFilterRecord;
use App\Models\Distribution;
use App\Models\Family;
use App\Models\PackageType;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DistributionController extends Controller
{
    public function __construct(private readonly WebPushService $webPush) {}

    /**
     * تأكيد استلام طرد لعائلة واحدة ضمن سجل فلترة واسم طرد محدد.
     */
    public function confirmReceivedForFamily(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'camp_filter_record_id' => ['required', 'integer', 'exists:camp_filter_records,id'],
            'package_label' => ['required', 'string', 'max:255'],
            'family_id' => ['required', 'integer', 'exists:families,id'],
        ]);

        $record = CampFilterRecord::query()->findOrFail($validated['camp_filter_record_id']);

        $packageLabel = trim((string) $validated['package_label']);
        $familyId = (int) $validated['family_id'];

        $updated = (int) Distribution::query()
            ->where('camp_filter_record_id', $record->id)
            ->where('family_id', $familyId)
            ->where('package_label', $packageLabel)
            ->where('status', Distribution::STATUS_PENDING)
            ->update([
                'status' => Distribution::STATUS_RECEIVED,
                'delivered_at' => now(),
                'administered_by' => $request->user()->id,
            ]);

        return response()->json([
            'updated' => $updated,
            'camp_filter_record_id' => $record->id,
            'family_id' => $familyId,
            'package_label' => $packageLabel,
        ]);
    }

    /**
     * التراجع لعائلة واحدة: حذف سجل التوزيع لهذه العائلة (pending أو received) لهذا الطرد والسجل.
     * هذا يزيل الإشعار/الطرد من لوحة العائلة.
     */
    public function rollbackForFamily(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'camp_filter_record_id' => ['required', 'integer', 'exists:camp_filter_records,id'],
            'package_label' => ['required', 'string', 'max:255'],
            'family_id' => ['required', 'integer', 'exists:families,id'],
        ]);

        $record = CampFilterRecord::query()->findOrFail($validated['camp_filter_record_id']);

        $packageLabel = trim((string) $validated['package_label']);
        $familyId = (int) $validated['family_id'];

        $deleted = (int) Distribution::query()
            ->where('camp_filter_record_id', $record->id)
            ->where('family_id', $familyId)
            ->where('package_label', $packageLabel)
            ->delete();

        return response()->json([
            'deleted' => $deleted,
            'camp_filter_record_id' => $record->id,
            'family_id' => $familyId,
            'package_label' => $packageLabel,
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Distribution::query()
            ->with([
                'family' => function ($q) {
                    $q->with(Family::ADMIN_USER_EAGER)
                        ->withProfileCompletenessCounts();
                },
                'packageType:id,name,description',
                'administeredBy:id,name,role,username,is_super,camp_id,created_at,national_id,email',
                'administeredBy.camp:id,primary_admin_user_id',
                'campFilterRecord:id,name,created_at',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('family_id')) {
            $query->where('family_id', $request->integer('family_id'));
        }

        if ($request->filled('package_type_id')) {
            $query->where('package_type_id', $request->integer('package_type_id'));
        }

        if ($request->filled('package_label')) {
            $query->where('package_label', trim((string) $request->input('package_label')));
        }

        if ($request->filled('camp_filter_record_id')) {
            $query->where('camp_filter_record_id', $request->integer('camp_filter_record_id'));
        }

        $perPage = min(max(1, $request->integer('per_page', 20)), 500);
        $distributions = $query->latest('id')->paginate($perPage);

        return DistributionResource::collection($distributions);
    }

    /**
     * إنشاء سجلات توزيع (قيد الانتظار) لكل عائلات لقطة سجل فلترة معيّن.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'camp_filter_record_id' => ['required', 'integer', 'exists:camp_filter_records,id'],
            'package_type_id' => ['nullable', 'integer', 'exists:package_types,id'],
            'package_type_name' => ['nullable', 'string', 'max:255'],
            'package_label' => ['nullable', 'string', 'max:255'],
        ]);

        $name = isset($validated['package_type_name']) ? trim((string) $validated['package_type_name']) : '';
        $label = isset($validated['package_label']) ? trim((string) $validated['package_label']) : '';
        $hasId = $request->filled('package_type_id');
        $hasName = $name !== '';
        $hasLabel = $label !== '';

        // الجديد: يسمح بإرسال package_label كنص مباشرة
        if (! $hasLabel && ! $hasId && ! $hasName) {
            throw ValidationException::withMessages([
                'package_label' => ['أدخل نوع الطرد.'],
            ]);
        }

        if (($hasId && $hasName) || ($hasLabel && $hasId) || ($hasLabel && $hasName)) {
            throw ValidationException::withMessages([
                'package_label' => ['أرسل نوع الطرد كنص، أو اختر نوعاً مسجلاً، لا أكثر من خيار.'],
            ]);
        }

        $packageTypeId = null;
        $packageLabel = null;

        if ($hasLabel) {
            $packageLabel = $label;
        } elseif ($hasName) {
            $packageType = PackageType::query()->firstOrCreate(
                ['name' => $name],
                ['description' => null],
            );
            $packageTypeId = $packageType->id;
            $packageLabel = $packageType->name;
        } else {
            $packageTypeId = (int) $validated['package_type_id'];
            $pt = PackageType::query()->findOrFail($packageTypeId);
            $packageLabel = $pt->name;
        }

        $record = CampFilterRecord::query()->findOrFail($validated['camp_filter_record_id']);

        $snapshot = $record->snapshot ?? [];
        $familiesSnap = $snapshot['families'] ?? [];
        $idsFromSnap = collect($familiesSnap)->pluck('id')->filter()->unique()->values()->all();
        $familyIds = Family::query()->whereIn('id', $idsFromSnap)->pluck('id')->all();

        if ($familyIds === []) {
            return response()->json([
                'created' => 0,
                'skipped' => 0,
                'families_in_record' => 0,
                'camp_filter_record_id' => $record->id,
                'package_label' => $packageLabel,
            ]);
        }

        $created = 0;
        $skipped = 0;
        $campId = App::has('current_camp_id') ? (int) App::get('current_camp_id') : null;

        DB::transaction(function () use ($familyIds, $packageTypeId, $packageLabel, $request, $record, $campId, &$created, &$skipped) {
            $existingQuery = Distribution::query()
                ->whereIn('family_id', $familyIds)
                ->where('status', Distribution::STATUS_PENDING);
            if ($packageTypeId !== null) {
                $existingQuery->where('package_type_id', $packageTypeId);
            } else {
                $existingQuery->whereNull('package_type_id')->where('package_label', $packageLabel);
            }
            $existing = array_flip($existingQuery->pluck('family_id')->all());

            $now = now();
            $rows = [];
            foreach ($familyIds as $familyId) {
                if (isset($existing[$familyId])) {
                    $skipped++;

                    continue;
                }

                $rows[] = [
                    'family_id' => $familyId,
                    'package_type_id' => $packageTypeId,
                    'package_label' => $packageLabel,
                    'camp_filter_record_id' => $record->id,
                    'status' => Distribution::STATUS_PENDING,
                    'administered_by' => $request->user()->id,
                    'camp_id' => $campId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $created++;
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                Distribution::query()->insert($chunk);
            }
        });

        // إشعار Push للعائلات المُضافة للسجل (والتي صار عندها طرد قيد الانتظار).
        // الإشعار داخل صفحة العائلة أصلاً يظهر عبر distributions، وهذا الإشعار للهاتف.
        try {
            $userIds = Family::query()->whereIn('id', $familyIds)->pluck('user_id')->filter()->unique()->values()->all();
            $this->webPush->notifyFamilyHeadsByUserIds(
                $userIds,
                'لديك إشعار جديد',
                $packageLabel ? ('طرد بانتظار الاستلام: '.$packageLabel) : 'تم إضافة طرد بانتظار الاستلام.',
                '/family/notifications',
                [
                    'type' => 'distribution_pending',
                    'camp_filter_record_id' => $record->id,
                    'package_label' => $packageLabel,
                ]
            );
        } catch (\Throwable) {
            // تجاهل أخطاء push
        }

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'families_in_record' => count($familyIds),
            'camp_filter_record_id' => $record->id,
            'package_label' => $packageLabel,
        ]);
    }

    /**
     * إلغاء/حذف طرود قيد الانتظار المرتبطة بسجل فلترة (اختياري: لنوع طرد محدد).
     */
    public function bulkCancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'camp_filter_record_id' => ['required', 'integer', 'exists:camp_filter_records,id'],
            'package_type_id' => ['nullable', 'integer', 'exists:package_types,id'],
            'package_label' => ['nullable', 'string', 'max:255'],
        ]);

        $record = CampFilterRecord::query()->findOrFail($validated['camp_filter_record_id']);

        $q = Distribution::query()
            ->where('camp_filter_record_id', $record->id)
            ->where('status', Distribution::STATUS_PENDING);

        if ($request->filled('package_type_id')) {
            $q->where('package_type_id', (int) $validated['package_type_id']);
        } elseif ($request->filled('package_label')) {
            $q->whereNull('package_type_id')->where('package_label', trim((string) $validated['package_label']));
        }

        $deleted = (int) $q->delete();

        return response()->json([
            'deleted' => $deleted,
            'camp_filter_record_id' => $record->id,
        ]);
    }

    /**
     * تأكيد أن جميع الطرود لهذا السجل (ولنوع طرد محدد) تم استلامها.
     */
    public function bulkConfirmReceived(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'camp_filter_record_id' => ['required', 'integer', 'exists:camp_filter_records,id'],
            'package_label' => ['required', 'string', 'max:255'],
        ]);

        $record = CampFilterRecord::query()->findOrFail($validated['camp_filter_record_id']);

        $packageLabel = trim((string) $validated['package_label']);

        $updated = (int) Distribution::query()
            ->where('camp_filter_record_id', $record->id)
            ->where('package_label', $packageLabel)
            ->where('status', Distribution::STATUS_PENDING)
            ->update([
                'status' => Distribution::STATUS_RECEIVED,
                'delivered_at' => now(),
                'administered_by' => $request->user()->id,
            ]);

        return response()->json([
            'updated' => $updated,
            'camp_filter_record_id' => $record->id,
            'package_label' => $packageLabel,
        ]);
    }

    /**
     * التراجع عن التسليم: إلغاء الإشعار/الطرد بالكامل (حذف الطرود المرتبطة بالسجل ونوع الطرد).
     * هذا يجعل "الإشعار" يختفي من لوحة العائلة.
     */
    public function bulkRollbackReceived(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'camp_filter_record_id' => ['required', 'integer', 'exists:camp_filter_records,id'],
            'package_label' => ['required', 'string', 'max:255'],
        ]);

        $record = CampFilterRecord::query()->findOrFail($validated['camp_filter_record_id']);

        $packageLabel = trim((string) $validated['package_label']);

        $deleted = 0;

        DB::transaction(function () use ($record, $packageLabel, &$deleted) {
            $deleted = (int) Distribution::query()
                ->where('camp_filter_record_id', $record->id)
                ->where('package_label', $packageLabel)
                ->delete();

            // إزالة اسم الطرد من لقطة السجل حتى لا يبقى ظاهراً في واجهة الإدارة بعد حذف الطرود.
            // بدون هذا يبقى العنوان في «الطرود المرسلة» رغم عدم وجود توزيعات = الإشعار يبدو وكأنه لم يُلغَ.
            $snapshot = is_array($record->snapshot) ? $record->snapshot : [];
            $sent = $snapshot['sent_package_labels'] ?? null;
            if (is_array($sent)) {
                $filtered = collect($sent)
                    ->map(fn ($v) => trim((string) $v))
                    ->filter(fn ($v) => $v !== '')
                    ->reject(fn ($v) => $v === $packageLabel)
                    ->unique()
                    ->values()
                    ->all();
                $snapshot['sent_package_labels'] = $filtered;
            }

            $record->update(['snapshot' => $snapshot]);
        });

        return response()->json([
            'deleted' => $deleted,
            'camp_filter_record_id' => $record->id,
            'package_label' => $packageLabel,
        ]);
    }

    public function update(UpdateDistributionRequest $request, Distribution $distribution): DistributionResource
    {
        $data = $request->validated();
        $updates = ['status' => $data['status']];

        if (! empty($data['delivered_at'])) {
            $updates['delivered_at'] = Carbon::parse($data['delivered_at']);
        } elseif ($data['status'] === Distribution::STATUS_RECEIVED) {
            $updates['delivered_at'] = $distribution->delivered_at ?? now();
        } elseif ($data['status'] === Distribution::STATUS_PENDING) {
            $updates['delivered_at'] = null;
        }

        $updates['administered_by'] = $request->user()->id;

        $distribution->update($updates);
        $distribution->load(['packageType', 'administeredBy']);

        return new DistributionResource($distribution);
    }
}
