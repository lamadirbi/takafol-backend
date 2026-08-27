<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NtfyDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_key',
        'topic',
        'installed_at',
        'linked_at',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'linked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
