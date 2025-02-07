<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureCommands();
        $this->configureDates();
        $this->configureModels();
        $this->configureUrl();
        $this->configureVite();
        $this->configureRateLimiting();
    }

    /**
     * It's recommended to use CarbonImmutable as it's immutable and thread-safe to avoid issues with mutability.
     *
     * @see https://dyrynda.com.au/blog/laravel-immutable-dates
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    /**
     * Configure the application's commands.
     */
    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands(app()->isProduction());
    }

    /**
     * Configure the application's models.
     * This is optional, but it's recommended to enable strict mode and disable mass assignment.
     *
     * @see https://laravel.com/docs/eloquent#configuring-eloquent-strictness
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict();

        Model::unguard();
    }

    /**
     * Configure the application's URL.
     * This is optional, but it's recommended to force HTTPS in production.
     *
     * @see https://laravel.com/docs/octane#serving-your-application-via-https
     */
    private function configureUrl(): void
    {
        URL::forceHttps(app()->isProduction());
    }

    /**
     * Configure the application's Vite loading strategy.
     * This is optional, but it's recommended to use aggressive prefetching so the UI feels snappy.
     */
    private function configureVite(): void
    {
        Vite::useAggressivePrefetching();
    }

    /**
     * Configure the application's rate limiting.
     *
     * @see https://laravel.com/docs/11.x/routing#rate-limiting
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login-link', fn(Request $request) => $request->email ? Limit::perHour(5)->by($request->email) : Limit::perHour(5)->by($request->ip()));
    }
}
