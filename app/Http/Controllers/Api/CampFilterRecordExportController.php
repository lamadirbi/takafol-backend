<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampFilterRecord;
use App\Models\Family;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Common\Creator\WriterFactory;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampFilterRecordExportController extends Controller
{
    public function exportExcel(Request $request, CampFilterRecord $campFilterRecord): StreamedResponse
    {
        $snapshot = is_array($campFilterRecord->snapshot) ? $campFilterRecord->snapshot : [];
        $familiesSnap = is_array($snapshot['families'] ?? null) ? ($snapshot['families'] ?? []) : [];
        $idsFromSnap = collect($familiesSnap)->pluck('id')->filter()->unique()->values()->all();

        $families = Family::query()
            ->whereIn('id', $idsFromSnap)
            ->with(['members' => Family::constrainMemberListColumns(...)])
            ->orderBy('id')
            ->get();

        $fileName = 'filter-record-'.$campFilterRecord->id.'.xlsx';

        return response()->streamDownload(function () use ($families) {
            $writer = WriterFactory::createFromFile('export.xlsx');
            $writer->openToBrowser('export.xlsx');

            // RTL + column widths for Arabic
            $sheet = $writer->getCurrentSheet();
            $sheet->setSheetView((new SheetView)->setRightToLeft(true));
            // Column widths (1-based). Adjust to make fields readable.
            $sheet->setColumnWidth(6, 1); // م
            $sheet->setColumnWidth(30, 2); // الإسم
            $sheet->setColumnWidth(12, 3); // الجنس
            $sheet->setColumnWidth(18, 4); // رقم الهوية
            $sheet->setColumnWidth(16, 5); // تاريخ الميلاد
            $sheet->setColumnWidth(16, 6); // الحالة الاجتماعية
            $sheet->setColumnWidth(14, 7); // الصفة
            $sheet->setColumnWidth(30, 8); // اسم الزوجة
            $sheet->setColumnWidth(18, 9); // رقم هوية الزوجة
            $sheet->setColumnWidth(18, 10); // رقم الموبايل
            $sheet->setColumnWidth(18, 11); // عدد أفراد الأسرة
            $sheet->setColumnWidth(20, 12); // المحافظة
            $sheet->setColumnWidth(20, 13); // الحي

            $header = [
                'م',
                'الإسم',
                'الجنس',
                'رقم الهوية',
                'تاريخ الميلاد',
                'الحالة الاجتماعية',
                'الصفة',
                'اسم الزوجة رباعي',
                'رقم هوية الزوجة',
                'رقم الموبايل',
                "عدد افراد\nالاسرة الكلي",
                'العنوان الأصلي- المحافظة',
                'العنوان الأصلي- الحي',
            ];
            $writer->addRow(Row::fromValues($header));

            $i = 1;
            foreach ($families as $family) {
                $head = $family->members->firstWhere('relationship', 'رب الأسرة');
                $spouse = $family->members->firstWhere('relationship', $family->head_gender === 'female' ? 'زوج' : 'زوجة');
                $row = [
                    $i,
                    $family->head_name,
                    $family->head_gender === 'male' ? 'ذكر' : ($family->head_gender === 'female' ? 'أنثى' : ''),
                    $family->national_id,
                    $head?->date_of_birth?->toDateString(),
                    $this->socialStatusAr($family->social_status),
                    'رب الأسرة',
                    $family->spouse_name ?? ($spouse?->name ?? '-'),
                    $family->spouse_national_id ?? '-',
                    $family->phone ?? '',
                    $family->total_members,
                    $family->original_governorate ?? '',
                    $family->original_neighborhood ?? '',
                ];
                $writer->addRow(Row::fromValues($row));
                $i++;
            }

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * تصدير سجلات الأفراد: اسم الفرد + اسم رب الأسرة.
     */
    public function exportMembersExcel(Request $request, CampFilterRecord $campFilterRecord): StreamedResponse
    {
        $snapshot = is_array($campFilterRecord->snapshot) ? $campFilterRecord->snapshot : [];
        $familiesSnap = is_array($snapshot['families'] ?? null) ? ($snapshot['families'] ?? []) : [];
        $idsFromSnap = collect($familiesSnap)->pluck('id')->filter()->unique()->values()->all();

        $families = Family::query()
            ->whereIn('id', $idsFromSnap)
            ->with(['members' => Family::constrainMemberListColumns(...)])
            ->orderBy('id')
            ->get();

        $fileName = 'filter-record-members-'.$campFilterRecord->id.'.xlsx';

        return response()->streamDownload(function () use ($families) {
            $writer = WriterFactory::createFromFile('export.xlsx');
            $writer->openToBrowser('export.xlsx');

            $sheet = $writer->getCurrentSheet();
            $sheet->setSheetView((new SheetView)->setRightToLeft(true));
            $sheet->setColumnWidth(35, 1);
            $sheet->setColumnWidth(35, 2);

            $writer->addRow(Row::fromValues(['اسم الفرد', 'اسم رب الأسرة']));

            foreach ($families as $family) {
                $headName = (string) ($family->head_name ?? '');
                foreach ($family->members as $m) {
                    if ($m->relationship === 'رب الأسرة') {
                        continue;
                    }
                    $writer->addRow(Row::fromValues([(string) $m->name, $headName]));
                }
            }

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function socialStatusAr(?string $value): string
    {
        return match ($value) {
            'married' => 'متزوج',
            'widowed' => 'أرمل',
            'separated', 'divorced', 'single' => 'مطلق',
            'abandoned' => 'مهجور',
            default => '',
        };
    }
}
