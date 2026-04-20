<?php

namespace App\Http\Resources\API;

use App\Data\AppVersionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppVersionData */
class AppVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'platform' => $this->platform,
            'currentVersion' => $this->currentVersion,
            'latestVersion' => $this->latestVersion,
            'minimumVersion' => $this->minimumVersion,
            'updateRequired' => $this->updateRequired,
            'updateAvailable' => $this->updateAvailable,
            'storeUrl' => $this->storeUrl,
            'releaseNotes' => $this->releaseNotes,
        ];
    }
}
