<?php

declare(strict_types=1);

namespace Prism\Browser;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Prism\Browser\Console\ServeCommand;
use Prism\Browser\Contracts\AttachmentStore;
use Prism\Browser\Contracts\BrowserEngine;
use Prism\Browser\Engine\PlaywrightSidecarEngine;
use Prism\Browser\Security\BrowserPolicy;
use Prism\Browser\Security\ObservationGuard;
use Prism\Browser\Stores\LaravelAttachmentStore;
use Prism\Browser\Tools\BrowserToolset;

final class PrismBrowserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/prism-browser.php', 'prism-browser');
        $this->app->singleton(AttachmentStore::class, LaravelAttachmentStore::class);
        $this->app->singleton(BrowserPolicy::class, fn (): BrowserPolicy => new BrowserPolicy(
            allowedHosts: config('prism-browser.policy.allowed_hosts', []),
            requireHttps: (bool) config('prism-browser.policy.require_https', true),
            maxObservationBytes: (int) config('prism-browser.policy.max_observation_bytes', 65536),
            allowedPorts: config('prism-browser.policy.allowed_ports', [443]),
        ));
        $this->app->singleton(BrowserEngine::class, function ($app): BrowserEngine {
            $token = config('prism-browser.sidecar.token');
            if (! is_string($token) || strlen($token) < 32) {
                throw new \RuntimeException('PRISM_BROWSER_TOKEN must contain at least 32 characters.');
            }

            return new PlaywrightSidecarEngine(
                $app->make(Factory::class),
                $app->make(BrowserPolicy::class),
                (string) config('prism-browser.sidecar.url'),
                $token,
                (int) config('prism-browser.sidecar.timeout', 30),
            );
        });
        $this->app->singleton(BrowserManager::class);
        $this->app->singleton(ObservationGuard::class, fn ($app): ObservationGuard => new ObservationGuard($app->make(BrowserPolicy::class)->maxObservationBytes));
        $this->app->singleton(BrowserToolset::class);
    }

    public function boot(): void
    {
        $this->publishes([dirname(__DIR__).'/config/prism-browser.php' => config_path('prism-browser.php')], 'prism-browser-config');
        if ($this->app->runningInConsole()) {
            $this->commands([ServeCommand::class]);
        }
    }
}
