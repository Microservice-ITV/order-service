<?php

namespace App\Providers;

use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind Publisher as singleton for RabbitMQ
        $this->app->singleton(Publisher::class, function ($app) {
            return new Publisher();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if (env('APP_ENV') !== 'local') {
            URL::forceScheme('https');
        }

        // Register authorization policies
        Gate::policy(Order::class, OrderPolicy::class);
    }
}

