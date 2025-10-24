<?php

namespace JTSmith\Cloudflare\Services;

use JTSmith\Cloudflare\DTOs\WafRuleResult;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\CloudflareApiClient;

/**
 * Service class for managing Cloudflare WAF rules.
 */
class CloudflareService
{
    /**
     * The Cloudflare API client for HTTP communication.
     */
    protected CloudflareApiClient $apiClient;

    /**
     * Configuration array containing settings for WAF rules.
     */
    protected array $config;

    /**
     * Create a new CloudflareService instance.
     *
     * @param  array  $config  Optional configuration array. If not provided, will load from Laravel config.
     * @param  CloudflareApiClient|null  $apiClient  Optional API client instance for dependency injection
     */
    public function __construct(array $config = [], ?CloudflareApiClient $apiClient = null)
    {
        $this->config = empty($config) ? config('cf-waf-rule', []) : $config;

        // If no client is provided, create one from configuration
        if ($apiClient === null) {
            $this->apiClient = new CloudflareApiClient(
                data_get($this->config, 'api.token'),
                data_get($this->config, 'api.zone_id'),
                [
                    'base_url' => data_get($this->config, 'settings.base_url'),
                    'timeout' => data_get($this->config, 'settings.timeout'),
                    'retry_attempts' => data_get($this->config, 'settings.retry_attempts'),
                    'retry_delay' => data_get($this->config, 'settings.retry_delay'),
                ]
            );
        } else {
            $this->apiClient = $apiClient;
        }
    }

    /**
     * Synchronize a WAF rule with Cloudflare.
     *
     * @param  string  $expression  The Cloudflare expression to use for the WAF rule
     * @return WafRuleResult Result object containing the action taken and rule details
     *
     * @throws CloudflareApiException If the API request fails
     */
    public function syncRule(string $expression): WafRuleResult
    {
        $existingRule = $this->findExistingRule();

        if ($existingRule) {
            return $this->updateRule($existingRule['id'], $expression);
        }

        return $this->createRule($expression);
    }

    /**
     * Find an existing WAF rule by its identifier.
     *
     * @return array|null The existing rule data if found, null otherwise
     *
     * @throws CloudflareApiException If the API request fails
     */
    protected function findExistingRule(): ?array
    {
        $identifier = $this->getRuleIdentifier();
        $rules = collect($this->apiClient->getFirewallRules());

        $rule = $rules->first(function ($rule) use ($identifier) {
            $description = $rule['description'] ?? '';

            return str_contains($description, "[id:{$identifier}]");
        });

        return $rule;
    }

    /**
     * Create a new WAF rule in Cloudflare.
     *
     * This method creates both a filter (containing the expression) and a firewall rule (containing the action and
     * filter reference). If rule creation fails, it will clean up the created filter to avoid orphaned resources.
     *
     * @param  string  $expression  The Cloudflare expression for the filter
     * @return WafRuleResult Result object containing the created rule details
     *
     * @throws CloudflareApiException If the API request fails
     */
    protected function createRule(string $expression): WafRuleResult
    {
        $filter = $this->createFilter($expression);
        $identifier = $this->getRuleIdentifier();
        $description = $this->generateRuleDescription($identifier);

        $ruleData = [
            [
                'action' => data_get($this->config, 'waf.rule_action', 'block'),
                'description' => $description,
                'filter' => [
                    'id' => $filter['id'],
                ],
                'paused' => false,
            ],
        ];

        try {
            $rules = $this->apiClient->createFirewallRules($ruleData);
            $rule = $rules[0] ?? [];
        } catch (CloudflareApiException $e) {
            if (isset($filter['id'])) {
                $this->deleteFilter($filter['id']);
            }
            throw $e;
        }

        return new WafRuleResult(
            action: 'create',
            ruleId: $rule['id'],
            filterId: $filter['id'],
            expression: $expression,
            message: 'Successfully created new WAF rule'
        );
    }

    /**
     * Update an existing WAF rule in Cloudflare.
     *
     * This method fetches the existing rule to get its filter ID, then updates the filter with the new expression. The
     * rule itself maintains its other properties like action and description.
     *
     * @param  string  $ruleId  The ID of the existing rule to update
     * @param  string  $expression  The new Cloudflare expression for the filter
     * @return WafRuleResult Result object containing the updated rule details
     *
     * @throws CloudflareApiException If the API request fails
     */
    protected function updateRule(string $ruleId, string $expression): WafRuleResult
    {
        $rule = $this->apiClient->getFirewallRule($ruleId);
        $filterId = data_get($rule, 'filter.id');

        $filterData = [
            'id' => $filterId,
            'expression' => $expression,
            'description' => data_get($rule, 'filter.description'),
        ];

        $this->apiClient->updateFilter($filterId, $filterData);

        return new WafRuleResult(
            action: 'update',
            ruleId: $ruleId,
            filterId: $filterId,
            expression: $expression,
            message: 'Successfully updated existing WAF rule'
        );
    }

    /**
     * Create a new filter in Cloudflare.
     *
     * Filters contain the expression logic that determines when a firewall rule should be triggered. This method
     * creates the filter which will then be referenced by a firewall rule.
     *
     * @param  string  $expression  The Cloudflare expression for the filter
     * @return array The created filter data from the API response
     *
     * @throws CloudflareApiException If the API request fails
     */
    protected function createFilter(string $expression): array
    {
        $filterData = [
            'expression' => $expression,
            'description' => 'Laravel static cache protection filter',
        ];

        $filters = $this->apiClient->createFilters([$filterData]);

        return $filters[0] ?? [];
    }

    /**
     * Delete a Cloudflare filter by its ID.
     *
     * @param  string  $filterId  The ID of the filter to delete
     */
    protected function deleteFilter(string $filterId): void
    {
        try {
            $this->apiClient->deleteFilter($filterId);
        } catch (CloudflareApiException $e) {
            // Silently ignore deletion errors to avoid cascading failures.
        }
    }

    /**
     * Get the WAF rule identifier from configuration.
     *
     * @return string The WAF rule identifier, defaults to 'laravel-waf-rule' if not configured
     */
    protected function getRuleIdentifier(): string
    {
        return data_get($this->config, 'waf.rule_identifier', 'laravel-waf-rule');
    }

    /**
     * Generate a description for a WAF rule with the given identifier.
     *
     * @param  string  $identifier  The rule identifier to include in the description
     * @return string The formatted description
     */
    protected function generateRuleDescription(string $identifier): string
    {
        $baseDescription = data_get($this->config, 'waf.rule_description', 'Valid Laravel Routes');

        return "{$baseDescription} [id:{$identifier}]";
    }

    /**
     * Get the API client instance.
     */
    public function getApiClient(): CloudflareApiClient
    {
        return $this->apiClient;
    }
}
