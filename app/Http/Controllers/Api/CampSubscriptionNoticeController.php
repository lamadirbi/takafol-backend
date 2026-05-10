<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

class CampSubscriptionNoticeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $campId = App::has('current_camp_id') ? (int) App::get('current_camp_id') : null;
        abort_if(! $campId, 400, 'No camp context.');

        $user = $request->user();
        abort_unless($user && $user->isAdmin(), 403);

        if ($user->camp_id !== null && (int) $user->camp_id !== $campId) {
            abort(403);
        }

        if ($user->camp_id === null && ! $user->isSuper()) {
            abort(403);
        }

        $camp = Camp::query()->findOrFail($campId);

        if ($camp->subscription_notice_image_path) {
            Storage::disk('public')->delete($camp->subscription_notice_image_path);
        }

        $path = $request->file('image')->store('camp-subscription-notices', 'public');
        $camp->update(['subscription_notice_image_path' => $path]);

        $fresh = $camp->fresh();

        return response()->json([
            'subscription_notice_image_path' => $fresh->subscription_notice_image_path,
            'subscription_notice_image_url' => $fresh->subscriptionNoticeImageUrl(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $campId = App::has('current_camp_id') ? (int) App::get('current_camp_id') : null;
        abort_if(! $campId, 400, 'No camp context.');

        $user = $request->user();
        abort_unless($user && $user->isAdmin(), 403);

        if ($user->camp_id !== null && (int) $user->camp_id !== $campId) {
            abort(403);
        }

        if ($user->camp_id === null && ! $user->isSuper()) {
            abort(403);
        }

        $camp = Camp::query()->findOrFail($campId);

        if ($camp->subscription_notice_image_path) {
            Storage::disk('public')->delete($camp->subscription_notice_image_path);
        }

        $camp->update(['subscription_notice_image_path' => null]);

        return response()->json(['message' => 'تم حذف صورة الإشعار.']);
    }
}
