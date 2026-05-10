<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class FamilyMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dob = $this->date_of_birth ? Carbon::parse($this->date_of_birth) : null;
        $ageYears = $this->age;
        $ageMonths = null;
        $ageDisplay = $ageYears !== null ? (string) $ageYears : null;
        if ($dob) {
            // Carbon::diffInMonths يعيد عدد صحيح، لكن نضمن النوع دائماً int
            $monthsTotal = (int) $dob->diffInMonths(Carbon::now());
            $years = (int) floor($monthsTotal / 12);
            if ($years <= 0) {
                $ageMonths = $monthsTotal;
                $ageDisplay = $monthsTotal <= 0 ? 'أقل من شهر' : $monthsTotal.' شهر';
            } else {
                $ageDisplay = (string) $years;
            }
        }

        return [
            'id' => $this->id,
            'family_id' => $this->family_id,
            'name' => $this->name,
            'date_of_birth' => optional($this->date_of_birth)->toDateString(),
            'age' => $this->age,
            'age_months' => $ageMonths,
            'age_display' => $ageDisplay,
            'relationship' => $this->relationship,
            'gender' => $this->gender,
        ];
    }
}
