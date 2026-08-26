<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageTypeResource;
use App\Models\PackageType;
use App\Support\TenantCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PackageTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = Cache::remember(
            TenantCache::packageTypesKey(),
            TenantCache::ttl(TenantCache::TTL_LONG),
            fn () => PackageType::query()->orderBy('name')->get()
        );

        return PackageTypeResource::collection($types)
            ->response()
            ->header('Cache-Control', 'private, max-age=60');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $type = PackageType::query()->create($data);

        return (new PackageTypeResource($type))
            ->response()
            ->setStatusCode(201);
    }
}
