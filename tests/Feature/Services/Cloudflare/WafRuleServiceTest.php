<?php

namespace Tests\Feature\Services\Cloudflare;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
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
    public function it_syncs_domain_rules_idempotently_and_removes_obsolete_managed_rules_when_switching_modes(): void
    {
        $ruleset = [
            'id' => 'ruleset-1',
            'rules' => [
                ['id' => 'legacy', 'description' => 'Test WAF Rule [id:test-rule]'],
                ['id' => 'unrelated', 'description' => 'Other rule [id:test-rule-other]', 'expression' => 'untouched'],
            ],
        ];
        $nextId = 1;

        Http::fake(function (Request $request) use (&$ruleset, &$nextId) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            if ($request->method() === 'POST') {
                $ruleset['rules'][] = ['id' => 'created-'.$nextId++] + $request->data();
            }

            if ($request->method() === 'PATCH') {
                $ruleset['rules'] = array_map(fn (array $rule) => $rule['id'] === $id ? array_replace($rule, $request->data()) : $rule, $ruleset['rules']);
            }

            if ($request->method() === 'DELETE') {
                $ruleset['rules'] = array_values(array_filter($ruleset['rules'], fn (array $rule) => $rule['id'] !== $id));
            }

            return Http::response(['success' => true, 'result' => $ruleset]);
        });

        $json = file_get_contents(__DIR__.'/../../../Fixtures/routes/domains.json');
        $command = new GenerateWafRule;
        $expressions = $command->rules($json)->all();
        $service = new WafRuleService($this->validConfig);
        $created = $service->syncRules($expressions);

        $this->assertCount(count($expressions), $created);
        $this->assertCount(count($expressions) + 1, $ruleset['rules']);
        $this->assertNotContains('legacy', array_column($ruleset['rules'], 'id'));

        foreach ($created as $result) {
            $this->assertSame('create', $result->action);
            $this->assertNotEmpty($result->ruleId);
        }

        $updated = $service->syncRules(array_reverse($expressions, true));
        $this->assertSame(array_reverse(array_column($created, 'ruleId')), array_column($updated, 'ruleId'));
        $this->assertSame(['update'], array_unique(array_column($updated, 'action')));

        $combined = $command->rules($json, singleRule: true)->all();
        $service->syncRules($combined);
        $this->assertCount(2, $ruleset['rules']);
        $this->assertSame('untouched', $ruleset['rules'][0]['expression']);
        $this->assertSame('Test WAF Rule [id:test-rule]', $ruleset['rules'][1]['description']);
        $this->assertSame($combined[''], $ruleset['rules'][1]['expression']);

        // Returning to per-host mode must remove the old combined blocker too.
        $service->syncRules($expressions);
        $this->assertCount(count($expressions) + 1, $ruleset['rules']);
        $this->assertNotContains('Test WAF Rule [id:test-rule]', array_column($ruleset['rules'], 'description'));
        $this->assertSame('untouched', $ruleset['rules'][0]['expression']);
    }

    #[Test]
    public function it_does_not_delete_old_rules_if_a_domain_rule_fails_to_sync(): void
    {
        $ruleset = ['id' => 'ruleset-1', 'rules' => [['id' => 'legacy', 'description' => 'Test [id:test-rule]']]];
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['success' => true, 'result' => $ruleset])
            : Http::response(['success' => false, 'errors' => [['message' => 'Rule limit reached']]], 400));
        $expressions = (new GenerateWafRule)->rules(file_get_contents(__DIR__.'/../../../Fixtures/routes/domains.json'))->all();

        try {
            (new WafRuleService($this->validConfig))->syncRules($expressions);
            $this->fail('Expected a sync failure');
        } catch (CloudflareApiException) {
            Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
        }
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
