<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(SiteSetting::allAsMap())
            ->header('Cache-Control', 'private, max-age=30');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'camp_name' => ['nullable', 'string', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:64'],
            'support_note' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data as $key => $value) {
            SiteSetting::putValue($key, $value);
        }

        return response()->json(SiteSetting::allAsMap());
    }
}
