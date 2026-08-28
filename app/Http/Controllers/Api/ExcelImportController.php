<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camp;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\FamilyFormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use OpenSpout\Writer\Common\Creator\WriterFactory;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelImportController extends Controller
{
    /**
     * عناوين الأعمدة المعتمدة لاستيراد العائلات — يجب أن تطابق صف الرأس في Excel.
     *
     * @return list<string>
     */
    public static function familyImportHeaders(): array
    {
        return [
            'الإسم',
            'رقم الهوية',
            'الجنس',
            'تاريخ الميلاد',
            'الحالة الاجتماعية',
            'اسم الزوجة رباعي',
            'رقم هوية الزوجة',
            'رقم الموبايل',
            'عدد افراد الاسرة الكلي',
            'العنوان الأصلي- المحافظة',
            'العنوان الأصلي- الحي',
        ];
    }

    public function familiesTemplate(FamilyFormSchema $schema): StreamedResponse
    {
        $fileName = 'families-import-template.xlsx';
        $headers = $schema->excelHeaders();

        return response()->streamDownload(function () use ($headers) {
            $writer = WriterFactory::createFromFile('template.xlsx');
            $writer->openToBrowser('template.xlsx');

            $sheet = $writer->getCurrentSheet();
            $sheet->setSheetView((new SheetView)->setRightToLeft(true));
            foreach ($headers as $i => $unused) {
                $sheet->setColumnWidth(18, $i + 1);
            }

            $writer->addRow(Row::fromValues($headers));
            $writer->close();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function importFamilies(Request $request): JsonResponse
    {
        // استيراد Excel قد يستغرق وقتاً، خصوصاً مع تشفير كلمات المرور
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();

        $reader = ReaderFactory::createFromFile((string) $file->getClientOriginalName());
        $reader->open($path);

        $header = null;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $usersByNationalId = [];
        $adoptedHeaders = [];

        DB::connection()->disableQueryLog();

        DB::transaction(function () use ($reader, &$header, &$created, &$updated, &$skipped, &$usersByNationalId, &$adoptedHeaders) {
            $schema = app(FamilyFormSchema::class);
            $camp = App::has('current_camp') ? App::get('current_camp') : null;
            $camp = $camp instanceof Camp ? $camp : null;
            $enabledKeys = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $cells[] = $cell->getValue();
                    }

                    if ($rowIndex === 1) {
                        $header = array_map(fn ($v) => trim((string) $v), $cells);
                        if ($camp) {
                            $schema->applyAdoptedConfig($camp, $header);
                            $camp = $camp->fresh();
                            App::instance('current_camp', $camp);
                            $adoptedHeaders = $schema->excelHeaders($camp);
                            $enabledKeys = array_map(
                                static fn (array $field): string => (string) $field['key'],
                                $schema->enabledFields($camp)
                            );
                        }

                        continue;
                    }

                    $mapped = $schema->mapImportRow($header ?? [], $cells, $camp);
                    if ($mapped === null) {
                        $skipped++;

                        continue;
                    }

                    $nationalId = $mapped['national_id'];
                    $currentCampId = App::has('current_camp_id') ? (int) App::get('current_camp_id') : null;

                    $user = $usersByNationalId[$nationalId] ?? User::withoutGlobalScopes()
                        ->where('national_id', $nationalId)
                        ->first();

                    if (! $user) {
                        $user = User::query()->create([
                            'national_id' => $nationalId,
                            'name' => $mapped['head_name'],
                            'email' => null,
                            'password' => 'init',
                            'role' => User::ROLE_FAMILY_HEAD,
                        ]);
                        $serial = User::defaultSerialFromId((int) $user->id);
                        $user->password = $serial;
                        $user->save();
                    } else {
                        $familyOtherCamp = Family::withoutGlobalScopes()
                            ->where('user_id', $user->id)
                            ->first();
                        if ($familyOtherCamp && (int) $familyOtherCamp->camp_id !== (int) $currentCampId) {
                            $usersByNationalId[$nationalId] = $user;
                            $skipped++;

                            continue;
                        }
                        $user->name = $mapped['head_name'];
                        if (! $user->password || ! Hash::check(User::defaultSerialFromId((int) $user->id), $user->password)) {
                            $serial = User::defaultSerialFromId((int) $user->id);
                            $user->password = $serial;
                        }
                        $user->save();
                    }

                    $usersByNationalId[$nationalId] = $user;

                    $family = Family::query()->where('user_id', $user->id)->first();
                    $payload = [
                        'user_id' => $user->id,
                        'head_name' => $mapped['head_name'],
                        'national_id' => $nationalId,
                    ];
                    foreach ([
                        'head_gender',
                        'phone',
                        'social_status',
                        'financial_status',
                        'spouse_name',
                        'spouse_national_id',
                        'total_members',
                        'file_status',
                        'original_governorate',
                        'original_neighborhood',
                    ] as $column) {
                        if (! in_array($column, $enabledKeys, true)) {
                            continue;
                        }
                        $payload[$column] = $mapped[$column] ?? ($column === 'total_members' ? 0 : null);
                    }
                    $extra = is_array($mapped['extra_data'] ?? null) ? $mapped['extra_data'] : [];
                    if ($family) {
                        $payload['extra_data'] = array_merge($family->extra_data ?? [], $extra);
                        $family->update($payload);
                        $updated++;
                    } else {
                        if (Family::withoutGlobalScopes()->where('user_id', $user->id)->exists()) {
                            $skipped++;

                            continue;
                        }
                        $family = Family::query()->create([
                            ...$payload,
                            'extra_data' => $extra !== [] ? $extra : null,
                        ]);
                        $created++;
                    }

                    $this->upsertHeadMember($family, $mapped);
                    $this->upsertSpouseMember($family, $mapped);
                }
                break;
            }
        });

        $reader->close();

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'adopted_headers' => $adoptedHeaders,
        ]);
    }

    /**
     * @param  list<string>  $header
     * @param  list<mixed>  $cells
     * @return array<string, mixed>|null
     */
    private function mapFamilyRow(array $header, array $cells): ?array
    {
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = $cells[$i] ?? null;
        }

        $headName = trim((string) ($row['الإسم'] ?? ''));
        $nidRaw = $row['رقم الهوية'] ?? null;
        $nid = trim((string) $nidRaw);

        if ($headName === '' || $nid === '' || $nid === '0') {
            return null;
        }

        $genderAr = trim((string) ($row['الجنس'] ?? ''));
        $headGender = $genderAr === 'ذكر' ? FamilyMember::GENDER_MALE : ($genderAr === 'أنثى' ? FamilyMember::GENDER_FEMALE : FamilyMember::GENDER_UNKNOWN);

        $socialAr = trim((string) ($row['الحالة الاجتماعية'] ?? ''));
        $social = match ($socialAr) {
            'متزوج' => 'married',
            'أرمل', 'أرملة' => 'widowed',
            'منفصل', 'منفصلة' => 'separated',
            'مطلق', 'مطلقة' => 'divorced',
            'مهجور', 'مهجورة' => 'abandoned',
            default => null,
        };

        $dob = $this->normalizeExcelDate($row['تاريخ الميلاد'] ?? null);

        $wifeName = trim((string) ($row['اسم الزوجة رباعي'] ?? ''));
        $wifeId = trim((string) ($row['رقم هوية الزوجة'] ?? ''));
        if ($wifeName === '-' || $wifeName === '—') {
            $wifeName = '';
        }
        if ($wifeId === '-' || $wifeId === '—') {
            $wifeId = '';
        }

        $phone = trim((string) ($row['رقم الموبايل'] ?? ''));
        if ($phone === '-' || $phone === '—') {
            $phone = '';
        }

        $total = $row["عدد افراد\nالاسرة الكلي"] ?? ($row['عدد افراد الاسرة الكلي'] ?? null);
        $totalMembers = is_numeric($total) ? (int) $total : null;

        $gov = trim((string) ($row['العنوان الأصلي- المحافظة'] ?? ''));
        $neigh = trim((string) ($row['العنوان الأصلي- الحي'] ?? ''));

        return [
            'national_id' => $nid,
            'head_name' => $headName,
            'head_gender' => $headGender,
            'date_of_birth' => $dob,
            'social_status' => $social,
            'spouse_name' => $wifeName !== '' ? $wifeName : null,
            'spouse_national_id' => $wifeId !== '' ? $wifeId : null,
            'phone' => $phone !== '' ? $phone : null,
            'total_members' => $totalMembers,
            'original_governorate' => $gov !== '' ? $gov : null,
            'original_neighborhood' => $neigh !== '' ? $neigh : null,
            'file_status' => null,
        ];
    }

    private function normalizeExcelDate(mixed $value): ?string
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
        // قبول صيغة yyyy-mm-dd أو أي صيغة تاريخ يفهمها PHP
        try {
            return (new \DateTimeImmutable($s))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function upsertHeadMember(Family $family, array $mapped): void
    {
        $head = $family->members()->where('relationship', 'رب الأسرة')->first();
        $payload = [
            'name' => $mapped['head_name'],
            'relationship' => 'رب الأسرة',
            'gender' => $mapped['head_gender'] ?? FamilyMember::GENDER_UNKNOWN,
            'date_of_birth' => $mapped['date_of_birth'],
            'age' => null,
        ];
        if ($head) {
            $head->update($payload);
        } else {
            $family->members()->create($payload);
        }
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function upsertSpouseMember(Family $family, array $mapped): void
    {
        $spouseName = $mapped['spouse_name'] ?? null;
        if (! $spouseName) {
            return;
        }
        $rel = ($mapped['head_gender'] ?? null) === FamilyMember::GENDER_FEMALE ? 'زوج' : 'زوجة';
        $gender = ($mapped['head_gender'] ?? null) === FamilyMember::GENDER_FEMALE ? FamilyMember::GENDER_MALE : FamilyMember::GENDER_FEMALE;

        $spouse = $family->members()->where('relationship', $rel)->first();
        $payload = [
            'name' => (string) $spouseName,
            'relationship' => $rel,
            'gender' => $gender,
            'date_of_birth' => null,
            'age' => null,
        ];
        if ($spouse) {
            $spouse->update($payload);
        } else {
            $family->members()->create($payload);
        }
    }
}
