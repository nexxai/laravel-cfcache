<?php

namespace Tests\Feature\Http\Clients;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\Clients\WafApiClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WafApiClientTest extends TestCase
{
    protected WafApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new WafApiClient('test-api-token', 'test-zone-id', [
            'base_url' => 'https://api.cloudflare.com/client/v4',
            'timeout' => 30,
            'retry_attempts' => 0, // Disable retries for tests
        ]);
    }

    #[Test]
    public function it_returns_parsed_firewall_rules(): void
    {
        $expectedRules = [
            ['id' => 'rule-1', 'description' => 'Rule 1'],
            ['id' => 'rule-2', 'description' => 'Rule 2'],
        ];

        Http::fake([
            '*' => Http::response(['success' => true, 'result' => $expectedRules], 200),
        ]);

        $rules = $this->client->getFirewallRules();

        $this->assertEquals($expectedRules, $rules);
    }

    #[Test]
    public function it_returns_single_firewall_rule(): void
    {
        $expectedRule = ['id' => 'rule-123', 'description' => 'Test Rule'];

        Http::fake([
            '*' => Http::response(['success' => true, 'result' => $expectedRule], 200),
        ]);

        $rule = $this->client->getFirewallRule('rule-123');

        $this->assertEquals($expectedRule, $rule);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'firewall/rules/rule-123');
        });
    }

    #[Test]
    public function it_creates_firewall_rules(): void
    {
        $rulesToCreate = [
            ['action' => 'block', 'description' => 'Rule 1'],
        ];
        $expectedResult = [
            ['id' => 'new-rule-1', 'action' => 'block', 'description' => 'Rule 1'],
        ];

        Http::fake([
            '*' => Http::response(['success' => true, 'result' => $expectedResult], 200),
        ]);

        $result = $this->client->createFirewallRules($rulesToCreate);

        $this->assertEquals($expectedResult, $result);
    }

    #[Test]
    public function it_creates_filters(): void
    {
        $filtersToCreate = [
            ['expression' => 'test expression', 'description' => 'Test filter'],
        ];
        $expectedResult = [
            ['id' => 'filter-1', 'expression' => 'test expression', 'description' => 'Test filter'],
        ];

        Http::fake([
            '*' => Http::response(['success' => true, 'result' => $expectedResult], 200),
        ]);

        $result = $this->client->createFilters($filtersToCreate);

        $this->assertEquals($expectedResult, $result);
    }

    #[Test]
    public function it_updates_filter(): void
    {
        $filterData = [
            'id' => 'filter-123',
            'expression' => 'updated expression',
            'description' => 'Updated filter',
        ];

        Http::fake([
            '*' => Http::response(['success' => true, 'result' => $filterData], 200),
        ]);

        $result = $this->client->updateFilter('filter-123', $filterData);

        $this->assertEquals($filterData, $result);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'filters/filter-123')
                && $request->method() === 'PUT';
        });
    }

    #[Test]
    public function it_deletes_filter(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 204),
        ]);

        $this->client->deleteFilter('filter-123');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'filters/filter-123')
                && $request->method() === 'DELETE';
        });
    }

    #[Test]
    public function it_gets_all_filters(): void
    {
        $expectedFilters = [
            ['id' => 'filter-1', 'expression' => 'expr1'],
            ['id' => 'filter-2', 'expression' => 'expr2'],
        ];

        Http::fake([
            '*' => Http::response(['success' => true, 'result' => $expectedFilters], 200),
        ]);

        $filters = $this->client->getFilters();

        $this->assertEquals($expectedFilters, $filters);
    }

    #[Test]
    public function it_gets_single_filter(): void
    {
        $expectedFilter = ['id' => 'filter-123', 'expression' => 'test expr'];

        Http::fake([
            '*' => Http::response(['success' => true, 'result' => $expectedFilter], 200),
        ]);

        $filter = $this->client->getFilter('filter-123');

        $this->assertEquals($expectedFilter, $filter);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'filters/filter-123');
        });
    }

    #[Test]
    public function it_handles_authentication_errors(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid authentication credentials']],
            ], 401),
        ]);

        $this->expectException(CloudflareApiException::class);

        try {
            $this->client->getFirewallRules();
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isAuthenticationError());
            $this->assertEquals(401, $e->getStatusCode());
            throw $e;
        }
    }

    #[Test]
    public function it_handles_rate_limit_errors(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Rate limit exceeded']],
            ], 429),
        ]);

        $this->expectException(CloudflareApiException::class);

        try {
            $this->client->getFirewallRules();
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isRateLimitError());
            $this->assertEquals(429, $e->getStatusCode());
            throw $e;
        }
    }
}
