<?php

namespace Tests\Feature\Services\Cloudflare;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JTSmith\Cloudflare\DTOs\WafRuleResult;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Http\Clients\WafApiClient;
use JTSmith\Cloudflare\Services\Cloudflare\WafRuleService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WafRuleServiceTest extends TestCase
{
    protected array $validConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validConfig = [
            'api' => [
                'token' => 'test-token-1234567890abcdefghijklmnopqrstuvw',
                'zone_id' => 'a1b2c3d4e5f678901234567890123456',
                'settings' => [
                    'base_url' => 'https://api.cloudflare.com/client/v4',
                    'timeout' => 30,
                    'retry_attempts' => 3,
                    'retry_delay' => 0,
                ],
            ],
            'features' => [
                'waf' => [
                    'rule_identifier' => 'test-rule',
                    'rule_description' => 'Test WAF Rule',
                    'rule_action' => 'block',
                ],
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

        $service = new WafRuleService($this->validConfig);

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

        $service = new WafRuleService($this->validConfig);

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

        $service = new WafRuleService($this->validConfig);

        try {
            $service->syncRule('test expression');
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertStringContainsString('zone', strtolower($e->getMessage()));
            $this->assertEquals(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function it_creates_ruleset_when_none_exists(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404);
            }

            return Http::response([
                'success' => true,
                'result' => [
                    'id' => 'ruleset-1',
                    'rules' => [['id' => 'rule-123', 'description' => 'Test WAF Rule [id:test-rule]']],
                ],
            ]);
        });

        $result = (new WafRuleService($this->validConfig))->syncRule('test expression');

        $this->assertEquals('create', $result->action);
        $this->assertEquals('rule-123', $result->ruleId);
        $this->assertEquals('ruleset-1', $result->rulesetId);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['rules'][0]['expression'] === 'test expression'
            && $request['rules'][0]['action'] === 'block'
            && $request['rules'][0]['description'] === 'Test WAF Rule [id:test-rule]');
    }

    #[Test]
    public function it_adds_rule_to_existing_ruleset(): void
    {
        $mockClient = Mockery::mock(WafApiClient::class);

        $mockClient->shouldReceive('getEntrypointRuleset')
            ->once()
            ->andReturn(['id' => 'ruleset-1', 'rules' => [['id' => 'other', 'description' => 'Unrelated']]]);

        $mockClient->shouldReceive('createRule')
            ->once()
            ->with('ruleset-1', Mockery::on(fn ($rule) => $rule['expression'] === 'test expression'
                && $rule['action'] === 'block'
                && $rule['enabled'] === true))
            ->andReturn([
                'id' => 'ruleset-1',
                'rules' => [['id' => 'rule-123', 'description' => 'Test WAF Rule [id:test-rule]']],
            ]);

        $result = (new WafRuleService($this->validConfig, $mockClient))->syncRule('test expression');

        $this->assertInstanceOf(WafRuleResult::class, $result);
        $this->assertEquals('create', $result->action);
        $this->assertEquals('rule-123', $result->ruleId);
        $this->assertEquals('ruleset-1', $result->rulesetId);
        $this->assertStringContainsString('Successfully created', $result->message);
    }

    #[Test]
    public function it_updates_existing_rule_preserving_its_properties(): void
    {
        $mockClient = Mockery::mock(WafApiClient::class);

        $mockClient->shouldReceive('getEntrypointRuleset')
            ->once()
            ->andReturn([
                'id' => 'ruleset-1',
                'rules' => [[
                    'id' => 'existing-rule-123',
                    'action' => 'managed_challenge',
                    'description' => 'Test WAF Rule [id:test-rule]',
                    'enabled' => false,
                ]],
            ]);

        $mockClient->shouldReceive('updateRule')
            ->once()
            ->with('ruleset-1', 'existing-rule-123', [
                'action' => 'managed_challenge',
                'description' => 'Test WAF Rule [id:test-rule]',
                'expression' => 'updated expression',
                'enabled' => false,
            ])
            ->andReturn(['id' => 'ruleset-1']);

        $result = (new WafRuleService($this->validConfig, $mockClient))->syncRule('updated expression');

        $this->assertEquals('update', $result->action);
        $this->assertEquals('existing-rule-123', $result->ruleId);
        $this->assertEquals('ruleset-1', $result->rulesetId);
        $this->assertEquals('updated expression', $result->expression);
        $this->assertStringContainsString('Successfully updated', $result->message);
    }

    #[Test]
    public function it_contains_endpoint_and_method_information(): void
    {
        Http::fake([
            '*/rulesets/*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Test error', 'code' => 10000]],
            ], 400),
        ]);

        $service = new WafRuleService($this->validConfig);

        try {
            $service->syncRule('test expression');
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertEquals(400, $e->getStatusCode());
            $this->assertStringContainsString('rulesets/phases', $e->getEndpoint());
            $this->assertEquals('GET', $e->getMethod());
            $this->assertIsArray($e->getErrorResponse());
        }
    }
}
