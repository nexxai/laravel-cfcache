<?php

namespace JTSmith\Cloudflare\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;

/**
 * HTTP client for interacting with the Cloudflare API.
 */
class CloudflareApiClient
{
    /**
     * The Cloudflare API token.
     */
    protected string $apiToken;

    /**
     * Configuration options for the HTTP client.
     */
    protected array $options;

    /**
     * The base URL for the Cloudflare API.
     */
    protected string $baseUrl;

    /**
     * The Cloudflare zone ID.
     */
    protected string $zoneId;

    /**
     * Create a new CloudflareApiClient instance.
     *
     * @param  string  $apiToken  The Cloudflare API token
     * @param  string  $zoneId  The Cloudflare zone ID
     * @param  array  $options  Optional configuration for the HTTP client
     */
    public function __construct(string $apiToken, string $zoneId, array $options = [])
    {
        $this->zoneId = $zoneId;
        $this->baseUrl = $options['base_url'] ?? 'https://api.cloudflare.com/client/v4';

        $this->initializeClient($apiToken, $options);
    }

    /**
     * Initialize the HTTP client with Cloudflare API configuration. Done lazily to allow for testing flexability.
     *
     * @param  string  $apiToken  The Cloudflare API token
     * @param  array  $options  Configuration options for the HTTP client
     */
    protected function initializeClient(string $apiToken, array $options): void
    {
        $this->apiToken = $apiToken;
        $this->options = $options;
    }

    /**
     * Get the HTTP client instance.
     */
    protected function getClient(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->apiToken}",
            'Content-Type' => 'application/json',
        ])
            ->baseUrl($this->baseUrl)
            ->timeout($this->options['timeout'] ?? 30)
            ->retry(
                $this->options['retry_attempts'] ?? 3,
                $this->options['retry_delay'] ?? 1000
            );
    }

    /**
     * Perform a GET request to the Cloudflare API.
     *
     * @param  string  $endpoint  The API endpoint (relative to base URL)
     * @param  array  $query  Optional query parameters
     * @return Response The HTTP response
     *
     * @throws CloudflareApiException If the request fails
     */
    public function get(string $endpoint, array $query = []): Response
    {
        try {
            $response = $this->getClient()->get($endpoint, $query);
        } catch (RequestException $e) {
            if ($e->response) {
                $this->handleApiError($e->response, 'GET request failed', $endpoint, 'GET');
            }
            throw CloudflareApiException::fromResponse(
                $e->response,
                'GET request failed',
                $endpoint,
                'GET'
            );
        }

        if (! $response->successful()) {
            $this->handleApiError($response, 'GET request failed', $endpoint, 'GET');
        }

        return $response;
    }

    /**
     * Perform a POST request to the Cloudflare API.
     *
     * @param  string  $endpoint  The API endpoint (relative to base URL)
     * @param  array  $data  The data to send in the request body
     * @return Response The HTTP response
     *
     * @throws CloudflareApiException If the request fails
     */
    public function post(string $endpoint, array $data = []): Response
    {
        try {
            $response = $this->getClient()->post($endpoint, $data);
        } catch (RequestException $e) {
            if ($e->response) {
                $this->handleApiError($e->response, 'POST request failed', $endpoint, 'POST');
            }
            throw CloudflareApiException::fromResponse(
                $e->response,
                'POST request failed',
                $endpoint,
                'POST'
            );
        }

        if (! $response->successful()) {
            $this->handleApiError($response, 'POST request failed', $endpoint, 'POST');
        }

        return $response;
    }

    /**
     * Perform a PUT request to the Cloudflare API.
     *
     * @param  string  $endpoint  The API endpoint (relative to base URL)
     * @param  array  $data  The data to send in the request body
     * @return Response The HTTP response
     *
     * @throws CloudflareApiException If the request fails
     */
    public function put(string $endpoint, array $data = []): Response
    {
        try {
            $response = $this->getClient()->put($endpoint, $data);
        } catch (RequestException $e) {
            if ($e->response) {
                $this->handleApiError($e->response, 'PUT request failed', $endpoint, 'PUT');
            }
            throw CloudflareApiException::fromResponse(
                $e->response,
                'PUT request failed',
                $endpoint,
                'PUT'
            );
        }

        if (! $response->successful()) {
            $this->handleApiError($response, 'PUT request failed', $endpoint, 'PUT');
        }

        return $response;
    }

    /**
     * Perform a DELETE request to the Cloudflare API.
     *
     * @param  string  $endpoint  The API endpoint (relative to base URL)
     * @return Response The HTTP response
     *
     * @throws CloudflareApiException If the request fails
     */
    public function delete(string $endpoint): Response
    {
        try {
            $response = $this->getClient()->delete($endpoint);
        } catch (RequestException $e) {
            if ($e->response) {
                $this->handleApiError($e->response, 'DELETE request failed', $endpoint, 'DELETE');
            }
            throw CloudflareApiException::fromResponse(
                $e->response,
                'DELETE request failed',
                $endpoint,
                'DELETE'
            );
        }

        if (! $response->successful()) {
            $this->handleApiError($response, 'DELETE request failed', $endpoint, 'DELETE');
        }

        return $response;
    }

    /**
     * Get the configured zone ID.
     *
     * @return string The Cloudflare zone ID
     */
    public function getZoneId(): string
    {
        return $this->zoneId;
    }

    /**
     * Handle API errors from Cloudflare and throw the appropriate exception.
     *
     * @param  Response  $response  HTTP response from the Cloudflare API
     * @param  string  $context  Context string for error messaging
     * @param  string  $endpoint  The API endpoint that was called
     * @param  string  $method  The HTTP method used
     *
     * @throws CloudflareApiException Always throws with appropriate error details
     */
    protected function handleApiError(Response $response, string $context, string $endpoint, string $method): never
    {
        $statusCode = $response->status();
        $errorMessage = $response->json('errors.0.message', 'Unknown error');

        if ($statusCode === 401 || $statusCode === 403) {
            throw CloudflareApiException::authenticationFailed($response);
        }

        if ($statusCode === 429) {
            throw CloudflareApiException::rateLimitExceeded($response);
        }

        if (str_contains(strtolower($errorMessage), 'zone')) {
            throw CloudflareApiException::zoneConfigurationError($response);
        }

        throw CloudflareApiException::fromResponse($response, $context, $endpoint, $method);
    }
}
