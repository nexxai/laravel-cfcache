<?php

namespace JTSmith\Cloudflare\Exceptions;

use RuntimeException;

/**
 * Base exception class for all Cloudflare-related errors.
 */
class CloudflareException extends RuntimeException
{
    /**
     * HTTP status code from the API response.
     */
    protected ?int $statusCode;

    /**
     * The raw error response from the Cloudflare API.
     */
    protected ?array $errorResponse;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $statusCode = null,
        ?array $errorResponse = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->statusCode = $statusCode;
        $this->errorResponse = $errorResponse;
    }

    /**
     * Get the HTTP status code from the API response.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Get the raw error response from the API.
     */
    public function getErrorResponse(): ?array
    {
        return $this->errorResponse;
    }

    /**
     * Check if this is an authentication error (401 or 403).
     */
    public function isAuthenticationError(): bool
    {
        return in_array($this->statusCode, [401, 403]);
    }

    /**
     * Check if this is a rate limit error (429).
     */
    public function isRateLimitError(): bool
    {
        return $this->statusCode === 429;
    }

    /**
     * Create a CloudflareException from an API error response.
     */
    public static function fromApiError(
        string $message,
        int $statusCode,
        ?array $errorResponse = null
    ): static {
        return new static(
            message: $message,
            code: $statusCode,
            statusCode: $statusCode,
            errorResponse: $errorResponse
        );
    }
}
