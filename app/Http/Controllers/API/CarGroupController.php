<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\CarGroupResource;
use App\Models\CarGroup;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CarGroupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $groups = CarGroup::query()->orderBy('name')->get();

        return CarGroupResource::collection($groups);
    }
}
