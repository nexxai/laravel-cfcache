<?php

namespace JTSmith\Cloudflare;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
use JTSmith\Cloudflare\Services\Cloudflare\WafRuleService;

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

            // Publish configuration file
            $this->publishes([
                __DIR__.'/../config/cfcache.php' => config_path('cfcache.php'),
            ], 'cfcache-config');
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/cfcache.php',
            'cfcache'
        );

        // Register the WAF rule service
        $this->app->singleton(WafRuleService::class, function ($app) {
            return new WafRuleService($app['config']->get('cfcache', []));
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            GenerateWafRule::class,
            WafRuleService::class,
        ];
    }
}
