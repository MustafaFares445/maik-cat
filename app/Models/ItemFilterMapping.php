<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemFilterMapping extends Model
{
    use HasFactory;
    use HasUuids;

    public const string STATUS_DETECTED = 'detected';
    public const string STATUS_APPROVED = 'approved';
    public const string STATUS_IGNORED = 'ignored';
    public const string STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'item_id',
        'filter_item_id',
        'filter_serial',
        'detection_method',
        'confidence',
        'status',
        'filter_weight_override',
        'evidence',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'filter_weight_override' => 'float',
        'evidence' => 'array',
        'approved_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function filterItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'filter_item_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
