<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class FamilyMember extends Model
{
    use HasFactory, BelongsToTenant;

    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    public const GENDER_UNKNOWN = 'unknown';

    /**
     * قيم صلة القرابة المسموحة (تطابق الواجهة).
     *
     * @return list<string>
     */
    public static function allowedRelationships(): array
    {
        return [
            'رب الأسرة',
            'زوجة',
            'زوج',
            'ابن',
            'ابنة',
            'أم',
            'أب',
            'أخ',
            'أخت',
            'جد',
            'جدة',
            'أخرى',
        ];
    }

    protected $fillable = [
        'family_id',
        'name',
        'age',
        'date_of_birth',
        'relationship',
        'gender',
        'camp_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    /**
     * العمر يُحسب ديناميكياً من تاريخ الميلاد إن وُجد.
     */
    public function getAgeAttribute($value): ?int
    {
        if ($this->date_of_birth) {
            return Carbon::parse($this->date_of_birth)->age;
        }

        return $value !== null ? (int) $value : null;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function scopeAgeBetween(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        // نفلتر حسب العمر المحسوب من date_of_birth إن وُجد، وإلا نستخدم age القديمة.
        if ($min !== null || $max !== null) {
            $query->where(function (Builder $q) use ($min, $max) {
                $q->whereNotNull('date_of_birth');
                if ($min !== null && $max !== null) {
                    $q->whereRaw('TIMESTAMPDIFF(YEAR, `date_of_birth`, CURDATE()) BETWEEN ? AND ?', [$min, $max]);
                } elseif ($min !== null) {
                    $q->whereRaw('TIMESTAMPDIFF(YEAR, `date_of_birth`, CURDATE()) >= ?', [$min]);
                } elseif ($max !== null) {
                    $q->whereRaw('TIMESTAMPDIFF(YEAR, `date_of_birth`, CURDATE()) <= ?', [$max]);
                }
            })->orWhere(function (Builder $q) use ($min, $max) {
                $q->whereNull('date_of_birth')->whereNotNull('age');
                if ($min !== null && $max !== null) {
                    $q->whereBetween('age', [$min, $max]);
                } elseif ($min !== null) {
                    $q->where('age', '>=', $min);
                } elseif ($max !== null) {
                    $q->where('age', '<=', $max);
                }
            });
        }

        return $query;
    }

    public function scopeGender(Builder $query, ?string $gender): Builder
    {
        if ($gender === null || $gender === '') {
            return $query;
        }

        return $query->where('gender', $gender);
    }
}
