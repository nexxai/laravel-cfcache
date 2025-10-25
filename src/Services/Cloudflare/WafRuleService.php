<?php

namespace JTSmith\Cloudflare\Services\Cloudflare;

use JTSmith\Cloudflare\DTOs\WafRuleResult;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\Clients\WafApiClient;
use JTSmith\Cloudflare\Http\CloudflareApiClient;
use JTSmith\Cloudflare\Services\CloudflareService;

/**
 * Service for managing Cloudflare WAF rules.
 */
class WafRuleService extends CloudflareService
{
    /**
     * The specialized WAF API client.
     *
     * @var WafApiClient
     */
    protected CloudflareApiClient $apiClient;

    /**
     * Create the appropriate API client for WAF operations.
     */
    protected function createApiClient(): CloudflareApiClient
    {
        return new WafApiClient(
            $this->getApiToken(),
            $this->getZoneId(),
            $this->getHttpOptions()
        );
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

        return $rules->first(function ($rule) use ($identifier) {
            $description = $rule['description'] ?? '';

            return str_contains($description, "[id:{$identifier}]");
        });
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
                'action' => $this->getRuleAction(),
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
        } catch (CloudflareApiException) {
            // Silently ignore deletion errors to avoid cascading failures.
        }
    }

    /**
     * Get the WAF rule identifier from the configuration.
     *
     * @return string The WAF rule identifier
     */
    protected function getRuleIdentifier(): string
    {
        return $this->getConfig('features.waf.rule_identifier', 'laravel-waf-rule');
    }

    /**
     * Get the WAF rule action from configuration.
     *
     * @return string The WAF rule action (block, challenge, etc.)
     */
    protected function getRuleAction(): string
    {
        return $this->getConfig('features.waf.rule_action', 'block');
    }

    /**
     * Generate a description for a WAF rule with the given identifier.
     *
     * @param  string  $identifier  The rule identifier to include in the description
     * @return string The formatted description
     */
    protected function generateRuleDescription(string $identifier): string
    {
        $baseDescription = $this->getConfig('features.waf.rule_description', 'Valid Laravel Routes');

        return "{$baseDescription} [id:{$identifier}]";
    }
}
