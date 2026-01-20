<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $contractsPath = app_path('Contracts');

        foreach (glob($contractsPath . '/*.php') as $file) {

            // CartInterface.php → CartInterface
            $interfaceName = pathinfo($file, PATHINFO_FILENAME);

            // Skip BaseInterface
            if ($interfaceName === 'BaseInterface') {
                continue;
            }

            $interface = "App\\Contracts\\{$interfaceName}";

            if (!interface_exists($interface)) {
                continue;
            }

            // CartInterface → CartRepository
            $repositoryName = Str::replaceLast('Interface', 'Repository', $interfaceName);

            $implementation = "App\\Repositories\\{$repositoryName}";

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
