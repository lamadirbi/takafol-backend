<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformContactMessage;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformContactMessageController extends Controller
{
    public function __construct(private readonly WebPushService $webPush) {}

    /**
     * رسالة تواصل عامة (بدون تسجيل دخول) — استفسار أو طلب تعديل على المنصة.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'whatsapp_phone' => ['required', 'string', 'max:32'],
            'camp_name' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::in(PlatformContactMessage::kinds())],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $row = PlatformContactMessage::query()->create([
            'name' => trim($validated['name']),
            'whatsapp_phone' => trim($validated['whatsapp_phone']),
            'camp_name' => isset($validated['camp_name']) ? (trim((string) $validated['camp_name']) ?: null) : null,
            'kind' => $validated['kind'],
            'message' => trim($validated['message']),
            'status' => PlatformContactMessage::STATUS_PENDING,
        ]);

        $titles = [
            PlatformContactMessage::KIND_INQUIRY => 'استفسار جديد',
            PlatformContactMessage::KIND_PLATFORM_CHANGE => 'طلب تعديل على المنصة',
            PlatformContactMessage::KIND_ISSUE => 'ملاحظة أو مشكلة على المنصة',
        ];

        $this->webPush->notifyGlobalSuperAdmins(
            $titles[$row->kind] ?? 'رسالة تواصل جديدة',
            $row->name.($row->camp_name ? ' — '.$row->camp_name : ''),
            '/super-admin/contact',
            [
                'type' => 'platform_contact',
                'platform_contact_message_id' => $row->id,
            ]
        );

        return response()->json([
            'id' => $row->id,
            'message' => 'تم استلام رسالتكم. الإدارة العليا تراجع الطلبات وتتواصل عبر واتساب عند الحاجة.',
        ], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        if (! $request->user()?->isSuper() || $request->user()->camp_id !== null) {
            abort(403);
        }

        $status = trim((string) $request->input('status', ''));
        $kind = trim((string) $request->input('kind', ''));
        $query = PlatformContactMessage::query()->latest('id');

        if (in_array($status, PlatformContactMessage::statuses(), true)) {
            $query->where('status', $status);
        }
        if (in_array($kind, PlatformContactMessage::kinds(), true)) {
            $query->where('kind', $kind);
        }

        $perPage = min(max(1, (int) $request->input('per_page', 20)), 50);

        return response()->json($query->paginate($perPage));
    }

    public function adminUpdate(Request $request, PlatformContactMessage $platformContactMessage): JsonResponse
    {
        if (! $request->user()?->isSuper() || $request->user()->camp_id !== null) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(PlatformContactMessage::statuses())],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $note = isset($validated['admin_note']) ? trim((string) $validated['admin_note']) : '';

        $platformContactMessage->update([
            'status' => $validated['status'],
            'admin_note' => $note !== '' ? $note : $platformContactMessage->admin_note,
        ]);

        return response()->json($platformContactMessage->fresh());
    }
}
