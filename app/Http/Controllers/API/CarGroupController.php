<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\CarGroupResource;
use App\Models\CarGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CarGroupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $groups = CarGroup::query()
            ->whereHas('items', static function (Builder $query): void {
                $query->apiVisible();
            })
            ->orderBy('name')
            ->get();

        return CarGroupResource::collection($groups);
    }
}
