<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class CampLogoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:5120'],
        ]);

        $camp = $this->resolveCamp($request);
        $camp->deleteStoredLogo();

        $path = $request->file('logo')->store('camp-logos', 'public');
        $camp->update(['logo_path' => $path]);

        $fresh = $camp->fresh();

        return response()->json([
            'logo_path' => $fresh->logo_path,
            'logo_url' => $fresh->logoUrl(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $camp = $this->resolveCamp($request);
        $camp->deleteStoredLogo();
        $camp->update(['logo_path' => null]);

        return response()->json([
            'message' => 'تم حذف شعار المخيم.',
            'logo_path' => null,
            'logo_url' => null,
        ]);
    }

    private function resolveCamp(Request $request): Camp
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

        return Camp::query()->findOrFail($campId);
    }
}
