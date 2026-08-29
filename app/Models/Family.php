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
    use BelongsToTenant, HasFactory;

    public const ADMIN_USER_EAGER = 'user:id,name,role,national_id,username,is_super,camp_id,created_at';

    /** @var list<string> */
    public const MEMBER_LIST_COLUMNS = ['id', 'family_id', 'name', 'age', 'date_of_birth', 'relationship', 'gender'];

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
        'extra_data',
        'camp_id',
    ];

    protected $casts = [
        'extra_data' => 'array',
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
    public static function queryForAdminFilters(Request $request, bool $withMembers = true): Builder
    {
        $query = static::query()->with(self::ADMIN_USER_EAGER);

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
                if ($withMembers) {
                    $query->with(['members' => self::constrainMemberListColumns(...)]);
                }
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
                if ($withMembers) {
                    $query->with(['members' => function ($q) use ($memberFilter) {
                        self::constrainMemberListColumns($q);
                        $memberFilter($q);
                    }]);
                }
            }
        } else {
            if ($withMembers) {
                $query->with(['members' => self::constrainMemberListColumns(...)]);
            }
            $socials = $request->input('social_statuses');
            if (! is_array($socials) || $socials === []) {
                $socials = $request->input('social_status');
            }
            $query->socialStatuses($socials);
            $query->financialStatus($request->input('financial_status'));

            $membersMin = $request->input('members_min');
            $membersMax = $request->input('members_max');
            $query->membersBetween(
                $membersMin !== null && $membersMin !== '' ? (int) $membersMin : null,
                $membersMax !== null && $membersMax !== '' ? (int) $membersMax : null
            );

            if ($request->boolean('has_newborn')) {
                $query->whereHas('members', function (Builder $q) {
                    $years = FamilyMember::yearsSinceDobExpression($q);
                    $q->whereIn('relationship', ['ابن', 'ابنة'])
                        ->where(function (Builder $qq) use ($years) {
                            $qq->where(function (Builder $dob) use ($years) {
                                $dob->whereNotNull('date_of_birth')
                                    ->whereRaw($years.' = 0');
                            })->orWhere(function (Builder $q2) {
                                $q2->whereNull('date_of_birth')->whereNotNull('age')->where('age', 0);
                            });
                        });
                });
            }
        }

        return $query;
    }

    /**
     * أعمدة الأفراد اللازمة للعرض دون تحميل camp_id والطوابع الزمنية.
     */
    public static function constrainMemberListColumns($query): void
    {
        $query->select(self::MEMBER_LIST_COLUMNS);
    }

    /**
     * مطلق يشمل السجلات القديمة «منفصل» و«أعزب».
     *
     * @return list<string>
     */
    public static function expandSocialStatusFilter(string $status): array
    {
        $canonical = match ($status) {
            'separated', 'single', 'منفصل', 'منفصلة' => 'divorced',
            default => $status,
        };

        if ($canonical === 'divorced') {
            return ['divorced', 'separated', 'single'];
        }

        return [$canonical];
    }

    public function scopeSocialStatus(Builder $query, ?string $status): Builder
    {
        return $query->socialStatuses($status);
    }

    public function scopeSocialStatuses(Builder $query, mixed $statuses): Builder
    {
        $list = is_array($statuses) ? $statuses : ($statuses !== null && $statuses !== '' ? [$statuses] : []);
        $list = array_values(array_filter(
            array_map(static fn ($v) => trim((string) $v), $list),
            static fn ($v) => $v !== ''
        ));

        if ($list === []) {
            return $query;
        }

        $expanded = [];
        foreach ($list as $status) {
            foreach (self::expandSocialStatusFilter($status) as $value) {
                $expanded[$value] = true;
            }
        }

        return $query->whereIn('social_status', array_keys($expanded));
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

    public function scopeWithProfileCompletenessCounts(Builder $query): Builder
    {
        return $query->withCount([
            'members',
            'members as incomplete_members_count' => function (Builder $q) {
                $q->where(function (Builder $inner) {
                    $inner->whereNull('name')
                        ->orWhereRaw("trim(name) = ''")
                        ->orWhereNull('relationship')
                        ->orWhereRaw("trim(relationship) = ''")
                        ->orWhereNull('age');
                });
            },
        ]);
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
        $expected = (int) $this->total_members;
        if ($expected < 1) {
            return false;
        }

        if (isset($this->members_count, $this->incomplete_members_count)) {
            return (int) $this->members_count === $expected
                && (int) $this->incomplete_members_count === 0;
        }

        $this->loadMissing('members');
        $members = $this->members;
        if ($members->count() !== $expected) {
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
