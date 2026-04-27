<?php

namespace App\Providers;

use App\Notifications\Channels\FcmChannel;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MetalsSpotService::class);

        $this->app->singleton(Messaging::class, function (): Messaging {
            $factory = new Factory();
            $credentials = config('services.firebase.credentials');
            $projectId = config('services.firebase.project_id');

            if (is_string($credentials) && $credentials !== '') {
                $factory = $factory->withServiceAccount($credentials);
            }

            if (is_string($projectId) && $projectId !== '') {
                $factory = $factory->withProjectId($projectId);
            }

            return $factory->createMessaging();
        });

        $this->app->singleton(FcmChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(ChannelManager::class)->extend('fcm', function ($app): FcmChannel {
            return $app->make(FcmChannel::class);
        });
    }
}
