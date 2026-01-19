<?php

namespace JTSmith\Cloudflare\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use JTSmith\Cloudflare\DTOs\CachePurgeResult;
use JTSmith\Cloudflare\Services\Cloudflare\CachePurgeService;

class Purge
{
    public function __construct(
        protected CachePurgeService $service
    ) {}

    /**
     * Purge specific URLs from the Cloudflare cache.
     */
    public function url(string|array $urls): CachePurgeResult
    {
        $urls = collect((array) $urls)
            ->map(fn ($path) => $this->normalizeUrl($path))
            ->unique()
            ->values()
            ->all();

        if (empty($urls)) {
            return new CachePurgeResult(id: '', message: 'No URLs provided to purge.');
        }

        return $this->service->purgeCache($urls);
    }

    /**
     * Purge specific routes from the Cloudflare cache.
     */
    public function route(string|array $names): CachePurgeResult
    {
        $urls = $this->resolveRoutes((array) $names);

        return $this->url($urls);
    }

    /**
     * Purge everything from the Cloudflare cache.
     */
    public function everything(): CachePurgeResult
    {
        return $this->service->purgeCache(null);
    }

    public function normalizeUrl(string $path): string
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
}
