<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'platform',
        'latest_version',
        'minimum_version',
        'store_id',
        'release_notes',
    ];
}
