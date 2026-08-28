<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformContactMessage extends Model
{
    public const KIND_INQUIRY = 'inquiry';

    public const KIND_PLATFORM_CHANGE = 'platform_change';

    public const KIND_ISSUE = 'issue';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'name',
        'whatsapp_phone',
        'camp_name',
        'kind',
        'message',
        'status',
        'admin_note',
    ];

    public static function kinds(): array
    {
        return [
            self::KIND_INQUIRY,
            self::KIND_PLATFORM_CHANGE,
            self::KIND_ISSUE,
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_CLOSED,
        ];
    }
}
