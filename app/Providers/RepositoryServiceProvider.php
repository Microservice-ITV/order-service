<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $contractsPath = app_path('Contracts');

        foreach (glob($contractsPath . '/*.php') as $interface)
        {
            // Example: App\Contracts\OrderRepositoryInterface
            $interface = 'App\\Contracts\\' . basename($interface, '.php');

            // Only bind interfaces
            if (!interface_exists($interface)) {
                continue;
            }

            // Convert Interface → Repository
            // OrderRepositoryInterface → OrderRepository
            $repositoryClass = str_replace('Interface', '', basename($interface, '.php'));

            // Full class name: App\Repositories\OrderRepository
            $implementation = 'App\\Repositories\\' . $repositoryClass;

            if (class_exists($implementation)) {
                $this->app->bind($interface, $implementation);
            }
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
