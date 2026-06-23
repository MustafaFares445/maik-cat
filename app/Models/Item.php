<?php

namespace App\Models;

use App\Traits\FilterQueries\ItemFilterQuery;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Item extends Model implements HasMedia
{
    use HasFactory;
    use HasUuids;
    use InteractsWithMedia;
    use ItemFilterQuery;

    protected $fillable = [
        'car_group_id',
        'model',
        'serial_code',
        'normalized_serial',
        'weight_kg',
        'pt_ppm',
        'pd_ppm',
        'rh_ppm',
        'shape_code',
        'details',
        'source',
        'source_url',
        'source_hash',
    ];

    protected $casts = [
        'weight_kg' => 'float',
        'pt_ppm' => 'float',
        'pd_ppm' => 'float',
        'rh_ppm' => 'float',
    ];

    protected $appends = [
        'image_url',
        'image_thumb_url',
        'image_detail_url',
    ];

    public static function normalizeSerialValue(mixed $serial): string
    {
        if ($serial === null) {
            return '';
        }

        $value = Str::upper(trim((string) $serial));

        return preg_replace('/[\s\-\.\/]+/u', '', $value) ?? $value;
    }

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

    public function scopeCalculablePrice(Builder $query): Builder
    {
        return $query
            ->whereNotNull('weight_kg')
            ->whereNotNull('pt_ppm')
            ->whereNotNull('pd_ppm')
            ->whereNotNull('rh_ppm');
    }

    public function scopeApiVisible(Builder $query): Builder
    {
        return $query
            ->calculablePrice()
            ->whereHas('media', static function (Builder $mediaQuery): void {
                $mediaQuery->where('collection_name', 'images');
            });
    }

    public function isApiVisible(): bool
    {
        return $this->weight_kg !== null
            && $this->pt_ppm !== null
            && $this->pd_ppm !== null
            && $this->rh_ppm !== null
            && $this->hasMedia('images');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->resolveImageUrl('card');
    }

    public function getImageThumbUrlAttribute(): ?string
    {
        return $this->resolveImageUrl('thumb', $this->image_url);
    }

    public function getImageDetailUrlAttribute(): ?string
    {
        return $this->resolveImageUrl('detail', $this->image_url);
    }

    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_items', 'item_id', 'user_id')->withTimestamps();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk('public')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/avif',
                'image/svg+xml',
            ])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Contain, 240, 240)
            ->format('webp')
            ->quality(78)
            ->nonQueued();

        $this->addMediaConversion('card')
            ->fit(Fit::Contain, 640, 480)
            ->format('webp')
            ->quality(82)
            ->nonQueued();

        $this->addMediaConversion('detail')
            ->fit(Fit::Contain, 1280, 960)
            ->format('webp')
            ->quality(84)
            ->nonQueued();
    }

    private function resolveImageUrl(string $conversion, ?string $fallback = null): ?string
    {
        $url = $this->getFirstMediaUrl('images', $conversion);

        if ($url !== '') {
            return $url;
        }

        $url = $this->getFirstMediaUrl('images');

        if ($url !== '') {
            return $url;
        }

        return $fallback;
    }
}
