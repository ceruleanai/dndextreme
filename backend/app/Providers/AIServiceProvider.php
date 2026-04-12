<?php

namespace App\Providers;

use App\Services\AI\AIManager;
use App\Services\AI\AIProvider;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIManager::class);

        $this->app->bind(AIProvider::class, function ($app) {
            return $app->make(AIManager::class)->provider();
        });
    }
}
