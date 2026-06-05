<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Exceptions\CloudflareException;
use JTSmith\Cloudflare\Exceptions\ConfigValidationException;
use JTSmith\Cloudflare\Services\Cloudflare\CachePurgeService;
use JTSmith\Cloudflare\Support\ScheduledPurgeStore;
use Throwable;

class PurgeCache extends BaseCommand
{
    protected $description = 'Purge Cloudflare cache for specified paths or all content';

    protected $signature = 'cloudflare:purge
                            {paths?* : Paths to purge (relative or full URLs). If omitted, purges all cache}
                            {--route=* : Route names to resolve and purge}
                            {--prefix=* : URL prefixes to purge}
                            {--host=* : Hosts to purge}
                            {--schedule= : Schedule the purge for a timestamp parseable by Carbon}
                            {--force : Do not prompt for confirmation when purging cache}
                            {--all : Purge all cached content from Cloudflare}';

    public function handle(): void
    {
        try {
            $this->validateRequiredConfig(['cfcache.api.token', 'cfcache.api.zone_id']);
        } catch (ConfigValidationException $e) {
            $this->fail($e->getMessage());
        }

        if (! $this->validatePurgeOptions()) {
            return;
        }

        if ($this->option('schedule')) {
            $this->schedulePurge($this->option('schedule'));

            return;
        }

        if ($this->option('all')) {
            if ($this->option('force') || $this->confirm('Are you sure you want to purge all cached content from Cloudflare?')) {
                $this->purgeAll();
            }

            return;
        }

        if (! empty($this->option('prefix'))) {
            $this->purgePrefixes($this->option('prefix'));

            return;
        }

        if (! empty($this->option('host'))) {
            $this->purgeHosts($this->option('host'));

            return;
        }

        $paths = collect($this->argument('paths'))
            ->merge($this->resolveRoutes($this->option('route')))
            ->map(fn ($path) => $this->processPaths($path))
            ->unique()
            ->values();

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

    protected function schedulePurge(string $timestamp): void
    {
        if (! $this->hasPurgeTarget()) {
            $this->warn('You must specify at least one path or route to purge. Or purge everything with `--all`.');

            return;
        }

        if ($this->option('all') && ! $this->option('force') && ! $this->confirm('Are you sure you want to purge all cached content from Cloudflare?')) {

            return;
        }

        try {
            $runAt = Carbon::parse($timestamp);
        } catch (Throwable) {
            $this->error("Unable to parse schedule timestamp: {$timestamp}");

            return;
        }

        app(ScheduledPurgeStore::class)->add($runAt, $this->getName(), [
            'paths' => $this->argument('paths'),
            '--route' => $this->option('route'),
            '--prefix' => $this->option('prefix'),
            '--host' => $this->option('host'),
            '--force' => (bool) $this->option('force'),
            '--all' => (bool) $this->option('all'),
        ]);

        $this->info('Cloudflare cache purge scheduled for '.$runAt->toDateTimeString().'.');
    }

    public function resolveRoutes(array $routeNames): array
    {
        $resolvedPaths = [];

        foreach ($routeNames as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            if (! $route) {
                // Skip unknown routes silently for now
                continue;
            }

            $uri = $route->uri();

            // Replace parameter placeholders like {id} with * for Cloudflare syntax
            $cleanUri = preg_replace('/\{[^}]+\}/', '*', $uri);
            $cleanUri = preg_replace('/\/\/+/', '/', $cleanUri); // Remove double slashes

            // Ensure it starts with /
            $path = Str::startsWith($cleanUri, '/') ? $cleanUri : '/'.$cleanUri;

            $resolvedPaths[] = $path;
        }

        return array_unique($resolvedPaths);
    }

    protected function validatePurgeOptions(): bool
    {
        $usesUrlOptions = ! empty($this->argument('paths')) || ! empty($this->option('route')) || $this->option('all');
        $usesPrefix = ! empty($this->option('prefix'));
        $usesHost = ! empty($this->option('host'));

        if ($usesPrefix && $usesHost) {
            $this->warn('The `--prefix` and `--host` options cannot be used together.');

            return false;
        }

        if (($usesPrefix || $usesHost) && $usesUrlOptions) {
            $this->warn('The `--prefix` and `--host` options cannot be used with paths, routes, or `--all`.');

            return false;
        }

        return true;
    }

    protected function hasPurgeTarget(): bool
    {
        return $this->option('all')
            || ! empty($this->argument('paths'))
            || ! empty($this->option('route'))
            || ! empty($this->option('prefix'))
            || ! empty($this->option('host'));
    }

    protected function processPaths(string $path): string
    {
        $appUrl = config('app.url');

        // If it starts with http:// or https://, it's a full URL, leave as is
        if (preg_match('/^https?:\/\//', $path)) {
            return $path;
        }

        // Otherwise, it's relative, prefix with app URL
        $baseUrl = rtrim($appUrl, '/');
        $cleanPath = ltrim($path, '/');

        return $baseUrl.'/'.$cleanPath;
    }

    protected function purgeAll(): void
    {
        $this->info('Purging all cached content from Cloudflare...');

        try {
            $service = app(CachePurgeService::class);
            $result = $service->purgeCache();

            $this->info($result->message);
            $this->line("  Purge ID: {$result->id}");
            $this->newLine();
            $this->info('Cache purge completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->handleApiException($e);
        } catch (CloudflareException $e) {
            $this->error('Failed to purge cache: '.$e->getMessage());
            $this->newLine();
            $this->warn('An unexpected error occurred. Please check your configuration and try again.');
        }
    }

    protected function purgePaths(array $paths): void
    {
        try {
            $service = app(CachePurgeService::class);
            $result = $service->purgeCache($paths);

            $this->info($result->message);
            $this->line("  Purge ID: {$result->id}");
            $this->line('  Paths purged: '.count($paths));
            $this->newLine();
            $this->info('Cache purge completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->handleApiException($e);
        } catch (CloudflareException $e) {
            $this->error('Failed to purge cache: '.$e->getMessage());
            $this->newLine();
            $this->warn('An unexpected error occurred. Please check your configuration and try again.');
        }
    }

    protected function purgePrefixes(array $prefixes): void
    {
        $this->info('Purging URL prefixes from Cloudflare cache:');
        foreach ($prefixes as $prefix) {
            $this->line($prefix);
        }
        $this->newLine();

        try {
            $service = app(CachePurgeService::class);
            $result = $service->purgePrefixes($prefixes);

            $this->info($result->message);
            $this->line("  Purge ID: {$result->id}");
            $this->line('  Prefixes purged: '.count($prefixes));
            $this->newLine();
            $this->info('Cache purge completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->handleApiException($e);
        } catch (CloudflareException $e) {
            $this->error('Failed to purge cache: '.$e->getMessage());
            $this->newLine();
            $this->warn('An unexpected error occurred. Please check your configuration and try again.');
        }
    }

    protected function purgeHosts(array $hosts): void
    {
        $this->info('Purging hosts from Cloudflare cache:');
        foreach ($hosts as $host) {
            $this->line($host);
        }
        $this->newLine();

        try {
            $service = app(CachePurgeService::class);
            $result = $service->purgeHosts($hosts);

            $this->info($result->message);
            $this->line("  Purge ID: {$result->id}");
            $this->line('  Hosts purged: '.count($hosts));
            $this->newLine();
            $this->info('Cache purge completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->handleApiException($e);
        } catch (CloudflareException $e) {
            $this->error('Failed to purge cache: '.$e->getMessage());
            $this->newLine();
            $this->warn('An unexpected error occurred. Please check your configuration and try again.');
        }
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
