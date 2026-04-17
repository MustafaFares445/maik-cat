<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'batch_id',
        'excel_row',
        'excel_sheet',
        'payload',
        'existing_item_id',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function existingItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'existing_item_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
