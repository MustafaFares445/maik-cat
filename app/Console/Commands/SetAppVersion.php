<?php

namespace App\Console\Commands;

use App\Models\AppVersion;
use Illuminate\Console\Command;

class SetAppVersion extends Command
{
    protected $signature = 'app:version {platform : Platform name: ios or android} {--latest= : Latest released version in X.Y.Z format} {--minimum= : Minimum supported version in X.Y.Z format} {--store= : Store identifier or app id} {--notes= : Optional release notes}';

    protected $description = 'Create or update the mobile app version policy for a platform';

    public function handle(): int
    {
        $platform = strtolower(trim((string) $this->argument('platform')));
        $latestVersion = trim((string) $this->option('latest'));
        $minimumVersion = trim((string) $this->option('minimum'));
        $storeId = trim((string) $this->option('store'));
        $releaseNotes = $this->option('notes');

        if (! in_array($platform, ['ios', 'android'], true)) {
            $this->error('Platform must be either ios or android.');

            return self::FAILURE;
        }

        if (! preg_match('/^\d+\.\d+\.\d+$/', $latestVersion)) {
            $this->error('Latest version must use X.Y.Z format.');

            return self::FAILURE;
        }

        if (! preg_match('/^\d+\.\d+\.\d+$/', $minimumVersion)) {
            $this->error('Minimum version must use X.Y.Z format.');

            return self::FAILURE;
        }

        if (version_compare($minimumVersion, $latestVersion, '>')) {
            $this->error('Minimum version cannot be greater than latest version.');

            return self::FAILURE;
        }

        if ($storeId === '') {
            $this->error('Store identifier is required.');

            return self::FAILURE;
        }

        AppVersion::query()->updateOrCreate(
            ['platform' => $platform],
            [
                'latest_version' => $latestVersion,
                'minimum_version' => $minimumVersion,
                'store_id' => $storeId,
                'release_notes' => $releaseNotes !== null ? trim((string) $releaseNotes) : null,
            ]
        );

        $this->info("Version policy updated for {$platform}.");

        return self::SUCCESS;
    }
}
