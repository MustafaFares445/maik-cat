<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceCalculation extends Model
{
    use HasUuids;

    protected $fillable = [
        'item_id',
        'metal_price_id',
        'recovery_rate',
        'pt_value_usd',
        'pd_value_usd',
        'rh_value_usd',
        'total_usd',
        'calculated_at',
    ];

    protected $casts = [
        'recovery_rate' => 'float',
        'pt_value_usd' => 'float',
        'pd_value_usd' => 'float',
        'rh_value_usd' => 'float',
        'total_usd' => 'float',
        'calculated_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function metalPrice(): BelongsTo
    {
        return $this->belongsTo(MetalPrice::class, 'metal_price_id');
    }
}

