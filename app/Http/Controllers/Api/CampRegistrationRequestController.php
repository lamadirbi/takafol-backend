<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampRegistrationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampRegistrationRequestController extends Controller
{
    /**
     * طلب تسجيل مخيم جديد (عام — بدون تسجيل دخول).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'applicant_name' => ['required', 'string', 'max:160'],
            'camp_name' => ['required', 'string', 'max:255'],
            'whatsapp_phone' => ['required', 'string', 'max:32'],
            'payment_notification_whatsapp' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $row = CampRegistrationRequest::query()->create([
            'applicant_name' => trim($validated['applicant_name']),
            'camp_name' => trim($validated['camp_name']),
            'whatsapp_phone' => trim($validated['whatsapp_phone']),
            'payment_notification_whatsapp' => isset($validated['payment_notification_whatsapp'])
                ? (trim((string) $validated['payment_notification_whatsapp']) ?: null)
                : null,
            'message' => isset($validated['message']) ? trim((string) $validated['message']) : null,
            'status' => CampRegistrationRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'id' => $row->id,
            'message' => 'تم استلام طلبك. سيتواصل معك فريق المنصة عبر واتساب قريباً.',
        ], 201);
    }

    /**
     * قائمة الطلبات — سوبر أدمن فقط.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        if (! $request->user()?->isSuper() || $request->user()->camp_id !== null) {
            abort(403);
        }

        $items = CampRegistrationRequest::query()->latest('id')->paginate(50);

        return response()->json($items);
    }

    public function adminUpdate(Request $request, CampRegistrationRequest $campRegistrationRequest): JsonResponse
    {
        if (! $request->user()?->isSuper() || $request->user()->camp_id !== null) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,approved,rejected'],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $campRegistrationRequest->update([
            'status' => $validated['status'],
            'admin_note' => isset($validated['admin_note']) ? trim((string) $validated['admin_note']) : null,
        ]);

        return response()->json($campRegistrationRequest->fresh());
    }
}
