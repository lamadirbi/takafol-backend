<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampFilterRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'name',
        'criteria',
        'snapshot',
        'camp_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    /**
     * لقطة القائمة: الإحصاءات فقط دون مصفوفة العائلات الضخمة.
     *
     * @return array<string, mixed>
     */
    public function summarySnapshot(): array
    {
        $snapshot = is_array($this->snapshot) ? $this->snapshot : [];
        unset($snapshot['families']);

        if (! isset($snapshot['families_count']) && is_array($this->snapshot['families'] ?? null)) {
            $snapshot['families_count'] = count($this->snapshot['families']);
        }

        if (! isset($snapshot['members_count']) && is_array($this->snapshot['families'] ?? null)) {
            $snapshot['members_count'] = collect($this->snapshot['families'])
                ->sum(fn ($family) => is_array($family['members'] ?? null) ? count($family['members']) : 0);
        }

        return $snapshot;
    }
}
