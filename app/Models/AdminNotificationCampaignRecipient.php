<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNotificationCampaignRecipient extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'campaign_id',
        'user_id',
        'preferred_language',
        'language_used',
        'notification_id',
        'delivery_status',
        'fcm_message_id',
        'failure_reason',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdminNotificationCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
