<?php

namespace App\Providers;

use App\Services\Probe\CurlProbeTransport;
use App\Services\Probe\FixtureProbeClient;
use App\Services\Probe\HostResolver;
use App\Services\Probe\NativeHostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeTransport;
use App\Services\Probe\SecureProbeClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HostResolver::class, NativeHostResolver::class);
        $this->app->bind(ProbeTransport::class, CurlProbeTransport::class);
        $this->app->bind(ProbeClient::class, function ($app): ProbeClient {
            if (config('monitoring.transport') === 'fixture') {
                abort_if(app()->isProduction(), 500, '正式環境禁止使用離線測試傳輸。');

                return $app->make(FixtureProbeClient::class);
            }

            return $app->make(SecureProbeClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip())
        );
    }
}
