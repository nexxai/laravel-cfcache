<?php

namespace JTSmith\Cloudflare\Facades;

use Illuminate\Support\Facades\Facade;
use JTSmith\Cloudflare\Services\Purge as PurgeManager;

/**
 * @method static \JTSmith\Cloudflare\DTOs\CachePurgeResult url(string|array $urls)
 * @method static \JTSmith\Cloudflare\DTOs\CachePurgeResult route(string|array $names)
 * @method static \JTSmith\Cloudflare\DTOs\CachePurgeResult everything()
 * @method static string normalizeUrl(string $path)
 * @method static array resolveRoutes(array $routeNames)
 *
 * @see \JTSmith\Cloudflare\Services\Purge
 */
class Purge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PurgeManager::class;
    }
}
