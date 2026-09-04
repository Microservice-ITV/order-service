<?php

namespace App\Providers;

use App\Repositories\SagaRepository;
use App\Services\SagaCheckoutService;
use App\Services\SagaOrchestrator;
use App\Services\RabbitMQ\SagaConsumer;
use Illuminate\Support\ServiceProvider;

/**
 * Saga Service Provider
 * 
 * Registers saga-related services and sets up the saga consumer.
 */
class SagaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(SagaRepository::class, function ($app) {
            return new SagaRepository();
        });

        $this->app->bind(SagaOrchestrator::class, function ($app) {
            return new SagaOrchestrator(
                $app->make(SagaRepository::class),
                $app->make(\App\Services\RabbitMQ\Publisher::class)
            );
        });

        $this->app->bind(SagaCheckoutService::class, function ($app) {
            return new SagaCheckoutService(
                $app->make(\App\Services\CheckoutService::class),
                $app->make(SagaOrchestrator::class),
                $app->make(\App\Services\RabbitMQ\Publisher::class)
            );
        });

        $this->app->bind(SagaConsumer::class, function ($app) {
            return new SagaConsumer(
                $app->make(SagaOrchestrator::class),
                $app->make(SagaRepository::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register the saga consumer if we're in the right environment
        if (config('app.env') !== 'testing') {
            $this->app->make(SagaConsumer::class);
        }
    }
}