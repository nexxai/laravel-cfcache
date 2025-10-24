<?php

namespace JTSmith\Cloudflare;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
use JTSmith\Cloudflare\Services\CloudflareService;

class PageCacheServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateWafRule::class,
            ]);

            // Publish a configuration file
            $this->publishes([
                __DIR__.'/../config/cf-waf-rule.php' => config_path('cf-waf-rule.php'),
            ], 'cf-waf-rule-config');
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cf-waf-rule.php',
            'cf-waf-rule'
        );

        $this->app->singleton(CloudflareService::class, function ($app) {
            return new CloudflareService($app['config']->get('cf-waf-rule', []));
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            GenerateWafRule::class,
            CloudflareService::class,
        ];
    }
}
