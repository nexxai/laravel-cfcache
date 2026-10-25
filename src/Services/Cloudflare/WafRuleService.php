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
        $ruleset = $this->apiClient->getEntrypointRuleset();

        if ($ruleset === null) {
            return $this->createRuleset($expression);
        }

        $existingRule = $this->findExistingRule($ruleset);

        if ($existingRule) {
            return $this->updateRule($ruleset['id'], $existingRule, $expression);
        }

        return $this->createRule($ruleset['id'], $expression);
    }

    /**
     * Find an existing WAF rule in the ruleset by its identifier.
     *
     * @return array|null The existing rule data if found, null otherwise
     */
    protected function findExistingRule(array $ruleset): ?array
    {
        $identifier = $this->getRuleIdentifier();

        return collect($ruleset['rules'] ?? [])->first(function ($rule) use ($identifier) {
            return str_contains($rule['description'] ?? '', "[id:{$identifier}]");
        });
    }

    /**
     * Build the rule payload for a new rule.
     */
    protected function buildRule(string $expression): array
    {
        return [
            'action' => $this->getRuleAction(),
            'description' => $this->generateRuleDescription($this->getRuleIdentifier()),
            'expression' => $expression,
            'enabled' => true,
        ];
    }

    /**
     * Create the zone's custom rules ruleset containing the new rule.
     *
     * @throws CloudflareApiException If the API request fails
     */
    protected function createRuleset(string $expression): WafRuleResult
    {
        $ruleset = $this->apiClient->createEntrypointRuleset([$this->buildRule($expression)]);

        return $this->createdResult($ruleset, $expression);
    }

    /**
     * Add a new rule to an existing ruleset.
     *
     * @throws CloudflareApiException If the API request fails
     */
    protected function createRule(string $rulesetId, string $expression): WafRuleResult
    {
        $ruleset = $this->apiClient->createRule($rulesetId, $this->buildRule($expression));

        return $this->createdResult($ruleset, $expression);
    }

    /**
     * Build the result for a created rule; the API returns the full ruleset, so locate our rule in it.
     */
    protected function createdResult(array $ruleset, string $expression): WafRuleResult
    {
        $rule = $this->findExistingRule($ruleset) ?? [];

        return new WafRuleResult(
            action: 'create',
            ruleId: $rule['id'] ?? '',
            rulesetId: $ruleset['id'] ?? '',
            expression: $expression,
            message: 'Successfully created new WAF rule'
        );
    }

    /**
     * Update the expression of an existing WAF rule, preserving its other properties.
     *
     * @param  array  $rule  The existing rule as returned by the API
     *
     * @throws CloudflareApiException If the API request fails
     */
    protected function updateRule(string $rulesetId, array $rule, string $expression): WafRuleResult
    {
        $this->apiClient->updateRule($rulesetId, $rule['id'], [
            'action' => $rule['action'] ?? $this->getRuleAction(),
            'description' => $rule['description'],
            'expression' => $expression,
            'enabled' => $rule['enabled'] ?? true,
        ]);

        return new WafRuleResult(
            action: 'update',
            ruleId: $rule['id'],
            rulesetId: $rulesetId,
            expression: $expression,
            message: 'Successfully updated existing WAF rule'
        );
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
