<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ItemFilterRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function index(ItemFilterRequest $request): AnonymousResourceCollection
    {
        $items = Item::getQuery()->paginate($request->integer('per_page', 20));

        return ItemResource::collection($items);
    }

    public function show(Item $item): JsonResponse
    {
        $item->load(['carGroup', 'extraCodes']);

        $related = Item::query()
            ->with(['carGroup', 'extraCodes'])
            ->where('car_group_id', $item->car_group_id)
            ->whereKeyNot($item->id)
            ->limit(5)
            ->get();

        return response()->json([
            'data' => ItemResource::make($item)->resolve(),
            'related' => ItemResource::collection($related)->resolve(),
        ]);
    }


    public function similar(Item $item): JsonResponse
    {
        $limit = max(1, min((int) request()->integer('limit', 8), 20));

        $similar = Item::query()
            ->with(['carGroup', 'extraCodes'])
            ->where('car_group_id', $item->car_group_id)
            ->whereKeyNot($item->id)
            ->orderByDesc('pt_ppm')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => ItemResource::collection($similar)->resolve(),
        ]);
    }
}
