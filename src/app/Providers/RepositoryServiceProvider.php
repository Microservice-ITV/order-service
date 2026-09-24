<?php

namespace App\Providers;

use App\Contracts\CheckoutItemInterface;
use App\Contracts\CheckoutSessionInterface;
use App\Contracts\OrderInterface;
use App\Contracts\ShopOrderInterface;
use App\Repositories\CheckoutItemRepository;
use App\Repositories\CheckoutSessionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ShopOrderRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(CheckoutSessionInterface::class, CheckoutSessionRepository::class);
        $this->app->bind(CheckoutItemInterface::class, CheckoutItemRepository::class);
        $this->app->bind(OrderInterface::class, OrderRepository::class);
        $this->app->bind(ShopOrderInterface::class, ShopOrderRepository::class);

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
