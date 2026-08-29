<?php

namespace App\Services;

use App\Models\Camp;
use App\Models\FamilyMember;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

class FamilyFormSchema
{
    public const LOCKED_KEYS = ['national_id', 'head_name'];

    /** Always accepted on family change-requests so filters can be completed without a full form. */
    public const FILTER_CRITERIA_KEYS = ['social_status', 'head_gender', 'financial_status'];

    public const EXTRA_MEMBER_SLOTS = 6;

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            [
                'key' => 'national_id',
                'label' => 'رقم هوية رب الأسرة',
                'excel_header' => 'رقم الهوية',
                'type' => 'text',
                'locked' => true,
                'required' => true,
                'group' => 'family',
                'options' => [],
            ],
            [
                'key' => 'head_name',
                'label' => 'اسم رب الأسرة',
                'excel_header' => 'الإسم',
                'type' => 'text',
                'locked' => true,
                'required' => true,
                'group' => 'family',
                'options' => [],
            ],
            [
                'key' => 'head_gender',
                'label' => 'جنس رب الأسرة',
                'excel_header' => 'الجنس',
                'type' => 'select',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [
                    ['value' => 'male', 'label' => 'ذكر'],
                    ['value' => 'female', 'label' => 'أنثى'],
                    ['value' => 'unknown', 'label' => 'غير محدد'],
                ],
            ],
            [
                'key' => 'date_of_birth',
                'label' => 'تاريخ ميلاد رب الأسرة',
                'excel_header' => 'تاريخ الميلاد',
                'type' => 'date',
                'locked' => false,
                'required' => false,
                'group' => 'head_member',
                'options' => [],
            ],
            [
                'key' => 'social_status',
                'label' => 'الحالة الاجتماعية',
                'excel_header' => 'الحالة الاجتماعية',
                'type' => 'select',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [
                    ['value' => 'married', 'label' => 'متزوج'],
                    ['value' => 'widowed', 'label' => 'أرمل'],
                    ['value' => 'separated', 'label' => 'منفصل'],
                    ['value' => 'divorced', 'label' => 'مطلق'],
                    ['value' => 'abandoned', 'label' => 'مهجور'],
                ],
            ],
            [
                'key' => 'spouse_name',
                'label' => 'اسم الزوج/الزوجة',
                'excel_header' => 'اسم الزوجة رباعي',
                'type' => 'text',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [],
            ],
            [
                'key' => 'spouse_national_id',
                'label' => 'رقم هوية الزوج/الزوجة',
                'excel_header' => 'رقم هوية الزوجة',
                'type' => 'text',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [],
            ],
            [
                'key' => 'phone',
                'label' => 'الجوال',
                'excel_header' => 'رقم الموبايل',
                'type' => 'text',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [],
            ],
            [
                'key' => 'total_members',
                'label' => 'عدد أفراد الأسرة',
                'excel_header' => 'عدد افراد الاسرة الكلي',
                'type' => 'number',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [],
            ],
            [
                'key' => 'financial_status',
                'label' => 'الوضع المادي',
                'excel_header' => 'الوضع المادي',
                'type' => 'select',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [
                    ['value' => 'low', 'label' => 'منخفض'],
                    ['value' => 'medium', 'label' => 'متوسط'],
                    ['value' => 'good', 'label' => 'جيد'],
                ],
            ],
            [
                'key' => 'original_governorate',
                'label' => 'المحافظة الأصلية',
                'excel_header' => 'العنوان الأصلي- المحافظة',
                'type' => 'text',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [],
            ],
            [
                'key' => 'original_neighborhood',
                'label' => 'الحي الأصلي',
                'excel_header' => 'العنوان الأصلي- الحي',
                'type' => 'text',
                'locked' => false,
                'required' => false,
                'group' => 'family',
                'options' => [],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function defaultFields(): array
    {
        return array_map(static fn (array $field): array => [
            'key' => $field['key'],
            'enabled' => true,
            'required' => (bool) ($field['required'] ?? false),
            'source' => 'catalog',
        ], self::catalog());
    }

    public function currentCamp(): ?Camp
    {
        return App::has('current_camp') ? App::get('current_camp') : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCamp(?Camp $camp = null): array
    {
        $camp ??= $this->currentCamp();
        $stored = is_array($camp?->family_form_config) ? $camp->family_form_config : [];
        $fields = $stored['fields'] ?? null;
        if (! is_array($fields) || $fields === []) {
            $fields = $this->defaultFields();
        }

        return $this->resolve($fields);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledFields(?Camp $camp = null): array
    {
        return array_values(array_filter($this->forCamp($camp), static fn (array $f): bool => (bool) $f['enabled']));
    }

    /**
     * @param  list<array<string, mixed>>  $storedFields
     * @return list<array<string, mixed>>
     */
    public function resolve(array $storedFields): array
    {
        $catalog = [];
        foreach (self::catalog() as $field) {
            $catalog[$field['key']] = $field;
        }

        $out = [];
        $seen = [];
        foreach ($storedFields as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (isset($catalog[$key])) {
                $base = $catalog[$key];
                $locked = (bool) $base['locked'];
                $out[] = [
                    ...$base,
                    'enabled' => $locked ? true : (bool) ($row['enabled'] ?? true),
                    'required' => $locked ? true : (bool) ($row['required'] ?? $base['required']),
                    'source' => 'catalog',
                ];
                $seen[$key] = true;

                continue;
            }
            if (! str_starts_with($key, 'custom_')) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = (string) ($row['type'] ?? 'text');
            if (! in_array($type, ['text', 'number', 'date', 'select'], true)) {
                $type = 'text';
            }
            $excel = trim((string) ($row['excel_header'] ?? $label));
            $out[] = [
                'key' => $key,
                'label' => mb_substr($label, 0, 80),
                'excel_header' => mb_substr($excel !== '' ? $excel : $label, 0, 80),
                'type' => $type,
                'locked' => false,
                'enabled' => (bool) ($row['enabled'] ?? true),
                'required' => (bool) ($row['required'] ?? false),
                'source' => 'custom',
                'group' => 'custom',
                'options' => $this->normalizeOptions($row['options'] ?? []),
            ];
            $seen[$key] = true;
        }

        foreach ($catalog as $key => $base) {
            if (isset($seen[$key])) {
                continue;
            }
            $out[] = [
                ...$base,
                'enabled' => false,
                'source' => 'catalog',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{fields: list<array<string, mixed>>}
     */
    public function normalizeConfig(array $input): array
    {
        $fields = $input['fields'] ?? [];
        if (! is_array($fields)) {
            $fields = [];
        }
        $resolved = $this->resolve($fields);
        $saved = [];
        foreach ($resolved as $field) {
            $row = [
                'key' => $field['key'],
                'enabled' => (bool) $field['enabled'],
                'required' => (bool) $field['required'],
                'source' => $field['source'],
            ];
            if ($field['source'] === 'custom') {
                $row['label'] = $field['label'];
                $row['excel_header'] = $field['excel_header'];
                $row['type'] = $field['type'];
                $row['options'] = $field['options'];
            }
            $saved[] = $row;
        }

        return ['fields' => array_slice($saved, 0, 40)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{columns: array<string, mixed>, extra_data: array<string, mixed>, date_of_birth: mixed, has_date_of_birth: bool}
     */
    public function extractFamilyAttributes(array $input, ?Camp $camp = null): array
    {
        $enabled = $this->enabledFields($camp);
        $columns = [];
        $extra = [];
        $dob = null;
        $hasDob = false;
        $incomingExtra = is_array($input['extra_data'] ?? null) ? $input['extra_data'] : [];

        foreach ($enabled as $field) {
            $key = $field['key'];
            $hasKey = array_key_exists($key, $input) || array_key_exists($key, $incomingExtra);
            if (! $hasKey) {
                continue;
            }
            $value = array_key_exists($key, $input) ? $input[$key] : $incomingExtra[$key];
            if ($key === 'date_of_birth') {
                $hasDob = true;
                $dob = $this->blankToNull($value);

                continue;
            }
            if (($field['source'] ?? 'catalog') === 'custom') {
                $extra[$key] = $this->normalizeCustomValue($field, $value);

                continue;
            }
            $columns[$key] = $this->normalizeColumnValue($key, $value);
        }

        return [
            'columns' => $columns,
            'extra_data' => $extra,
            'date_of_birth' => $dob,
            'has_date_of_birth' => $hasDob,
        ];
    }

    /**
     * @param  list<string>  $header
     * @param  list<mixed>  $cells
     * @return array<string, mixed>|null
     */
    public function mapImportRow(array $header, array $cells, ?Camp $camp = null): ?array
    {
        $byHeader = [];
        foreach ($header as $i => $raw) {
            $label = $this->normalizeHeader((string) $raw);
            if ($label === '' || array_key_exists($label, $byHeader)) {
                continue;
            }
            $byHeader[$label] = $cells[$i] ?? null;
        }

        $enabled = $this->enabledFields($camp);
        $values = [];
        foreach ($enabled as $field) {
            $values[$field['key']] = $this->cellForField($byHeader, $field);
        }

        $headName = trim((string) ($values['head_name'] ?? ''));
        $nid = $this->stringifyIdentity($values['national_id'] ?? null);
        if ($headName === '' || $nid === '' || $nid === '0') {
            return null;
        }

        $extracted = $this->extractFamilyAttributes($values, $camp);
        $gender = $extracted['columns']['head_gender'] ?? FamilyMember::GENDER_UNKNOWN;
        if (! in_array($gender, [FamilyMember::GENDER_MALE, FamilyMember::GENDER_FEMALE, FamilyMember::GENDER_UNKNOWN], true)) {
            $gender = FamilyMember::GENDER_UNKNOWN;
        }

        return [
            'national_id' => $nid,
            'head_name' => $headName,
            'head_gender' => $gender,
            'date_of_birth' => $extracted['date_of_birth'],
            'social_status' => $extracted['columns']['social_status'] ?? null,
            'financial_status' => $extracted['columns']['financial_status'] ?? null,
            'spouse_name' => $extracted['columns']['spouse_name'] ?? null,
            'spouse_national_id' => $extracted['columns']['spouse_national_id'] ?? null,
            'phone' => $extracted['columns']['phone'] ?? null,
            'total_members' => $extracted['columns']['total_members'] ?? null,
            'original_governorate' => $extracted['columns']['original_governorate'] ?? null,
            'original_neighborhood' => $extracted['columns']['original_neighborhood'] ?? null,
            'file_status' => null,
            'extra_data' => $extracted['extra_data'],
            'spouse_date_of_birth' => $this->parseExcelDate(
                $byHeader['تاريخ ميلاد الزوج/الزوجة'] ?? $byHeader['تاريخ ميلاد الزوجة'] ?? null
            ),
            'extra_members' => $this->mapExtraMembersFromCells($byHeader),
        ];
    }

    /**
     * أعمدة النموذج التي تغطي كل معايير الفلترة (أسرة + أفراد).
     *
     * @return list<string>
     */
    public static function filterTemplateHeaders(): array
    {
        $headers = [
            'الإسم',
            'رقم الهوية',
            'الجنس',
            'تاريخ الميلاد',
            'الحالة الاجتماعية',
            'اسم الزوجة رباعي',
            'رقم هوية الزوجة',
            'تاريخ ميلاد الزوج/الزوجة',
            'رقم الموبايل',
            'عدد افراد الاسرة الكلي',
            'العنوان الأصلي- المحافظة',
            'العنوان الأصلي- الحي',
        ];

        for ($i = 1; $i <= self::EXTRA_MEMBER_SLOTS; $i++) {
            $headers[] = "اسم الفرد {$i}";
            $headers[] = "صلة القرابة {$i}";
            $headers[] = "جنس الفرد {$i}";
            $headers[] = "تاريخ ميلاد الفرد {$i}";
        }

        return $headers;
    }

    /**
     * عناوين التحميل: معايير الفلترة دائماً، ثم أي حقل مفعّل في المخيم غير مكرر.
     *
     * @return list<string>
     */
    public function templateHeaders(?Camp $camp = null): array
    {
        $base = self::filterTemplateHeaders();
        $seen = [];
        foreach ($base as $header) {
            $seen[$this->headerIdentity($header)] = true;
        }

        $out = $base;
        foreach ($this->excelHeaders($camp) as $header) {
            $header = trim((string) $header);
            if ($header === '' || $this->isMemberSlotHeader($header) || $this->isIgnorableHeader($header)) {
                continue;
            }
            $id = $this->headerIdentity($header);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $header;
        }

        return $out;
    }

    public function isMemberSlotHeader(string $label): bool
    {
        $normalized = $this->normalizeHeader($label);
        if (in_array($normalized, ['تاريخ ميلاد الزوج/الزوجة', 'تاريخ ميلاد الزوجة', 'تاريخ ميلاد الزوج'], true)) {
            return true;
        }

        return (bool) preg_match(
            '/^(اسم الفرد|صلة القرابة|صلة القرابه|جنس الفرد|تاريخ ميلاد الفرد)\s*\d+$/u',
            $normalized
        );
    }

    /**
     * يعتمد حقول المخيم من صف عناوين ملف إكسل قائم.
     *
     * @param  list<mixed>  $rawHeaders
     * @return array{fields: list<array<string, mixed>>}
     */
    public function adoptFromExcelHeaders(array $rawHeaders, ?Camp $camp = null): array
    {
        $camp ??= $this->currentCamp();
        $existingCustom = [];
        foreach ($this->forCamp($camp) as $field) {
            if (($field['source'] ?? '') !== 'custom') {
                continue;
            }
            $existingCustom[$this->normalizeHeader((string) ($field['excel_header'] ?? ''))] = $field['key'];
            $existingCustom[$this->normalizeHeader((string) ($field['label'] ?? ''))] = $field['key'];
        }

        $fields = [];
        $usedCatalog = [];
        foreach ($this->canonicalizeExcelHeaders($rawHeaders) as $label) {
            if ($label === '' || $this->isMemberSlotHeader($label)) {
                continue;
            }
            $catalogKey = $this->matchCatalogKey($label);
            if ($catalogKey && ! isset($usedCatalog[$catalogKey])) {
                $fields[] = [
                    'key' => $catalogKey,
                    'enabled' => true,
                    'required' => in_array($catalogKey, self::LOCKED_KEYS, true),
                    'source' => 'catalog',
                ];
                $usedCatalog[$catalogKey] = true;

                continue;
            }
            $customKey = $existingCustom[$label] ?? $this->freshCustomKey($label);
            $fields[] = [
                'key' => $customKey,
                'enabled' => true,
                'required' => false,
                'source' => 'custom',
                'label' => mb_substr($label, 0, 80),
                'excel_header' => mb_substr($label, 0, 80),
                'type' => 'text',
            ];
            $existingCustom[$label] = $customKey;
        }

        foreach (array_reverse(self::LOCKED_KEYS) as $locked) {
            if (isset($usedCatalog[$locked])) {
                continue;
            }
            array_unshift($fields, [
                'key' => $locked,
                'enabled' => true,
                'required' => true,
                'source' => 'catalog',
            ]);
            $usedCatalog[$locked] = true;
        }

        return $this->normalizeConfig(['fields' => $fields]);
    }

    /**
     * @param  list<mixed>  $rawHeaders
     * @return list<array<string, mixed>>
     */
    public function applyAdoptedConfig(Camp $camp, array $rawHeaders): array
    {
        $camp->family_form_config = $this->adoptFromExcelHeaders($rawHeaders, $camp);
        $camp->save();

        return $this->forCamp($camp->fresh());
    }

    public function matchCatalogKey(string $label): ?string
    {
        $normalized = $this->normalizeHeader($label);
        if ($normalized === '') {
            return null;
        }
        foreach (self::catalog() as $field) {
            $candidates = [
                $this->normalizeHeader((string) $field['excel_header']),
                $this->normalizeHeader((string) $field['label']),
            ];
            foreach ($this->headerAliases((string) $field['key']) as $alias) {
                $candidates[] = $this->normalizeHeader($alias);
            }
            if (in_array($normalized, $candidates, true)) {
                return $field['key'];
            }
        }

        return null;
    }

    /**
     * صف العناوين الحقيقي غالباً مش الصف الأول: ملفات المخيم بتحط اسم المخيم فوق.
     *
     * @param  list<mixed>  $cells
     */
    public function rowLooksLikeHeader(array $cells): bool
    {
        $hasName = false;
        $hasId = false;
        foreach ($this->canonicalizeExcelHeaders($cells) as $label) {
            $key = $this->matchCatalogKey($label);
            if ($key === 'head_name') {
                $hasName = true;
            }
            if ($key === 'national_id') {
                $hasId = true;
            }
        }

        return $hasName && $hasId;
    }

    /**
     * @param  list<mixed>  $rawHeaders
     * @return list<string>
     */
    public function canonicalizeExcelHeaders(array $rawHeaders): array
    {
        $out = [];
        $seen = [];
        $sawSpouseName = false;
        foreach ($rawHeaders as $raw) {
            $label = $this->normalizeHeader($this->stringifyHeaderCell($raw));
            if ($label === '' || $this->isIgnorableHeader($label)) {
                $out[] = '';

                continue;
            }
            $key = $this->matchCatalogKey($label);
            if ($key === 'national_id' && isset($seen['national_id']) && $sawSpouseName && ! isset($seen['spouse_national_id'])) {
                $label = 'رقم هوية الزوجة';
                $key = 'spouse_national_id';
            } elseif ($key && isset($seen[$key])) {
                $label .= ' (2)';
                $key = null;
            }
            if ($key) {
                $seen[$key] = true;
                if ($key === 'spouse_name') {
                    $sawSpouseName = true;
                }
            }
            $out[] = $label;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function excelHeaders(?Camp $camp = null): array
    {
        return array_values(array_map(
            static fn (array $field): string => (string) $field['excel_header'],
            $this->enabledFields($camp)
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function filterChangeRequestFamily(array $payload, ?Camp $camp = null): array
    {
        $allowed = [];
        $keys = [];
        foreach ($this->enabledFields($camp) as $field) {
            $key = $field['key'];
            if ($key === 'national_id' || $key === 'date_of_birth') {
                continue;
            }
            if (($field['source'] ?? 'catalog') === 'custom') {
                continue;
            }
            $keys[$key] = true;
        }
        foreach (self::FILTER_CRITERIA_KEYS as $key) {
            $keys[$key] = true;
        }
        foreach (array_keys($keys) as $key) {
            if (array_key_exists($key, $payload)) {
                $allowed[$key] = $payload[$key];
            }
        }
        if (isset($payload['extra_data']) && is_array($payload['extra_data'])) {
            $extra = [];
            foreach ($this->enabledFields($camp) as $field) {
                if (($field['source'] ?? '') !== 'custom') {
                    continue;
                }
                if (array_key_exists($field['key'], $payload['extra_data'])) {
                    $extra[$field['key']] = $payload['extra_data'][$field['key']];
                }
            }
            if ($extra !== []) {
                $allowed['extra_data'] = $extra;
            }
        }

        return $allowed;
    }

    /**
     * @param  mixed  $options
     * @return list<array{value: string, label: string}>
     */
    private function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $option) {
            if (is_string($option) || is_numeric($option)) {
                $label = trim((string) $option);
                if ($label === '') {
                    continue;
                }
                $out[] = ['value' => $label, 'label' => $label];

                continue;
            }
            if (! is_array($option)) {
                continue;
            }
            $label = trim((string) ($option['label'] ?? $option['value'] ?? ''));
            $value = trim((string) ($option['value'] ?? $label));
            if ($label === '' || $value === '') {
                continue;
            }
            $out[] = ['value' => mb_substr($value, 0, 80), 'label' => mb_substr($label, 0, 80)];
        }

        return array_slice($out, 0, 30);
    }

    /**
     * @param  array<string, mixed>  $byHeader
     * @param  array<string, mixed>  $field
     */
    private function cellForField(array $byHeader, array $field): mixed
    {
        $headers = array_filter([
            $this->normalizeHeader((string) ($field['excel_header'] ?? '')),
            $this->normalizeHeader((string) ($field['label'] ?? '')),
        ]);
        foreach ($this->headerAliases((string) $field['key']) as $alias) {
            $headers[] = $this->normalizeHeader($alias);
        }
        foreach ($headers as $header) {
            if ($header !== '' && array_key_exists($header, $byHeader)) {
                return $byHeader[$header];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    /**
     * @param  array<string, mixed>  $byHeader
     * @return list<array{name: string, relationship: string, gender: string, date_of_birth: ?string}>
     */
    private function mapExtraMembersFromCells(array $byHeader): array
    {
        $members = [];
        for ($i = 1; $i <= self::EXTRA_MEMBER_SLOTS; $i++) {
            $name = $this->blankToNull(
                $byHeader["اسم الفرد {$i}"] ?? $byHeader["اسم الفرد{$i}"] ?? null
            );
            if (! is_string($name) || $name === '') {
                continue;
            }
            $rel = $this->parseRelationship(
                $byHeader["صلة القرابة {$i}"] ?? $byHeader["صلة القرابه {$i}"] ?? null
            );
            if ($rel === 'رب الأسرة') {
                continue;
            }
            $members[] = [
                'name' => $name,
                'relationship' => $rel ?: 'أخرى',
                'gender' => $this->parseGender($byHeader["جنس الفرد {$i}"] ?? $byHeader["الجنس {$i}"] ?? null),
                'date_of_birth' => $this->parseExcelDate(
                    $byHeader["تاريخ ميلاد الفرد {$i}"] ?? $byHeader["تاريخ الميلاد {$i}"] ?? null
                ),
            ];
        }

        return $members;
    }

    public function parseExcelDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_array($value) && isset($value['date'])) {
            try {
                return (new \DateTimeImmutable((string) $value['date']))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $s = trim((string) ($value ?? ''));
        if ($s === '' || $s === '-' || $s === '—') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($s))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseRelationship(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '' || $s === '-' || $s === '—') {
            return null;
        }
        if (in_array($s, FamilyMember::allowedRelationships(), true)) {
            return $s;
        }

        return match ($s) {
            'بنت' => 'ابنة',
            'ولد', 'ابن/ة' => 'ابن',
            'الزوجة' => 'زوجة',
            'الزوج' => 'زوج',
            default => 'أخرى',
        };
    }

    private function headerIdentity(string $header): string
    {
        $key = $this->matchCatalogKey($header);

        return $key ?? $this->normalizeHeader($header);
    }

    private function headerAliases(string $key): array
    {
        return match ($key) {
            'head_name' => ['الإسم', 'الاسم', 'اسم رب الأسرة', 'اسم رب الاسره', 'الاسم الرباعي', 'الاسم رباعي', 'اسم رباعي', 'اسم العائلة', 'الاسم الرباعى'],
            'national_id' => ['رقم الهوية', 'رقم هوية رب الأسرة', 'رقم هوية رب الاسره', 'الهوية', 'رقم هوية'],
            'head_gender' => ['الجنس', 'جنس رب الأسرة'],
            'date_of_birth' => ['تاريخ الميلاد', 'تاريخ الولادة'],
            'social_status' => ['الحالة الاجتماعية', 'الحاله الاجتماعيه'],
            'spouse_name' => ['اسم الزوجة رباعي', 'اسم الزوجة', 'اسم الزوج', 'اسم الزوج/الزوجة'],
            'spouse_national_id' => ['رقم هوية الزوجة', 'رقم هوية الزوج', 'هوية الزوجة'],
            'total_members' => ["عدد افراد\nالاسرة الكلي", 'عدد افراد الاسرة الكلي', 'عدد أفراد الأسرة', 'عدد افراد الاسرة', 'عدد أفراد الاسرة', 'عدد الافراد'],
            'phone' => ['رقم الموبايل', 'الجوال', 'الهاتف', 'رقم الجوال', 'موبايل'],
            'financial_status' => ['الوضع المادي', 'الوضع المالي'],
            'original_governorate' => ['العنوان الأصلي- المحافظة', 'المحافظة الأصلية', 'المحافظة', 'المحافظه'],
            'original_neighborhood' => ['العنوان الأصلي- الحي', 'الحي الأصلي', 'الحي'],
            default => [],
        };
    }

    private function isIgnorableHeader(string $label): bool
    {
        $normalized = mb_strtolower($this->normalizeHeader($label));

        return in_array($normalized, ['#', 'م', 'م.', 'no', 'n', 'الرقم'], true);
    }

    private function stringifyHeaderCell(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface || is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function stringifyIdentity(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface || is_array($value)) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }

        return trim((string) ($value ?? ''));
    }

    private function normalizeHeader(string $raw): string
    {
        $s = trim(str_replace('*', '', $raw));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    private function normalizeColumnValue(string $key, mixed $value): mixed
    {
        if ($key === 'head_gender') {
            return $this->parseGender($value);
        }
        if ($key === 'social_status') {
            return $this->parseSocial($value);
        }
        if ($key === 'financial_status') {
            $s = strtolower(trim((string) ($value ?? '')));

            return in_array($s, ['low', 'medium', 'good'], true) ? $s : $this->parseFinancialLabel($value);
        }
        if ($key === 'total_members') {
            return is_numeric($value) ? (int) $value : null;
        }

        $s = $this->blankToNull($value);

        return $s;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeCustomValue(array $field, mixed $value): mixed
    {
        $s = $this->blankToNull($value);
        if ($s === null) {
            return null;
        }
        if (($field['type'] ?? 'text') === 'number') {
            return is_numeric($s) ? $s + 0 : $s;
        }

        return $s;
    }

    private function parseGender(mixed $value): string
    {
        $s = trim((string) ($value ?? ''));

        return match (true) {
            in_array($s, ['male', 'ذكر'], true) => FamilyMember::GENDER_MALE,
            in_array($s, ['female', 'أنثى', 'انثى'], true) => FamilyMember::GENDER_FEMALE,
            default => FamilyMember::GENDER_UNKNOWN,
        };
    }

    private function parseSocial(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return match ($s) {
            'married', 'متزوج' => 'married',
            'widowed', 'أرمل', 'أرملة' => 'widowed',
            'separated', 'منفصل', 'منفصلة' => 'separated',
            'divorced', 'مطلق', 'مطلقة' => 'divorced',
            'abandoned', 'مهجور', 'مهجورة' => 'abandoned',
            default => $s !== '' ? $s : null,
        };
    }

    private function parseFinancialLabel(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return match ($s) {
            'منخفض' => 'low',
            'متوسط' => 'medium',
            'جيد' => 'good',
            default => null,
        };
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $s = trim((string) ($value ?? ''));
        if ($s === '' || $s === '-' || $s === '—') {
            return null;
        }

        return $s;
    }

    public function freshCustomKey(string $label): string
    {
        $slug = Str::slug($label, '_');
        if ($slug === '') {
            $slug = 'field';
        }

        return 'custom_'.mb_substr($slug, 0, 40).'_'.Str::lower(Str::random(4));
    }
}
