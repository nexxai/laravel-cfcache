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
    public function it_gets_the_entrypoint_ruleset(): void
    {
        $ruleset = ['id' => 'rs-1', 'rules' => [['id' => 'r-1']]];

        Http::fake(['*' => Http::response(['success' => true, 'result' => $ruleset], 200)]);

        $this->assertEquals($ruleset, $this->client->getEntrypointRuleset());

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/test-zone-id/rulesets/phases/http_request_firewall_custom/entrypoint');
    }

    #[Test]
    public function it_returns_null_when_no_entrypoint_exists(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'errors' => [['message' => 'could not find entrypoint ruleset', 'code' => 10003]],
        ], 404)]);

        $this->assertNull($this->client->getEntrypointRuleset());
    }

    #[Test]
    public function it_creates_the_entrypoint_ruleset(): void
    {
        $rules = [['action' => 'block', 'expression' => 'true']];

        Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'rs-1']], 200)]);

        $this->assertEquals(['id' => 'rs-1'], $this->client->createEntrypointRuleset($rules));

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), 'rulesets/phases/http_request_firewall_custom/entrypoint')
            && $request['rules'] === $rules);
    }

    #[Test]
    public function it_creates_a_rule(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'rs-1']], 200)]);

        $this->client->createRule('rs-1', ['action' => 'block']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), 'zones/test-zone-id/rulesets/rs-1/rules')
            && $request['action'] === 'block');
    }

    #[Test]
    public function it_updates_a_rule(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'rs-1']], 200)]);

        $this->client->updateRule('rs-1', 'r-1', ['expression' => 'new']);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), 'rulesets/rs-1/rules/r-1')
            && $request['expression'] === 'new');
    }

    #[Test]
    public function it_deletes_a_rule(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'rs-1']], 200)]);

        $this->client->deleteRule('rs-1', 'r-1');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), 'rulesets/rs-1/rules/r-1'));
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

        try {
            $this->client->getEntrypointRuleset();
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isAuthenticationError());
            $this->assertEquals(401, $e->getStatusCode());
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

        try {
            $this->client->getEntrypointRuleset();
            $this->fail('Expected CloudflareApiException was not thrown');
        } catch (CloudflareApiException $e) {
            $this->assertTrue($e->isRateLimitError());
            $this->assertEquals(429, $e->getStatusCode());
        }
    }
}
