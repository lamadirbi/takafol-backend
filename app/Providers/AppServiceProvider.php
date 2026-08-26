<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;

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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction() && method_exists(Model::class, 'automaticallyEagerLoadRelationships')) {
            Model::automaticallyEagerLoadRelationships();
        }

        $this->configureRateLimiting();
        $this->logSlowQueries();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $max = $this->app->environment('testing') ? 120 : 8;

            return Limit::perMinute($max)->by($request->ip());
        });
    }

    private function logSlowQueries(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        DB::listen(function ($query): void {
            if ($query->time <= 100) {
                return;
            }

            Log::warning('Slow query detected', [
                'sql' => $query->sql,
                'time' => $query->time,
            ]);
        });
    }
}
