<?php

namespace App\Models;

use App\Traits\FilterQueries\ItemFilterQuery;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Item extends Model implements HasMedia
{
    use HasFactory;
    use HasUuids;
    use ItemFilterQuery;
    use InteractsWithMedia;

    protected $fillable = [
        'car_group_id',
        'model',
        'serial_code',
        'weight_kg',
        'pt_ppm',
        'pd_ppm',
        'rh_ppm',
        'shape_code',
        'details',
    ];

    protected $casts = [
        'weight_kg' => 'float',
        'pt_ppm' => 'float',
        'pd_ppm' => 'float',
        'rh_ppm' => 'float',
    ];

    public function carGroup(): BelongsTo
    {
        return $this->belongsTo(CarGroup::class, 'car_group_id');
    }

    public function extraCodes(): HasMany
    {
        return $this->hasMany(ExtraCode::class, 'item_id');
    }

    public function priceCalculations(): HasMany
    {
        return $this->hasMany(PriceCalculation::class, 'item_id');
    }

    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_items', 'item_id', 'user_id')->withTimestamps();
    }
}
