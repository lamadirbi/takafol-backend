<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FamilyMemberController extends Controller
{
    public function store(Request $request, Family $family): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'], // توافق قديم
            'relationship' => ['nullable', 'string', Rule::in(FamilyMember::allowedRelationships())],
            'gender' => ['nullable', 'string', Rule::in([
                FamilyMember::GENDER_MALE,
                FamilyMember::GENDER_FEMALE,
                FamilyMember::GENDER_UNKNOWN,
            ])],
        ]);

        $member = $family->members()->create($data);
        $family->increment('total_members');

        return response()->json(['data' => $member], 201);
    }

    public function update(Request $request, Family $family, FamilyMember $member): JsonResponse
    {
        abort_unless($member->family_id === $family->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'], // توافق قديم
            'relationship' => ['nullable', 'string', Rule::in(FamilyMember::allowedRelationships())],
            'gender' => ['nullable', 'string', Rule::in([
                FamilyMember::GENDER_MALE,
                FamilyMember::GENDER_FEMALE,
                FamilyMember::GENDER_UNKNOWN,
            ])],
        ]);

        $member->update($data);

        return response()->json(['data' => $member->fresh()]);
    }

    public function destroy(Family $family, FamilyMember $member): JsonResponse
    {
        abort_unless($member->family_id === $family->id, 404);

        $member->delete();
        $family->decrement('total_members');

        return response()->json(null, 204);
    }
}
