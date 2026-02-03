<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\CheckoutItemInterface;
use App\Contracts\CheckoutSessionInterface;
use App\Repositories\CheckoutItemRepository;
use App\Repositories\CheckoutSessionRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Explicit bindings (faster and more predictable than filesystem scanning).
        $this->app->bind(CheckoutSessionInterface::class, CheckoutSessionRepository::class);
        $this->app->bind(CheckoutItemInterface::class, CheckoutItemRepository::class);

        // If you later add many interfaces/repositories and want auto-binding,
        // you can reintroduce the scanning approach here.
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
