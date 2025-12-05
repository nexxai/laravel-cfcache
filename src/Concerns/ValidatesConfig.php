<?php

namespace JTSmith\Cloudflare\Concerns;

use JTSmith\Cloudflare\Exceptions\ConfigValidationException;

trait ValidatesConfig
{
    /**
     * Validate that required configuration keys are set.
     */
    protected function validateRequiredConfig(array $requiredKeys): void
    {
        foreach ($requiredKeys as $key) {
            if (! config($key)) {
                throw new ConfigValidationException("Configuration '{$key}' is required but not set. Please set it in your config or environment variables.");
            }
        }
    }
}
