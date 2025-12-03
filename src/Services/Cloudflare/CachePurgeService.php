<?php

namespace JTSmith\Cloudflare\Services\Cloudflare;

use JTSmith\Cloudflare\DTOs\CachePurgeResult;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\CloudflareApiClient;
use JTSmith\Cloudflare\Services\CloudflareService;

/**
 * Service for purging Cloudflare cache.
 */
class CachePurgeService extends CloudflareService
{
    /**
     * The Cloudflare API client.
     */
    protected CloudflareApiClient $apiClient;

    /**
     * Create the Cloudflare API client.
     */
    protected function createApiClient(): CloudflareApiClient
    {
        return new CloudflareApiClient(
            $this->getApiToken(),
            $this->getZoneId(),
            $this->getHttpOptions()
        );
    }

    /**
     * Purge the Cloudflare cache.
     *
     * @param  array|null  $paths  Optional list of paths to purge. If null or empty, purges everything.
     * @return CachePurgeResult Result object containing the purge ID and message
     *
     * @throws CloudflareApiException If the API request fails
     */
    public function purgeCache(?array $paths = null): CachePurgeResult
    {
        if (empty($paths)) {
            $data = ['purge_everything' => true];
            $message = 'Successfully purged all cached content';
        } else {
            $data = ['files' => $paths];
            $message = 'Successfully purged specified cached content';
        }

        $response = $this->apiClient->post("zones/{$this->apiClient->getZoneId()}/purge_cache", $data);
        $result = $response->json('result');

        return new CachePurgeResult(
            id: $result['id'],
            message: $message
        );
    }
}
