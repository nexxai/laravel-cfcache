<?php

namespace Tests\Feature\Commands;

use Illuminate\Support\Facades\Config;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
use JTSmith\Cloudflare\DTOs\WafRuleResult;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Services\Cloudflare\WafRuleService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateWafRuleTest extends TestCase
{
    protected array $validConfig;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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
                    'retry_delay' => 1000,
                ],
            ],
        ];

        Config::set('cfcache', $this->validConfig);
    }

    #[Test]
    public function it_filters_out_ignorable_paths(): void
    {
        Config::set('cfcache.features.waf.ignorable_paths', ['/_dusk/*', '/admin/test']);

        $command = new GenerateWafRule;

        // Mock route list JSON with some routes including ignorable ones
        $json = json_encode([
            ['uri' => '/'],
            ['uri' => 'about'],
            ['uri' => '_dusk/login'],
            ['uri' => 'admin/test'],
            ['uri' => 'api/users'],
        ]);

        $routes = $command->routes($json);

        // Should not contain the ignorable paths
        $this->assertContains('/', $routes);
        $this->assertContains('/about', $routes);
        $this->assertContains('/api/users', $routes);
        $this->assertNotContains('/_dusk/login', $routes);
        $this->assertNotContains('/admin/test', $routes);
    }

    #[Test]
    public function it_uses_default_ignorable_paths_when_none_configured(): void
    {
        Config::set('cfcache.features.waf.ignorable_paths', null); // Simulate the config not being published

        $command = new GenerateWafRule;

        $json = json_encode([
            ['uri' => '/'],
            ['uri' => '_dusk/test'], // Should be ignored by default
        ]);

        $routes = $command->routes($json);

        // Should contain normal routes but not the default ignorable
        $this->assertContains('/', $routes);
        $this->assertNotContains('/_dusk/test', $routes);
    }

    #[Test]
    public function it_fails_when_syncing_and_api_token_is_not_set(): void
    {
        Config::set('cfcache.api.token', null);

        $this->artisan('cloudflare:waf-rule', ['--sync' => true])
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_syncing_and_zone_id_is_not_set(): void
    {
        Config::set('cfcache.api.zone_id', null);

        $this->artisan('cloudflare:waf-rule', ['--sync' => true])
            ->assertFailed();
    }

    // --patch tests

    #[Test]
    public function it_fails_when_patching_and_api_token_is_not_set(): void
    {
        Config::set('cfcache.api.token', null);

        $this->artisan('cloudflare:waf-rule', ['--patch' => true])
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_patching_and_zone_id_is_not_set(): void
    {
        Config::set('cfcache.api.zone_id', null);

        $this->artisan('cloudflare:waf-rule', ['--patch' => true])
            ->assertFailed();
    }

    #[Test]
    public function it_merges_existing_paths_when_patch_flag_is_used(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect(['/existing-path', '/legacy/*']));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--patch' => true])
            ->expectsOutputToContain('Fetching existing WAF rule paths from Cloudflare')
            ->expectsOutputToContain('Found 2 existing paths in current WAF rule')
            ->assertSuccessful();
    }

    #[Test]
    public function it_warns_and_continues_when_patch_finds_no_existing_rule(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect());

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--patch' => true])
            ->expectsOutputToContain('No existing WAF rule found')
            ->assertSuccessful();
    }

    #[Test]
    public function it_continues_with_fresh_paths_when_patch_api_call_fails(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andThrow(new CloudflareApiException(
                'Authentication failed',
                401,
                null,
                401,
                [],
                'zones/zone_id/firewall/rules',
                'GET'
            ));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--patch' => true])
            ->expectsOutputToContain('Could not fetch existing rule')
            ->assertSuccessful();
    }

    // --check tests

    #[Test]
    public function it_fails_when_checking_and_api_token_is_not_set(): void
    {
        Config::set('cfcache.api.token', null);

        $this->artisan('cloudflare:waf-rule', ['--check' => true])
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_checking_and_zone_id_is_not_set(): void
    {
        Config::set('cfcache.api.zone_id', null);

        $this->artisan('cloudflare:waf-rule', ['--check' => true])
            ->assertFailed();
    }

    #[Test]
    public function it_reports_missing_paths_when_checking_against_live_rule(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect(['/about', '/contact']));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--check' => true])
            ->expectsOutputToContain('Fetching existing WAF rule paths from Cloudflare')
            ->expectsOutputToContain('path(s) in your application are missing from the live Cloudflare WAF rule')
            ->assertSuccessful();
    }

    #[Test]
    public function it_reports_all_paths_present_when_nothing_is_missing(): void
    {
        // Suppress all testbench-specific routes/files so routes() returns empty.
        // An empty diff against any non-empty existing set → "all present" message.
        Config::set('cfcache.features.waf.ignorable_paths', ['/_dusk/*', '/_workbench/*', '/.gitignore']);

        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect(['/about', '/contact']));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--check' => true])
            ->expectsOutputToContain('All generated paths are already present')
            ->assertSuccessful();
    }

    #[Test]
    public function it_warns_when_check_finds_no_existing_rule(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect());

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--check' => true])
            ->expectsOutputToContain('No existing WAF rule found in Cloudflare')
            ->assertSuccessful();
    }

    #[Test]
    public function it_does_not_output_the_expression_when_check_flag_is_used(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect(['/about']));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--check' => true])
            ->doesntExpectOutputToContain('Generated Cloudflare WAF rule')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_when_check_api_call_fails(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andThrow(new CloudflareApiException(
                'Unauthorized',
                401,
                null,
                401,
                [],
                'zones/zone_id/firewall/rules',
                'GET'
            ));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--check' => true])
            ->expectsOutputToContain('Could not fetch existing rule')
            ->assertFailed();
    }

    #[Test]
    public function it_warns_when_patch_and_check_are_used_together(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect(['/about']));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--patch' => true, '--check' => true])
            ->expectsOutputToContain('--patch and --check cannot be used together')
            ->assertSuccessful();
    }

    #[Test]
    public function it_merges_and_syncs_when_patch_and_sync_are_used_together(): void
    {
        $mockService = Mockery::mock(WafRuleService::class);
        $mockService->shouldReceive('fetchCurrentPaths')
            ->once()
            ->andReturn(collect(['/existing-path']));

        $mockService->shouldReceive('syncRule')
            ->once()
            ->andReturn(new WafRuleResult(
                action: 'Updated',
                ruleId: 'rule-123',
                filterId: 'filter-456',
                expression: '',
                message: 'WAF rule updated successfully',
            ));

        $this->app->instance(WafRuleService::class, $mockService);

        $this->artisan('cloudflare:waf-rule', ['--patch' => true, '--sync' => true])
            ->expectsOutputToContain('Fetching existing WAF rule paths from Cloudflare')
            ->expectsOutputToContain('Found 1 existing paths in current WAF rule')
            ->expectsOutputToContain('Syncing WAF rule to Cloudflare')
            ->assertSuccessful();
    }
}
