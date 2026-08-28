<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FamilyFormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

class FamilyFormSchemaController extends Controller
{
    public function __construct(private readonly FamilyFormSchema $schema) {}

    public function show(): JsonResponse
    {
        $camp = App::has('current_camp') ? App::get('current_camp') : null;

        return response()->json($this->payload($camp));
    }

    public function update(Request $request): JsonResponse
    {
        $camp = App::has('current_camp') ? App::get('current_camp') : null;
        if (! $camp) {
            throw ValidationException::withMessages([
                'fields' => ['لا يمكن حفظ حقول العائلات بدون مخيم.'],
            ]);
        }

        $data = $request->validate([
            'fields' => ['required', 'array', 'max:40'],
            'fields.*.key' => ['required', 'string', 'max:64'],
            'fields.*.enabled' => ['nullable', 'boolean'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.source' => ['nullable', 'string', 'max:16'],
            'fields.*.label' => ['nullable', 'string', 'max:80'],
            'fields.*.excel_header' => ['nullable', 'string', 'max:80'],
            'fields.*.type' => ['nullable', 'string', 'max:16'],
            'fields.*.options' => ['nullable', 'array'],
        ]);

        $config = $this->schema->normalizeConfig($data);
        $camp->family_form_config = $config;
        $camp->save();

        return response()->json($this->payload($camp->fresh()));
    }

    private function payload(mixed $camp): array
    {
        $fields = $this->schema->forCamp(is_object($camp) ? $camp : null);

        return [
            'fields' => $fields,
            'enabled_fields' => array_values(array_filter($fields, static fn (array $f): bool => (bool) $f['enabled'])),
            'catalog' => FamilyFormSchema::catalog(),
            'excel_headers' => $this->schema->excelHeaders(is_object($camp) ? $camp : null),
        ];
    }
}
