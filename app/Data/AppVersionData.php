<?php

namespace App\Data;

use App\Enums\AppPlatform;
use App\Models\AppVersion;

class AppVersionData
{
    public string $platform;

    public string $currentVersion;

    public string $latestVersion;

    public string $minimumVersion;

    public bool $updateRequired;

    public bool $updateAvailable;

    public string $storeUrl;

    public ?string $releaseNotes;

    public function __construct(
        string $platform,
        string $currentVersion,
        string $latestVersion,
        string $minimumVersion,
        bool $updateRequired,
        bool $updateAvailable,
        string $storeUrl,
        ?string $releaseNotes
    ) {
        $this->platform = $platform;
        $this->currentVersion = $currentVersion;
        $this->latestVersion = $latestVersion;
        $this->minimumVersion = $minimumVersion;
        $this->updateRequired = $updateRequired;
        $this->updateAvailable = $updateAvailable;
        $this->storeUrl = $storeUrl;
        $this->releaseNotes = $releaseNotes;
    }

    public static function fromRequestAndModel(string $platform, string $currentVersion, AppVersion $model): self
    {
        $forceUpdate = version_compare($currentVersion, $model->minimum_version, '<');
        $softUpdate = $forceUpdate || version_compare($currentVersion, $model->latest_version, '<');

        return new self(
            platform: $platform,
            currentVersion: $currentVersion,
            latestVersion: $model->latest_version,
            minimumVersion: $model->minimum_version,
            updateRequired: $forceUpdate,
            updateAvailable: $softUpdate,
            storeUrl: AppPlatform::storeUrl($platform, $model->store_id),
            releaseNotes: $model->release_notes,
        );
    }
}
