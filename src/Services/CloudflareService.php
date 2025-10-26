<?php

namespace JTSmith\Cloudflare\Services;

use InvalidArgumentException;
use JTSmith\Cloudflare\Http\CloudflareApiClient;

/**
 * Abstract service class for all Cloudflare services.
 */
abstract class CloudflareService
{
    /**
     * The Cloudflare API client for HTTP communication.
     */
    protected CloudflareApiClient $apiClient;

    /**
     * Configuration array containing settings.
     */
    protected array $config;

    /**
     * Create a new CloudflareService instance.
     *
     * @param  array  $config  Optional configuration array. If not provided, it will load from Laravel config.
     * @param  CloudflareApiClient|null  $apiClient  Optional API client instance for dependency injection
     */
    public function __construct(array $config = [], ?CloudflareApiClient $apiClient = null)
    {
        $this->config = empty($config) ? $this->loadConfiguration() : $config;

        if ($apiClient === null) {
            $this->apiClient = $this->createApiClient();
        } else {
            $this->apiClient = $apiClient;
        }
    }

    /**
     * Load configuration from Laravel config files.
     */
    protected function loadConfiguration(): array
    {
        return config('cfcache', []);
    }

    /**
     * Create the appropriate API client for this service.
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
     * Get the API token from configuration.
     *
     * @throws InvalidArgumentException If a token is not configured
     */
    protected function getApiToken(): string
    {
        $token = data_get($this->config, 'api.token');

        if (empty($token)) {
            throw new InvalidArgumentException('Cloudflare API token is required. Please set CF_API_TOKEN in your environment.');
        }

        return $token;
    }

    /**
     * Get Zone ID from configuration.
     *
     * @throws InvalidArgumentException If zone ID is not configured
     */
    protected function getZoneId(): string
    {
        $zoneId = data_get($this->config, 'api.zone_id');

        if (empty($zoneId)) {
            throw new InvalidArgumentException('Cloudflare Zone ID is required. Please set CF_ZONE_ID in your environment.');
        }

        return $zoneId;
    }

    /**
     * Get HTTP client options.
     */
    protected function getHttpOptions(): array
    {
        $settings = data_get($this->config, 'api.settings', []);

        return [
            'base_url' => $settings['base_url'] ?? 'https://api.cloudflare.com/client/v4',
            'timeout' => $settings['timeout'] ?? 30,
            'retry_attempts' => $settings['retry_attempts'] ?? 3,
            'retry_delay' => $settings['retry_delay'] ?? 1000,
        ];
    }

    /**
     * Get configuration value with optional default.
     *
     * @param  string  $key  The configuration key (dot notation supported)
     * @param  mixed  $default  The default value if the key doesn't exist
     * @return mixed The configuration value
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
