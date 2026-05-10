<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementReaction extends Model
{
    use BelongsToTenant;

    public const TYPE_LIKE = 'like';

    public const TYPE_INTERESTED = 'interested';

    public const TYPE_THANKS = 'thanks';

    protected $fillable = [
        'announcement_id',
        'user_id',
        'type',
        'camp_id',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
