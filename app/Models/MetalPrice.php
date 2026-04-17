<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetalPrice extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'pt_usd_per_oz',
        'pd_usd_per_oz',
        'rh_usd_per_oz',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'pt_usd_per_oz' => 'float',
        'pd_usd_per_oz' => 'float',
        'rh_usd_per_oz' => 'float',
    ];

    public function calculations(): HasMany
    {
        return $this->hasMany(PriceCalculation::class, 'metal_price_id');
    }

    public function ptPerGram(): float
    {
        return $this->pt_usd_per_oz / 31.1043;
    }

    public function pdPerGram(): float
    {
        return $this->pd_usd_per_oz / 31.1043;
    }

    public function rhPerGram(): float
    {
        return $this->rh_usd_per_oz / 31.1043;
    }
}
