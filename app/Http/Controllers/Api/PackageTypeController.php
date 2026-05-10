<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageTypeResource;
use App\Models\PackageType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = PackageType::query()->orderBy('name')->get();

        return PackageTypeResource::collection($types)->response();
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
