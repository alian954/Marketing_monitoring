<?php

namespace App\Providers;

use App\Services\Probe\CurlProbeTransport;
use App\Services\Probe\FixtureProbeClient;
use App\Services\Probe\HostResolver;
use App\Services\Probe\NativeHostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeTransport;
use App\Services\Probe\SecureProbeClient;
use App\Services\Probe\SecureTlsCertificateProbe;
use App\Services\Probe\StreamTlsCertificateTransport;
use App\Services\Probe\TlsCertificateProbe;
use App\Services\Probe\TlsCertificateTransport;
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
        $this->app->bind(TlsCertificateTransport::class, StreamTlsCertificateTransport::class);
        $this->app->bind(TlsCertificateProbe::class, SecureTlsCertificateProbe::class);
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
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(30)->by('login-ip:'.(string) $request->ip())
        );
        RateLimiter::for('notification-test', static function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(5)->by('user:'.($userId ?? $request->ip()));
        });
        RateLimiter::for('monitor-probe-preview', static function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(5)->by('user:'.($userId ?? $request->ip()));
        });
        RateLimiter::for('status-page-subscription', static function (Request $request): array {
            $limits = [Limit::perMinute(5)->by('status-subscribe-ip:'.$request->ip())];
            $email = mb_strtolower(trim((string) $request->input('email', '')));

            if ($email !== '') {
                $emailHash = hash_hmac('sha256', $email, (string) config('app.key'));
                $limits[] = Limit::perHour(3)->by('status-subscribe-email:'.$emailHash);
            }

            return $limits;
        });
    }
}
