<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class Family extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'user_id',
        'head_name',
        'head_gender',
        'national_id',
        'phone',
        'social_status',
        'financial_status',
        'spouse_name',
        'spouse_national_id',
        'total_members',
        'file_status',
        'original_governorate',
        'original_neighborhood',
        'camp_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    /**
     * استعلام موحّد لفلترة العائلات في لوحة الإدارة وحفظ السجلات.
     */
    public static function queryForAdminFilters(Request $request): Builder
    {
        $query = static::query()->with('user');

        $scope = strtolower(trim((string) $request->input('filter_scope', 'family')));

        if ($scope === 'members') {
            $ageMin = $request->input('child_age_min');
            $ageMax = $request->input('child_age_max');
            $gender = $request->input('member_gender');
            $isNewborn = $request->boolean('member_is_newborn');
            if (is_array($ageMin)) {
                $ageMin = reset($ageMin);
            }
            if (is_array($ageMax)) {
                $ageMax = reset($ageMax);
            }
            if (is_string($ageMin)) {
                $ageMin = trim($ageMin);
            }
            if (is_string($ageMax)) {
                $ageMax = trim($ageMax);
            }
            if (is_string($gender)) {
                $gender = trim($gender);
            }

            $amin = $ageMin !== null && $ageMin !== '' && is_numeric($ageMin) ? (int) $ageMin : null;
            $amax = $ageMax !== null && $ageMax !== '' && is_numeric($ageMax) ? (int) $ageMax : null;
            if ($amin !== null && $amax !== null && $amin > $amax) {
                [$amin, $amax] = [$amax, $amin];
            }

            if ($isNewborn) {
                $amin = 0;
                $amax = 0;
            }
            $g = in_array($gender, [FamilyMember::GENDER_MALE, FamilyMember::GENDER_FEMALE], true)
                ? $gender
                : null;

            $rels = [];
            $rawRels = $request->input('member_relationships');
            if ($rawRels !== null && $rawRels !== '') {
                $rels = is_array($rawRels) ? $rawRels : [$rawRels];
            } elseif ($request->filled('member_relationship')) {
                $rels = [(string) $request->input('member_relationship')];
            }
            $rels = array_values(array_filter($rels, static fn ($v) => $v !== null && $v !== ''));
            $allowed = FamilyMember::allowedRelationships();
            $rels = array_values(array_intersect($rels, $allowed));

            $hasAge = $amin !== null || $amax !== null;
            $hasGender = $g !== null && $g !== '';
            $hasRel = $rels !== [];

            if (! $hasAge && ! $hasGender && ! $hasRel) {
                // فلترة «أفراد» بدون شروط فرعية: عائلات لديها فرد مسجّل على الأقل
                $query->whereHas('members');
                $query->with('members');
            } else {
                /**
                 * نفس الفلتر يُستخدم في:
                 * - whereHas(): يمرّر Builder
                 * - with(): يمرّر Relation (HasMany)
                 * لذلك نتجنب تقييد نوع $q.
                 */
                $memberFilter = function ($q) use ($amin, $amax, $g, $rels) {
                    $q->ageBetween($amin, $amax);
                    $q->gender($g);
                    if ($rels !== []) {
                        $q->whereIn('relationship', $rels);
                    }
                };

                $query->whereHas('members', $memberFilter);
                $query->with(['members' => $memberFilter]);
            }
        } else {
            $query->with('members');
            $query->socialStatus($request->input('social_status'));
            $query->financialStatus($request->input('financial_status'));

            $membersMin = $request->input('members_min');
            $membersMax = $request->input('members_max');
            $query->membersBetween(
                $membersMin !== null && $membersMin !== '' ? (int) $membersMin : null,
                $membersMax !== null && $membersMax !== '' ? (int) $membersMax : null
            );

            if ($request->boolean('has_newborn')) {
                $query->whereHas('members', function (Builder $q) {
                    $q->whereIn('relationship', ['ابن', 'ابنة'])
                        ->where(function (Builder $qq) {
                            $qq->whereNotNull('date_of_birth')
                                ->whereRaw('TIMESTAMPDIFF(YEAR, `date_of_birth`, CURDATE()) = 0')
                                ->orWhere(function (Builder $q2) {
                                    $q2->whereNull('date_of_birth')->whereNotNull('age')->where('age', 0);
                                });
                        });
                });
            }
        }

        return $query;
    }

    public function scopeSocialStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('social_status', $status);
    }

    public function scopeFinancialStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('financial_status', $status);
    }

    public function scopeMembersBetween(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($min !== null) {
            $query->where('total_members', '>=', $min);
        }
        if ($max !== null) {
            $query->where('total_members', '<=', $max);
        }

        return $query;
    }

    /**
     * Families that have at least one member with age in [min, max] (inclusive).
     */
    public function scopeWithMemberAgeBetween(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($min === null && $max === null) {
            return $query;
        }

        return $query->whereHas('members', function (Builder $q) use ($min, $max) {
            $q->ageBetween($min, $max);
        });
    }

    /**
     * Families with children in age range (optionally filtered by gender on members).
     */
    public function scopeWithChildrenFiltered(
        Builder $query,
        ?int $ageMin,
        ?int $ageMax,
        ?string $gender
    ): Builder {
        if ($ageMin === null && $ageMax === null && ($gender === null || $gender === '')) {
            return $query;
        }

        return $query->whereHas('members', function (Builder $q) use ($ageMin, $ageMax, $gender) {
            $q->ageBetween($ageMin, $ageMax);
            $q->gender($gender);
        });
    }

    /**
     * الملف مكتمل عندما يطابق عدد الأفراد المسجّلين العدد المعلن، ولكل فرد اسم وعمر وصلة قرابة.
     */
    public function profileComplete(): bool
    {
        $this->loadMissing('members');
        $expected = (int) $this->total_members;
        $members = $this->members;
        if ($expected < 1 || $members->count() !== $expected) {
            return false;
        }
        foreach ($members as $m) {
            if (trim((string) ($m->name ?? '')) === '') {
                return false;
            }
            if ($m->relationship === null || trim((string) $m->relationship) === '') {
                return false;
            }
            if ($m->age === null) {
                return false;
            }
        }

        return true;
    }
}
