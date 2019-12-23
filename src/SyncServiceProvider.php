<?php

namespace HasnHasan\MigrationSync;

use HasnHasan\MigrationSync\Console\Commands\MigrationSync;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{

    private $configFileName = 'migration-sync';

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Merge Config
        $this->mergeConfigFrom(
            __DIR__.'/config/'.$this->configFileName.'.php', $this->configFileName
        );

        // Command Register
        $this->commands([
            MigrationSync::class,
        ]);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */

    public function boot()
    {
        // Config File
        $this->publishes([
            __DIR__.'/config/'.$this->configFileName.'.php' => config_path($this->configFileName.'.php'),
        ]);

    }
}
