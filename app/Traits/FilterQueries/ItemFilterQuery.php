<?php

namespace App\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait ItemFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(static::query()->calculablePrice()->with('carGroup'))
            ->allowedFilters(
                AllowedFilter::exact('category_id', 'car_group_id'),
                AllowedFilter::callback('text', static function (Builder $query, string $value): void {
                    $search = trim($value);

                    if ($search === '') {
                        return;
                    }

                    $query->where(function (Builder $inner) use ($search): void {
                        $inner->where('serial_code', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%")
                            ->orWhereHas('extraCodes', static function (Builder $codeQuery) use ($search): void {
                                $codeQuery->where('code', 'like', "%{$search}%");
                            });
                    });
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
}

