<?php

namespace JTSmith\Cloudflare\Commands;

use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Exceptions\CloudflareException;
use JTSmith\Cloudflare\Exceptions\ConfigValidationException;
use JTSmith\Cloudflare\Facades\Purge;

class PurgeCache extends BaseCommand
{
    protected $description = 'Purge Cloudflare cache for specified paths or all content';

    protected $signature = 'cloudflare:purge
                            {paths?* : Paths to purge (relative or full URLs). If omitted, purges all cache}
                            {--route=* : Route names to resolve and purge}
                            {--all : Purge all cached content from Cloudflare}';

    public function handle(): void
    {
        try {
            $this->validateRequiredConfig(['cfcache.api.token', 'cfcache.api.zone_id']);
        } catch (ConfigValidationException $e) {
            $this->fail($e->getMessage());
        }

        if ($this->option('all')) {
            if ($this->confirm('Are you sure you want to purge all cached content from Cloudflare?')) {
                $this->purgeEverything();
            }

            return;
        }

        try {
            $paths = collect($this->argument('paths'))
                ->merge(Purge::resolveRoutes($this->option('route')))
                ->map(fn ($path) => Purge::normalizeUrl($path))
                ->unique()
                ->values();
        } catch (\JTSmith\Cloudflare\Exceptions\RouteNotFoundException $e) {
            $this->error($e->getMessage());

            return;
        }

        if ($paths->isEmpty()) {
            $this->warn('You must specify at least one path or route to purge. Or purge everything with `--all`.');

            return;
        }

        $this->info('Purging specified paths from Cloudflare cache:');
        foreach ($paths as $path) {
            $this->line($path);
        }
        $this->newLine();

        $this->purgePaths($paths->all());
    }

    protected function purgeEverything(): void
    {
        $this->info('Purging all cached content from Cloudflare...');

        try {
            $result = Purge::everything();

            $this->info($result->message);
            $this->line("  Purge ID: {$result->id}");
            $this->newLine();
            $this->info('Cache purge completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->handleApiException($e);
        } catch (CloudflareException $e) {
            $this->handleException($e);
        }
    }

    protected function purgePaths(array $paths): void
    {
        try {
            $result = Purge::url($paths);

            $this->info($result->message);
            $this->line("  Purge ID: {$result->id}");
            $this->line('  Paths purged: '.count($paths));
            $this->newLine();
            $this->info('Cache purge completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->handleApiException($e);
        } catch (CloudflareException $e) {
            $this->handleException($e);
        }
    }

    protected function handleException(\Exception $e): void
    {
        $this->error('Failed to purge cache: '.$e->getMessage());
        $this->newLine();
        $this->warn('An unexpected error occurred. Please check your configuration and try again.');
    }

    protected function handleApiException(CloudflareApiException $e): void
    {
        $this->error('API error: '.$e->getMessage());
        $this->newLine();

        if ($e->isAuthenticationError()) {
            $this->warn('Authentication failed. Please verify:');
            $this->line('  - Your API token is valid and not expired');
            $this->line('  - The token has "Zone:Cache Purge:Edit" permission');
            $this->line('  - The Zone ID matches your domain');
        } elseif ($e->isRateLimitError()) {
            $this->warn('Rate limit exceeded. Please wait a moment and try again.');
        } else {
            $this->warn('Please check the error message above and verify your Cloudflare settings.');
        }

        if ($e->getStatusCode()) {
            $this->line('HTTP Status: '.$e->getStatusCode());
        }
    }
}
