<?php

namespace App\Http\Requests;

use App\Models\FamilyMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // التحقق من التكرار يُنفَّذ في FamilyController مع withoutGlobalScopes (نطاق المخيم)
            'national_id' => ['required', 'string', 'max:32'],
            'head_name' => ['required', 'string', 'max:255'],
            'head_gender' => ['nullable', 'string', 'max:16'],
            'family_national_id' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'social_status' => ['nullable', 'string', 'max:64'],
            'financial_status' => ['nullable', 'string', Rule::in(['low', 'medium', 'good'])],
            'spouse_name' => ['nullable', 'string', 'max:255'],
            'spouse_national_id' => ['nullable', 'string', 'max:32'],
            'original_governorate' => ['nullable', 'string', 'max:64'],
            'original_neighborhood' => ['nullable', 'string', 'max:64'],
            'total_members' => ['required', 'integer', 'min:0', 'max:65535'],
            'members' => ['nullable', 'array'],
            'members.*.name' => ['required_with:members', 'string', 'max:255'],
            'members.*.date_of_birth' => ['nullable', 'date'],
            'members.*.age' => ['nullable', 'integer', 'min:0', 'max:150'], // توافق قديم
            'members.*.relationship' => ['nullable', 'string', Rule::in(FamilyMember::allowedRelationships())],
            'members.*.gender' => ['nullable', 'string', Rule::in([
                FamilyMember::GENDER_MALE,
                FamilyMember::GENDER_FEMALE,
                FamilyMember::GENDER_UNKNOWN,
            ])],
        ];
    }
}
