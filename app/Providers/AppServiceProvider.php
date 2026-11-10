<?php

namespace App\Providers;

use App\Auth\UserPasswordBrokerManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend('auth.password', fn ($manager, $app) => new UserPasswordBrokerManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
