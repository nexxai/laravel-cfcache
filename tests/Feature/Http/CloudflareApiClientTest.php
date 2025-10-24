<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\CloudflareApiClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CloudflareApiClientTest extends TestCase
{
    protected CloudflareApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new CloudflareApiClient(
            'test-token-1234567890',
            'test-zone-id',
            [
                'base_url' => 'https://api.cloudflare.com/client/v4',
                'timeout' => 30,
                'retry_attempts' => 3,
                'retry_delay' => 1000,
            ]
        );
    }

    #[Test]
    public function it_makes_get_requests_with_correct_headers(): void
    {
        Http::fake();

        $response = $this->client->get('zones/test-zone-id/firewall/rules');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-token-1234567890')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones/test-zone-id/firewall/rules'
                && $request->method() === 'GET';
        });

        $this->assertTrue($response->successful());
    }

    #[Test]
    public function it_makes_post_requests_with_data(): void
    {
        $testData = ['action' => 'block', 'description' => 'Test rule'];

        Http::fake();

        $response = $this->client->post('zones/test-zone-id/firewall/rules', $testData);

        Http::assertSent(function (Request $request) use ($testData) {
            return $request->method() === 'POST'
                && $request->data() === $testData;
        });

        $this->assertTrue($response->successful());
    }

    #[Test]
    public function it_makes_put_requests_with_data(): void
    {
        $testData = ['expression' => 'test expression'];

        Http::fake();

        $response = $this->client->put('zones/test-zone-id/filters/filter-123', $testData);

        Http::assertSent(function (Request $request) use ($testData) {
            return $request->method() === 'PUT'
                && $request->data() === $testData;
        });

        $this->assertTrue($response->successful());
    }

    #[Test]
    public function it_makes_delete_requests(): void
    {
        Http::fake([
            '*' => Http::response([], 204),
        ]);

        $response = $this->client->delete('zones/test-zone-id/filters/filter-123');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones/test-zone-id/filters/filter-123';
        });

        $this->assertEquals(204, $response->status());
    }

    #[Test]
    public function it_throws_authentication_exception_for_401_responses(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid API token']],
            ], 401),
        ]);

        $this->expectException(CloudflareApiException::class);

        try {
            $this->client->get('zones/test-zone-id/firewall/rules');
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isAuthenticationError());
            $this->assertEquals(401, $e->getStatusCode());

            $message = $e->getMessage();
            $this->assertStringContainsString('authentication error', strtolower($message));
            throw $e;
        }
    }

    #[Test]
    public function it_throws_authentication_exception_for_403_responses(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Forbidden']],
            ], 403),
        ]);

        $this->expectException(CloudflareApiException::class);

        try {
            $this->client->get('zones/test-zone-id/firewall/rules');
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isAuthenticationError());
            $this->assertEquals(403, $e->getStatusCode());
            throw $e;
        }
    }

    #[Test]
    public function it_throws_rate_limit_exception_for_429_responses(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Rate limit exceeded']],
            ], 429, ['Retry-After' => '60']),
        ]);

        $this->expectException(CloudflareApiException::class);

        try {
            $this->client->get('zones/test-zone-id/firewall/rules');
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isRateLimitError());
            $this->assertEquals(429, $e->getStatusCode());
            $this->assertStringContainsString('rate limit', strtolower($e->getMessage()));
            throw $e;
        }
    }

    #[Test]
    public function it_throws_zone_configuration_exception_for_zone_errors(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Zone not found']],
            ], 404),
        ]);

        $this->expectException(CloudflareApiException::class);

        try {
            $this->client->get('zones/test-zone-id/firewall/rules');
        } catch (CloudflareApiException $e) {
            $this->assertEquals(404, $e->getStatusCode());
            $this->assertStringContainsString('zone', strtolower($e->getMessage()));
            throw $e;
        }
    }

    #[Test]
    public function it_returns_parsed_result(): void
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
    public function it_returns_single_rule(): void
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
    public function it_returns_created_rules(): void
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
    public function it_returns_created_filters(): void
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
    public function it_returns_updated_filter(): void
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
    public function it_sends_delete_request(): void
    {
        Http::fake([
            '*' => Http::response([], 204),
        ]);

        $this->client->deleteFilter('filter-123');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'filters/filter-123')
                && $request->method() === 'DELETE';
        });
    }

    #[Test]
    public function it_includes_endpoint_and_method_information(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Test error', 'code' => 10000]],
            ], 400),
        ]);

        try {
            $this->client->post('zones/test-zone-id/firewall/rules', ['test' => 'data']);
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertEquals(400, $e->getStatusCode());
            $this->assertEquals('zones/test-zone-id/firewall/rules', $e->getEndpoint());
            $this->assertEquals('POST', $e->getMethod());
            $this->assertIsArray($e->getErrorResponse());
        }
    }
}
