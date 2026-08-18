<?php

namespace App\Traits\FilterQueries;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait ItemFilterQuery
{
    public static function getQuery(?Request $request = null): QueryBuilder
    {
        return QueryBuilder::for(
            static::query()->apiVisible()->with(['carGroup', 'media']),
            $request
        )
            ->allowedFilters(
                AllowedFilter::exact('category_id', 'car_group_id'),
                AllowedFilter::callback('text', static function (Builder $query, string $value): void {
                    $search = trim($value);

                    self::applyTextSearch($query, $search);
                }),
                AllowedFilter::callback('car_group', static function (Builder $query, string $value): void {
                    $group = trim($value);

                    if ($group === '') {
                        return;
                    }

                    if (Str::isUuid($group)) {
                        $query->where('car_group_id', $group);

                        return;
                    }

                    $query->whereHas('carGroup', static function (Builder $groupQuery) use ($group): void {
                        $groupQuery->where('name', 'like', "%{$group}%")
                            ->orWhere('excel_sheet_name', 'like', "%{$group}%");
                    });
                }),
            )
            ->allowedSorts(
                AllowedSort::field('created_at'),
                AllowedSort::field('serial_code'),
                AllowedSort::field('model'),
            )
            ->defaultSort('-created_at');
    }

    private static function applyTextSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $normalizedSearch = Item::normalizeSerialValue($search);

        $query->where(static function (Builder $inner) use ($normalizedSearch, $search): void {
            $inner->where('serial_code', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")
                ->orWhereHas('extraCodes', static fn (Builder $codes): Builder => $codes->where('code', 'like', "%{$search}%"));

            if ($normalizedSearch !== '') {
                self::applyNormalizedCodeSearch($inner, $normalizedSearch);
            }
        });
    }

    private static function applyNormalizedCodeSearch(Builder $query, string $normalizedSearch): void
    {
        $query->orWhere('normalized_serial', 'like', "%{$normalizedSearch}%")
            ->orWhereHas('extraCodes', static fn (Builder $codes): Builder => $codes->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(code), ' ', ''), '-', ''), '.', ''), '/', '') LIKE ?",
                ["%{$normalizedSearch}%"],
            ));
    }
}
