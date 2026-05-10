<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampRegistrationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'applicant_name',
        'camp_name',
        'whatsapp_phone',
        'payment_notification_whatsapp',
        'message',
        'status',
        'admin_note',
    ];
}
