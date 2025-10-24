<?php

namespace JTSmith\Cloudflare\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * Exception thrown when a Cloudflare API request fails.
 */
class CloudflareApiException extends CloudflareException
{
    /**
     * The API endpoint that was being called.
     */
    protected ?string $endpoint;

    /**
     * The HTTP method used for the request.
     */
    protected ?string $method;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $statusCode = null,
        ?array $errorResponse = null,
        ?string $endpoint = null,
        ?string $method = null
    ) {
        parent::__construct($message, $code, $previous, $statusCode, $errorResponse);
        $this->endpoint = $endpoint;
        $this->method = $method;
    }

    /**
     * Get the API endpoint that was being called.
     */
    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    /**
     * Get the HTTP method used for the request.
     */
    public function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * Create an exception from a failed API response.
     */
    public static function fromResponse(
        Response $response,
        string $context = 'API request failed',
        ?string $endpoint = null,
        ?string $method = null
    ): self {
        $statusCode = $response->status();
        $errorMessage = $response->json('errors.0.message', 'Unknown error');
        $errorCode = $response->json('errors.0.code');

        $message = $context.': '.$errorMessage;

        if ($errorCode) {
            $message .= ' (Code: '.$errorCode.')';
        }

        return new self(
            message: $message,
            code: $statusCode,
            statusCode: $statusCode,
            errorResponse: $response->json(),
            endpoint: $endpoint,
            method: $method
        );
    }

    /**
     * Create an authentication-specific error.
     */
    public static function authenticationFailed(Response $response): self
    {
        $errorMessage = $response->json('errors.0.message', 'Authentication failed');

        return new self(
            message: "Cloudflare authentication error: {$errorMessage}. Please check your API token and permissions.",
            code: $response->status(),
            statusCode: $response->status(),
            errorResponse: $response->json()
        );
    }

    /**
     * Create a zone configuration error.
     */
    public static function zoneConfigurationError(Response $response): self
    {
        $errorMessage = $response->json('errors.0.message', 'Zone configuration error');

        return new self(
            message: "Cloudflare configuration error: {$errorMessage}. Please check your Zone ID.",
            code: $response->status(),
            statusCode: $response->status(),
            errorResponse: $response->json()
        );
    }

    /**
     * Create a rate limit error.
     */
    public static function rateLimitExceeded(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');
        $message = 'Cloudflare API rate limit exceeded';

        if ($retryAfter) {
            $message .= ". Retry after {$retryAfter} seconds";
        }

        return new self(
            message: $message,
            code: 429,
            statusCode: 429,
            errorResponse: $response->json()
        );
    }
}
