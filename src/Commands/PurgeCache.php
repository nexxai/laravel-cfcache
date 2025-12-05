<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Exceptions\CloudflareException;
use JTSmith\Cloudflare\Services\Cloudflare\CachePurgeService;

class PurgeCache extends Command
{
    protected $description = 'Purge Cloudflare cache for specified paths or all content';

    protected $signature = 'cloudflare:purge
                            {paths?* : Paths to purge (relative or full URLs). If omitted, purges all cache}
                            {--route=* : Route names to resolve and purge}
                            {--all : Purge all cached content from Cloudflare}';

    public function handle(): void
    {
        if ($this->option('all')) {
            if ($this->confirm('Are you sure you want to purge all cached content from Cloudflare?')) {
                $this->purgeAll();
            }

            return;
        }

        $paths = collect($this->argument('paths'))
            ->merge($this->resolveRoutes($this->option('route')))
            ->map(fn ($path) => $this->processPaths($path))
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            $this->warn('You must specify a path or route to purge. Or purge everything with `--all`.');

            return;
        }

        $this->info('Purging specified paths from Cloudflare cache:');
        foreach ($paths as $path) {
            $this->line($path);
        }
        $this->newLine();
        $this->purgePaths($paths->all());
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
