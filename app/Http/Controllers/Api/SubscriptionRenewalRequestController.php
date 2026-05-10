<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camp;
use App\Models\SubscriptionRenewalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SubscriptionRenewalRequestController extends Controller
{
    /**
     * سجل طلبات التجديد للمخيم الحالي (أدمن مخيم).
     */
    public function index(Request $request): JsonResponse
    {
        $campId = App::has('current_camp_id') ? (int) App::get('current_camp_id') : null;
        abort_if(! $campId, 400, 'No camp context.');

        $user = $request->user();
        abort_unless($user && $user->isAdmin(), 403);
        abort_unless($user->camp_id !== null && (int) $user->camp_id === $campId, 403);

        $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);
        $query = SubscriptionRenewalRequest::query()
            ->where('camp_id', $campId)
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        $paginator = $query->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (SubscriptionRenewalRequest $row) => $this->serializeRow($row))
        );

        return response()->json($paginator);
    }

    /**
     * إرسال طلب تجديد (أدمن مخيم).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $campId = App::has('current_camp_id') ? (int) App::get('current_camp_id') : null;
        abort_if(! $campId, 400, 'No camp context.');

        $user = $request->user();
        abort_unless($user && $user->isAdmin(), 403);
        abort_unless($user->camp_id !== null && (int) $user->camp_id === $campId, 403);

        $camp = Camp::query()->findOrFail($campId);

        // لا نسمح بأكثر من طلب pending لكل مخيم لتجنب الإغراق
        $hasPending = SubscriptionRenewalRequest::query()
            ->where('camp_id', $campId)
            ->where('status', SubscriptionRenewalRequest::STATUS_PENDING)
            ->exists();
        if ($hasPending) {
            return response()->json([
                'message' => 'يوجد طلب تجديد قيد المراجعة بالفعل.',
            ], 409);
        }

        $path = $request->file('image')->store('subscription-renewals', 'public');
        if (! is_string($path) || trim($path) === '' || $path === '0') {
            return response()->json([
                'message' => 'تعذر حفظ صورة الإشعار. يرجى إعادة المحاولة.',
            ], 500);
        }

        $row = SubscriptionRenewalRequest::query()->create([
            'camp_id' => $campId,
            'admin_user_id' => $user->id,
            'image_path' => $path,
            'status' => SubscriptionRenewalRequest::STATUS_PENDING,
        ]);

        return response()->json([
            ...$this->serializeRow($row),
            'message' => 'تم إرسال طلب تجديد الاشتراك. سيقوم الأدمن بمراجعته قريباً.',
        ], 201);
    }

    /**
     * قائمة الطلبات (سوبر أدمن عام).
     */
    public function superIndex(Request $request): JsonResponse
    {
        $u = $request->user();
        if (! $u || ! $u->isSuper() || $u->camp_id !== null) {
            abort(403);
        }

        $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);
        $query = SubscriptionRenewalRequest::withoutGlobalScopes()
            ->with(['camp:id,name,slug', 'adminUser:id,name,username'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        $paginator = $query->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (SubscriptionRenewalRequest $row) => $this->serializeRow($row))
        );

        return response()->json($paginator);
    }

    public function superUpdate(Request $request, SubscriptionRenewalRequest $subscriptionRenewalRequest): JsonResponse
    {
        $u = $request->user();
        if (! $u || ! $u->isSuper() || $u->camp_id !== null) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $row = SubscriptionRenewalRequest::withoutGlobalScopes()->findOrFail($subscriptionRenewalRequest->id);
        if ($row->status !== SubscriptionRenewalRequest::STATUS_PENDING) {
            return response()->json(['message' => 'تمت مراجعة الطلب مسبقاً.'], 409);
        }

        $row->update([
            'status' => $validated['status'],
            'admin_note' => isset($validated['admin_note']) ? trim((string) $validated['admin_note']) : null,
        ]);

        if ($validated['status'] === SubscriptionRenewalRequest::STATUS_APPROVED) {
            $camp = Camp::withoutGlobalScopes()->find($row->camp_id);
            if ($camp) {
                $renewalDays = (int) config('subscription.renewal_days', 30);
                $today = now()->startOfDay();
                $base = $camp->subscription_valid_until
                    ? \Carbon\Carbon::parse($camp->subscription_valid_until)->startOfDay()
                    : $today;
                if ($base->lt($today)) $base = $today;
                $camp->update(['subscription_valid_until' => $base->copy()->addDays($renewalDays)]);
            }
        }

        return response()->json($this->serializeRow($row->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRow(SubscriptionRenewalRequest $row): array
    {
        $camp = $row->relationLoaded('camp') ? $row->camp : null;
        $adminUser = $row->relationLoaded('adminUser') ? $row->adminUser : null;

        return [
            'id' => $row->id,
            'camp_id' => $row->camp_id,
            'camp_name' => $camp?->name,
            'camp_slug' => $camp?->slug,
            'admin_user_id' => $row->admin_user_id,
            'admin_name' => $adminUser?->name,
            'admin_username' => $adminUser?->username,
            'image_path' => $row->image_path,
            'image_url' => $row->imageUrl(),
            'status' => $row->status,
            'admin_note' => $row->admin_note,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}

