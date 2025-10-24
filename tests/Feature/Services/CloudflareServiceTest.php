<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JTSmith\Cloudflare\DTOs\WafRuleResult;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\CloudflareApiClient;
use JTSmith\Cloudflare\Services\CloudflareService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CloudflareServiceTest extends TestCase
{
    protected array $validConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validConfig = [
            'api' => [
                'token' => 'test-token-1234567890abcdefghijklmnopqrstuvw',
                'zone_id' => 'a1b2c3d4e5f678901234567890123456',
            ],
            'waf' => [
                'rule_identifier' => 'test-rule',
                'rule_description' => 'Test WAF Rule',
                'rule_action' => 'block',
            ],
            'settings' => [
                'base_url' => 'https://api.cloudflare.com/client/v4',
                'timeout' => 30,
                'retry_attempts' => 3,
                'retry_delay' => 1000,
            ],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_throws_api_exception_for_authentication_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [
                    ['message' => 'Invalid API token', 'code' => 10000],
                ],
            ], 401),
        ]);

        $service = new CloudflareService($this->validConfig);

        try {
            $service->syncRule('test expression');
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isAuthenticationError());
            $this->assertEquals(401, $e->getStatusCode());
        }
    }

    #[Test]
    public function it_throws_api_exception_for_rate_limit_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [
                    ['message' => 'Rate limit exceeded', 'code' => 10001],
                ],
            ], 429, ['Retry-After' => '60']),
        ]);

        $service = new CloudflareService($this->validConfig);

        try {
            $service->syncRule('test expression');
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertStringContainsString('rate limit exceeded', strtolower($e->getMessage()));
            $this->assertTrue($e->isRateLimitError());
            $this->assertEquals(429, $e->getStatusCode());
        }
    }

    #[Test]
    public function it_throws_api_exception_for_zone_configuration_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [
                    ['message' => 'Zone not found', 'code' => 10002],
                ],
            ], 404),
        ]);

        $service = new CloudflareService($this->validConfig);

        try {
            $service->syncRule('test expression');
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertStringContainsString('zone', strtolower($e->getMessage()));
            $this->assertEquals(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function it_creates_new_waf_rule_successfully(): void
    {
        Http::fake([
            '*/firewall/rules' => Http::sequence()
                // First call (no existing rules)
                ->push([
                    'success' => true,
                    'result' => [],
                ])
                // Second call (create rule)
                ->push([
                    'success' => true,
                    'result' => [
                        [
                            'id' => 'rule-123',
                            'filter' => ['id' => 'filter-123'],
                            'description' => 'Test WAF Rule [id:test-rule]',
                        ],
                    ],
                ]),
            '*/filters' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'filter-123', 'expression' => 'test expression'],
                ],
            ]),
        ]);

        $service = new CloudflareService($this->validConfig);
        $result = $service->syncRule('test expression');

        $this->assertInstanceOf(WafRuleResult::class, $result);
        $this->assertEquals('create', $result->action);
        $this->assertEquals('rule-123', $result->ruleId);
        $this->assertEquals('filter-123', $result->filterId);
        $this->assertEquals('test expression', $result->expression);
        $this->assertStringContainsString('Successfully created', $result->message);

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_updates_existing_waf_rule_successfully(): void
    {
        Http::fake([
            '*/firewall/rules*' => Http::sequence()
                // First call (existing rule found)
                ->push([
                    'success' => true,
                    'result' => [
                        [
                            'id' => 'existing-rule-123',
                            'filter' => ['id' => 'existing-filter-123'],
                            'description' => 'Test WAF Rule [id:test-rule]',
                        ],
                    ],
                ])
                // Second call (fetch rule details)
                ->push([
                    'success' => true,
                    'result' => [
                        'id' => 'existing-rule-123',
                        'filter' => [
                            'id' => 'existing-filter-123',
                            'description' => 'Existing filter description',
                        ],
                    ],
                ]),
            '*/filters/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'existing-filter-123',
                    'expression' => 'updated expression',
                ],
            ]),
        ]);

        $service = new CloudflareService($this->validConfig);
        $result = $service->syncRule('updated expression');

        $this->assertInstanceOf(WafRuleResult::class, $result);
        $this->assertEquals('update', $result->action);
        $this->assertEquals('existing-rule-123', $result->ruleId);
        $this->assertEquals('existing-filter-123', $result->filterId);
        $this->assertEquals('updated expression', $result->expression);
        $this->assertStringContainsString('Successfully updated', $result->message);

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_creates_rule_using_api_client(): void
    {
        $mockClient = Mockery::mock(CloudflareApiClient::class);

        $mockClient->shouldReceive('getFirewallRules')
            ->once()
            ->andReturn([]);

        $mockClient->shouldReceive('createFilters')
            ->once()
            ->with([
                [
                    'expression' => 'test expression',
                    'description' => 'Laravel static cache protection filter',
                ],
            ])
            ->andReturn([
                ['id' => 'filter-123', 'expression' => 'test expression'],
            ]);

        $mockClient->shouldReceive('createFirewallRules')
            ->once()
            ->with(Mockery::on(function ($rules) {
                return is_array($rules)
                    && count($rules) === 1
                    && $rules[0]['filter']['id'] === 'filter-123'
                    && $rules[0]['action'] === 'block';
            }))
            ->andReturn([
                [
                    'id' => 'rule-123',
                    'filter' => ['id' => 'filter-123'],
                ],
            ]);

        $service = new CloudflareService($this->validConfig, $mockClient);
        $result = $service->syncRule('test expression');

        $this->assertInstanceOf(WafRuleResult::class, $result);
        $this->assertEquals('create', $result->action);
        $this->assertEquals('rule-123', $result->ruleId);
        $this->assertEquals('filter-123', $result->filterId);
    }

    #[Test]
    public function it_updates_rule_using_api_client(): void
    {
        $mockClient = Mockery::mock(CloudflareApiClient::class);

        $mockClient->shouldReceive('getFirewallRules')
            ->once()
            ->andReturn([
                [
                    'id' => 'existing-rule-123',
                    'description' => 'Test WAF Rule [id:test-rule]',
                    'filter' => ['id' => 'existing-filter-123'],
                ],
            ]);

        $mockClient->shouldReceive('getFirewallRule')
            ->once()
            ->with('existing-rule-123')
            ->andReturn([
                'id' => 'existing-rule-123',
                'filter' => [
                    'id' => 'existing-filter-123',
                    'description' => 'Existing filter',
                ],
            ]);

        $mockClient->shouldReceive('updateFilter')
            ->once()
            ->with('existing-filter-123', Mockery::on(function ($data) {
                return $data['expression'] === 'updated expression'
                    && $data['id'] === 'existing-filter-123';
            }))
            ->andReturn([
                'id' => 'existing-filter-123',
                'expression' => 'updated expression',
            ]);

        $service = new CloudflareService($this->validConfig, $mockClient);
        $result = $service->syncRule('updated expression');

        $this->assertInstanceOf(WafRuleResult::class, $result);
        $this->assertEquals('update', $result->action);
        $this->assertEquals('existing-rule-123', $result->ruleId);
        $this->assertEquals('existing-filter-123', $result->filterId);
    }

    #[Test]
    public function it_cleans_up_filter_when_rule_creation_fails(): void
    {
        $deleteRequests = [];

        Http::fake(function (Request $request) use (&$deleteRequests) {
            $url = $request->url();

            // Track delete requests
            if ($request->method() === 'DELETE') {
                $deleteRequests[] = $url;

                return Http::response([], 204);
            }

            // Handle GET for existing rules
            if ($request->method() === 'GET' && str_contains($url, 'firewall/rules')) {
                return Http::response(['success' => true, 'result' => []]);
            }

            // Handle POST for filter creation
            if ($request->method() === 'POST' && str_contains($url, 'filters')) {
                return Http::response([
                    'success' => true,
                    'result' => [['id' => 'filter-to-delete']],
                ]);
            }

            // Handle POST for rule creation (fail)
            if ($request->method() === 'POST' && str_contains($url, 'firewall/rules')) {
                return Http::response([
                    'success' => false,
                    'errors' => [['message' => 'Rule creation failed']],
                ], 400);
            }

            return Http::response([], 404);
        });

        $service = new CloudflareService($this->validConfig);

        try {
            $service->syncRule('test expression');
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertStringContainsString('Rule creation failed', $e->getMessage());
        }

        $this->assertCount(1, $deleteRequests);
        $this->assertStringContainsString('filters/filter-to-delete', $deleteRequests[0]);
    }

    #[Test]
    public function it_contains_endpoint_and_method_information(): void
    {
        Http::fake([
            '*/firewall/rules' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Test error', 'code' => 10000]],
            ], 400),
        ]);

        $service = new CloudflareService($this->validConfig);

        try {
            $service->syncRule('test expression');
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertEquals(400, $e->getStatusCode());
            $this->assertStringContainsString('firewall/rules', $e->getEndpoint());
            $this->assertEquals('GET', $e->getMethod());
            $this->assertIsArray($e->getErrorResponse());
        }
    }
}
