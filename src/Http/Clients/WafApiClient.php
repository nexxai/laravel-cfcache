<?php

namespace JTSmith\Cloudflare\Http\Clients;

use JTSmith\Cloudflare\Http\CloudflareApiClient;

/**
 * API client for WAF specific operations.
 */
class WafApiClient extends CloudflareApiClient
{
    /**
     * Fetch all firewall rules for the configured zone.
     *
     * @return array Array of firewall rule data
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function getFirewallRules(): array
    {
        $response = $this->get("zones/{$this->zoneId}/firewall/rules");

        return $response->json('result', []);
    }

    /**
     * Fetch a specific firewall rule by ID.
     *
     * @param  string  $ruleId  The firewall rule ID
     * @return array The firewall rule data
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function getFirewallRule(string $ruleId): array
    {
        $response = $this->get("zones/{$this->zoneId}/firewall/rules/{$ruleId}");

        return $response->json('result', []);
    }

    /**
     * Create one or more firewall rules.
     *
     * @param  array  $rules  Array of rule definitions
     * @return array The created firewall rules
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function createFirewallRules(array $rules): array
    {
        $response = $this->post("zones/{$this->zoneId}/firewall/rules", $rules);

        return $response->json('result', []);
    }

    /**
     * Update a firewall rule.
     *
     * @param  string  $ruleId  The firewall rule ID to update
     * @param  array  $ruleData  The updated rule data
     * @return array The updated firewall rule
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function updateFirewallRule(string $ruleId, array $ruleData): array
    {
        $response = $this->put("zones/{$this->zoneId}/firewall/rules/{$ruleId}", $ruleData);

        return $response->json('result', []);
    }

    /**
     * Delete a firewall rule.
     *
     * @param  string  $ruleId  The firewall rule ID to delete
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function deleteFirewallRule(string $ruleId): void
    {
        $this->delete("zones/{$this->zoneId}/firewall/rules/{$ruleId}");
    }

    /**
     * Create one or more filters.
     *
     * @param  array  $filters  Array of filter definitions
     * @return array The created filters
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function createFilters(array $filters): array
    {
        $response = $this->post("zones/{$this->zoneId}/filters", $filters);

        return $response->json('result', []);
    }

    /**
     * Get all filters for the configured zone.
     *
     * @return array Array of filter data
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function getFilters(): array
    {
        $response = $this->get("zones/{$this->zoneId}/filters");

        return $response->json('result', []);
    }

    /**
     * Get a specific filter by ID.
     *
     * @param  string  $filterId  The filter ID
     * @return array The filter data
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function getFilter(string $filterId): array
    {
        $response = $this->get("zones/{$this->zoneId}/filters/{$filterId}");

        return $response->json('result', []);
    }

    /**
     * Update a filter.
     *
     * @param  string  $filterId  The filter ID to update
     * @param  array  $filterData  The updated filter data
     * @return array The updated filter
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function updateFilter(string $filterId, array $filterData): array
    {
        $response = $this->put("zones/{$this->zoneId}/filters/{$filterId}", $filterData);

        return $response->json('result', []);
    }

    /**
     * Delete a filter.
     *
     * @param  string  $filterId  The filter ID to delete
     *
     * @throws \JTSmith\Cloudflare\Exceptions\CloudflareApiException If the request fails
     */
    public function deleteFilter(string $filterId): void
    {
        $this->delete("zones/{$this->zoneId}/filters/{$filterId}");
    }
}
