<?php

namespace Tests\Feature\Commands;

use Illuminate\Support\Facades\Config;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateWafRuleTest extends TestCase
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
}
