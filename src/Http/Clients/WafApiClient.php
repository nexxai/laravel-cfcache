<?php

namespace JTSmith\Cloudflare\Http\Clients;

use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\CloudflareApiClient;

/**
 * API client for WAF specific operations, built on the Cloudflare Rulesets API.
 *
 * The legacy Firewall Rules and Filters APIs were removed by Cloudflare (error 10020).
 */
class WafApiClient extends CloudflareApiClient
{
    public const PHASE = 'http_request_firewall_custom';

    /**
     * Fetch the zone's custom rules entrypoint ruleset.
     *
     * @return array|null The ruleset (including its rules), or null if the zone has none yet
     *
     * @throws CloudflareApiException If the request fails for any reason other than a missing entrypoint
     */
    public function getEntrypointRuleset(): ?array
    {
        try {
            $response = $this->get("zones/{$this->zoneId}/rulesets/phases/".self::PHASE.'/entrypoint');
        } catch (CloudflareApiException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        return $response->json('result') ?: null;
    }

    /**
     * Create the entrypoint ruleset with the given rules.
     *
     * @param  array  $rules  Rule definitions
     * @return array The created ruleset
     *
     * @throws CloudflareApiException If the request fails
     */
    public function createEntrypointRuleset(array $rules): array
    {
        $response = $this->put("zones/{$this->zoneId}/rulesets/phases/".self::PHASE.'/entrypoint', [
            'rules' => $rules,
        ]);

        return $response->json('result', []);
    }

    /**
     * Add a rule to an existing ruleset.
     *
     * @return array The updated ruleset, including all rules
     *
     * @throws CloudflareApiException If the request fails
     */
    public function createRule(string $rulesetId, array $rule): array
    {
        $response = $this->post("zones/{$this->zoneId}/rulesets/{$rulesetId}/rules", $rule);

        return $response->json('result', []);
    }

    /**
     * Update a rule in a ruleset.
     *
     * @return array The updated ruleset, including all rules
     *
     * @throws CloudflareApiException If the request fails
     */
    public function updateRule(string $rulesetId, string $ruleId, array $rule): array
    {
        $response = $this->patch("zones/{$this->zoneId}/rulesets/{$rulesetId}/rules/{$ruleId}", $rule);

        return $response->json('result', []);
    }

    /**
     * Delete a rule from a ruleset.
     *
     * @throws CloudflareApiException If the request fails
     */
    public function deleteRule(string $rulesetId, string $ruleId): void
    {
        $this->delete("zones/{$this->zoneId}/rulesets/{$rulesetId}/rules/{$ruleId}");
    }
}
