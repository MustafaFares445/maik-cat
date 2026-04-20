<?php

namespace App\Services\Mobile;

use App\Data\AppVersionData;
use App\Models\AppVersion;
use Illuminate\Http\Exceptions\HttpResponseException;

class AppVersionService
{
    public function check(string $platform, string $version): AppVersionData
    {
        $model = AppVersion::query()
            ->where('platform', $platform)
            ->first();

        if (! $model) {
            throw new HttpResponseException(response()->json([
                'message' => "No version config found for platform: {$platform}",
            ], 404));
        }

        return AppVersionData::fromRequestAndModel($platform, $version, $model);
    }
}
