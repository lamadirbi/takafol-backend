<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DistributionResource;
use App\Http\Resources\FamilyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyPortalController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $family = $user->family()->with([
            'members',
            'distributions' => fn ($q) => $q
                ->whereNotNull('camp_filter_record_id')
                ->with(['packageType', 'campFilterRecord'])
                ->latest(),
        ])->firstOrFail();

        return response()->json([
            'family' => new FamilyResource($family),
            'current_distributions' => DistributionResource::collection(
                $family->distributions
                    ->where('status', '!=', 'not_eligible')
                    ->values()
            ),
        ]);
    }
}
