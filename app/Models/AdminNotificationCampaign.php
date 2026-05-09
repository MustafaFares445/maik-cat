<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminNotificationCampaign extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'sent_by',
        'audience_mode',
        'audience_id',
        'type',
        'title_en',
        'body_en',
        'title_ar',
        'body_ar',
        'title_hu',
        'body_hu',
        'payload',
        'total_recipients',
        'delivered_count',
        'failed_count',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(NotificationAudience::class, 'audience_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AdminNotificationCampaignRecipient::class, 'campaign_id');
    }
}
