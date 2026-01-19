<?php

namespace JTSmith\Cloudflare;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
use JTSmith\Cloudflare\Commands\PurgeCache;
use JTSmith\Cloudflare\Services\Cloudflare\CachePurgeService;
use JTSmith\Cloudflare\Services\Cloudflare\WafRuleService;
use JTSmith\Cloudflare\Services\Purge;

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
                PurgeCache::class,
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

        // Register the cache purge service
        $this->app->singleton(CachePurgeService::class, function ($app) {
            return new CachePurgeService($app['config']->get('cfcache', []));
        });

        // Register the Purge manager
        $this->app->singleton(Purge::class, function ($app) {
            return new Purge($app->make(CachePurgeService::class));
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            CachePurgeService::class,
            GenerateWafRule::class,
            Purge::class,
            PurgeCache::class,
            WafRuleService::class,
        ];
    }
}
