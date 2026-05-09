<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowIssue extends Model
{
    use HasUuids;

    protected $fillable = [
        'batch_id',
        'excel_sheet',
        'excel_row',
        'issue_code',
        'raw_payload',
        'normalized_payload',
    ];

    protected $casts = [
        'excel_row' => 'integer',
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}
