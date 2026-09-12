<?php

namespace App\Providers;

use Illuminate\Console\Signals;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Laravel Zero does not register Illuminate's ArtisanServiceProvider, so
     * Signals has no availability resolver and Command::trap() fatals on a null
     * callable. The sidecar traps SIGTERM/SIGINT to leave the bus politely, so
     * install the same resolver Laravel uses.
     */
    public function register(): void
    {
        Signals::resolveAvailabilityUsing(function (): bool {
            return $this->app->runningInConsole()
                && ! $this->app->runningUnitTests()
                && extension_loaded('pcntl');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
