<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Distribution extends Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_NOT_ELIGIBLE = 'not_eligible';

    protected $fillable = [
        'family_id',
        'package_type_id',
        'package_label',
        'camp_filter_record_id',
        'status',
        'delivered_at',
        'administered_by',
        'camp_id',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function packageType(): BelongsTo
    {
        return $this->belongsTo(PackageType::class);
    }

    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    public function campFilterRecord(): BelongsTo
    {
        return $this->belongsTo(CampFilterRecord::class);
    }
}
