<?php

namespace Ashiful\Tg;

use Illuminate\Support\ServiceProvider;

class PestGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/tg.php' => config_path('tg.php'),
        ], 'tg-config');

        // Register command
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\GeneratePestTestsCommand::class,
            ]);
        }
    }

    /**
     * Register any application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/tg.php', 'tg'
        );
    }
}
