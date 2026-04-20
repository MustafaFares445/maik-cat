<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\AppVersionRequest;
use App\Http\Resources\API\AppVersionResource;
use App\Services\Mobile\AppVersionService;
use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    private AppVersionService $appVersionService;

    public function __construct(AppVersionService $appVersionService)
    {
        $this->appVersionService = $appVersionService;
    }

    public function check(AppVersionRequest $request): JsonResponse
    {
        $platform = (string) $request->platform();
        $version = (string) $request->version();

        $version = $this->appVersionService->check($platform, $version);

        return response()->json(AppVersionResource::make($version)->resolve());
    }
}
