<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camp;
use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class CampController extends Controller
{
    /**
     * المخيمات المفعّلة فقط — للصفحة العامة.
     */
    public function index()
    {
        $camps = Cache::flexible(
            TenantCache::publicCampsKey(),
            [TenantCache::TTL_SHORT, TenantCache::TTL_MEDIUM],
            function () {
                return Camp::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'logo_path', 'subscription_valid_until']);
            }
        );

        return response()->json($camps->map(function (Camp $camp) {
            return [
                'id' => $camp->id,
                'name' => $camp->name,
                'slug' => $camp->slug,
                'logo_path' => $camp->logo_path,
                'logo_url' => $camp->logoUrl(),
                'families_portal_locked' => $camp->familiesHardBlocked(),
                'families_in_subscription_grace' => $camp->familiesInGracePeriod(),
            ];
        }))->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * كل المخيمات — إدارة عليا (سوبر عام غير مرتبط بمخيم فقط).
     */
    public function adminIndex(Request $request)
    {
        $this->assertGlobalSuper($request);

        $camps = Camp::query()->orderBy('name')->get();

        return response()->json($camps);
    }

    public function show(string $slug)
    {
        $camp = TenantCache::rememberActiveBySlug($slug);
        if (! $camp) {
            abort(404);
        }

        return response()->json(array_merge($camp->toArray(), [
            'subscription_notice_image_url' => $camp->subscriptionNoticeImageUrl(),
            'subscription' => $camp->subscriptionAdminMeta(),
            'families_portal_locked' => $camp->familiesHardBlocked(),
            'families_in_subscription_grace' => $camp->familiesInGracePeriod(),
        ]))->header('Cache-Control', 'private, max-age=30');
    }

    public function store(Request $request)
    {
        $this->assertGlobalSuper($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:camps,slug',
            'logo_path' => 'nullable|string',
            'is_active' => 'boolean',
            'subscription_valid_until' => ['nullable', 'date'],
            'payment_notification_whatsapp' => ['nullable', 'string', 'max:32'],
        ]);

        $trialDays = (int) config('subscription.trial_days', 14);
        $subUntil = $validated['subscription_valid_until'] ?? null;
        if ($subUntil === null && $trialDays > 0) {
            $subUntil = Carbon::today()->addDays($trialDays)->toDateString();
        }

        $camp = Camp::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'logo_path' => $validated['logo_path'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'landing_page_data' => [],
            'subscription_valid_until' => $subUntil,
            'payment_notification_whatsapp' => isset($validated['payment_notification_whatsapp'])
                ? trim((string) $validated['payment_notification_whatsapp']) ?: null
                : null,
        ]);

        return response()->json($camp, 201);
    }

    public function update(Request $request, Camp $camp)
    {
        $this->assertGlobalSuper($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('camps', 'slug')->ignore($camp->id)],
            'logo_path' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'subscription_valid_until' => ['nullable', 'date'],
            'payment_notification_whatsapp' => ['nullable', 'string', 'max:32'],
        ]);

        if (array_key_exists('payment_notification_whatsapp', $validated)) {
            $validated['payment_notification_whatsapp'] = trim((string) $validated['payment_notification_whatsapp']) ?: null;
        }

        $camp->update($validated);

        return response()->json($camp->fresh());
    }

    public function destroy(Request $request, Camp $camp)
    {
        $this->assertGlobalSuper($request);

        $camp->delete();

        return response()->json(['message' => 'Camp deleted successfully']);
    }

    private function assertGlobalSuper(Request $request): void
    {
        $u = $request->user();
        if (! $u || ! $u->isSuper() || $u->camp_id !== null) {
            abort(403, 'Unauthorized. Global super admin only.');
        }
    }
}
