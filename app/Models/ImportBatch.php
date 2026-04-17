<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'file_name',
        'imported_by',
        'status',
        'error_message',
        'rows_inserted',
        'rows_skipped',
        'rows_flagged',
        'rows_invalid',
    ];

    public function duplicateReviews(): HasMany
    {
        return $this->hasMany(DuplicateReview::class, 'batch_id');
    }
}
