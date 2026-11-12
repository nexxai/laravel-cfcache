<?php

namespace Tests\Feature\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
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
    public function it_prevents_routes_doesnt_contain_windows_absolute_paths()
    {
        $command = new GenerateWafRule;

        $json = json_encode([
            ['uri' => '/'],
            ['uri' => 'about'],
            ['uri' => 'api/users'],
        ]);

        $routes = $command->routes($json);

        $this->assertContains('/', $routes);
        $this->assertContains('/about', $routes);
        $this->assertContains('/api/users', $routes);

        // Assert no Windows absolute paths leaked in
        $windowsPaths = $routes->filter(fn (string $r) => preg_match('#^/[A-Za-z]:\\\\#', $r));
        $this->assertEmpty(
            $windowsPaths->all(),
            'Routes should not contain Windows absolute paths, but found: '.$windowsPaths->implode(', ')
        );
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
    public function it_includes_forced_allowed_paths_in_the_allowlist(): void
    {
        Config::set('cfcache.features.waf.forced_allowed_paths', ['/cdn-cgi/zaraz/s.js', 'cdn-cgi/monitor/xhr']);

        $command = new GenerateWafRule;

        $json = json_encode([
            ['uri' => '/'],
        ]);

        $routes = $command->routes($json);

        $this->assertContains('/', $routes);
        $this->assertContains('/cdn-cgi/zaraz/s.js', $routes);
        $this->assertContains('/cdn-cgi/monitor/xhr', $routes);
    }

    #[Test]
    #[DataProvider('livewireConfigurations')]
    public function it_only_adds_assets_for_the_configured_livewire_build(bool $cspSafe, bool $debug, string $scriptRoute, array $expectedPaths): void
    {
        Config::set('livewire.csp_safe', $cspSafe);
        Config::set('app.debug', $debug);

        Route::get($scriptRoute, fn () => '');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output);
        $expression = $output->fetch();

        preg_match_all('~"(/livewire/[^"]*)"~', $expression, $matches);

        $this->assertSame(0, $exitCode);
        $this->assertSame($expectedPaths, $matches[1]);
    }

    public static function livewireConfigurations(): array
    {
        return [
            'normal production' => [false, false, 'livewire/livewire.min.js', [
                '/livewire/livewire.min.js',
                '/livewire/livewire.min.js.map',
            ]],
            'normal debug' => [false, true, 'livewire/livewire.js', [
                '/livewire/livewire.js',
                '/livewire/livewire.min.js.map',
            ]],
            'csp production' => [true, false, 'livewire/livewire.min.js', [
                '/livewire/livewire.csp.min.js',
                '/livewire/livewire.csp.min.js.map',
                '/livewire/livewire.min.js',
            ]],
            'csp debug' => [true, true, 'livewire/livewire.js', [
                '/livewire/livewire.csp.js',
                '/livewire/livewire.csp.min.js.map',
                '/livewire/livewire.js',
            ]],
        ];
    }

    #[Test]
    public function it_defaults_to_normal_assets_without_removing_registered_csp_routes(): void
    {
        Config::set('livewire', []);
        Config::set('app.debug', false);

        Route::get('livewire/livewire.csp.min.js', fn () => '');
        Route::get('livewire/livewire.csp.min.js.map', fn () => '');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output);
        $expression = $output->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"/livewire/livewire.min.js.map"', $expression);
        $this->assertStringNotContainsString('"/livewire/livewire.js"', $expression);
        $this->assertStringContainsString('"/livewire/livewire.min.js"', $expression);
        $this->assertStringNotContainsString('"/livewire/livewire.csp.js"', $expression);
        $this->assertStringContainsString('"/livewire/livewire.csp.min.js"', $expression);
        $this->assertStringContainsString('"/livewire/livewire.csp.min.js.map"', $expression);
        $this->assertSame(1, substr_count($expression, '"/livewire/livewire.csp.min.js.map"'));
        $this->assertStringNotContainsString('wildcard "/livewire/*"', $expression);
    }

    #[Test]
    #[DataProvider('livewireAssetRoutes')]
    public function it_keeps_the_discovered_prefix_for_configured_livewire_assets(string $route, string $prefix): void
    {
        Config::set('livewire.csp_safe', false);
        Config::set('app.debug', false);

        Route::get($route, fn () => '');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output);
        $expression = $output->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"/'.$route.'"', $expression);
        $this->assertStringContainsString('"'.$prefix.'/livewire.min.js"', $expression);
        $this->assertStringContainsString('"'.$prefix.'/livewire.min.js.map"', $expression);
        $this->assertStringNotContainsString('wildcard "'.$prefix.'/*"', $expression);
    }

    public static function livewireAssetRoutes(): array
    {
        return [
            'standard prefix' => ['livewire/livewire.js', '/livewire'],
            'hashed prefix' => ['livewire-1a2b3c4d/livewire.min.js', '/livewire-1a2b3c4d'],
            'custom prefix' => ['tenant/assets/livewire.csp.js', '/tenant/assets'],
            'nested prefix' => ['custom/livewire/livewire.csp.min.js', '/custom/livewire'],
            'root source map' => ['livewire.min.js.map', ''],
            'csp source map' => ['livewire-1234abcd/livewire.csp.min.js.map', '/livewire-1234abcd'],
        ];
    }

    #[Test]
    public function it_applies_ignorable_paths_to_discovered_livewire_assets(): void
    {
        Config::set('livewire.csp_safe', true);
        Config::set('app.debug', false);
        Config::set('cfcache.features.waf.ignorable_paths', ['/livewire-1a2b3c4d/*.map']);

        Route::get('livewire-1a2b3c4d/livewire.js', fn () => '');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output);
        $expression = $output->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"/livewire-1a2b3c4d/livewire.js"', $expression);
        $this->assertStringContainsString('"/livewire-1a2b3c4d/livewire.csp.min.js"', $expression);
        $this->assertStringNotContainsString('.js.map', $expression);
    }

    #[Test]
    public function it_does_not_add_livewire_assets_without_a_matching_asset_route(): void
    {
        Route::post('livewire/update', fn () => '');
        Route::get('assets/not-livewire.js', fn () => '');
        Route::get('livewire/livewire.esm.js', fn () => '');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output);
        $expression = $output->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"/livewire/update"', $expression);
        $this->assertStringContainsString('"/assets/not-livewire.js"', $expression);
        $this->assertStringContainsString('"/livewire/livewire.esm.js"', $expression);
        $this->assertStringNotContainsString('livewire.csp.', $expression);
        $this->assertStringNotContainsString('livewire.min.js.map', $expression);
    }

    #[Test]
    public function it_filters_out_the_folio_fallback_route_and_includes_folio_routes(): void
    {
        $command = new GenerateWafRule;

        $json = json_encode([
            [
                'name' => 'laravel-folio',
                'uri' => '{fallbackPlaceholder}',
            ],
            [
                'name' => null,
                'uri' => '/',
            ],
        ]);

        $routes = $command->routes($json, [
            '/blog/{slug}',
            '/docs/{...path}',
        ]);

        $this->assertContains('/', $routes);
        $this->assertContains('/blog/*', $routes);
        $this->assertContains('/docs/*', $routes);
        $this->assertNotContains('/*', $routes);
    }

    #[Test]
    public function it_normalizes_folio_placeholder_routes(): void
    {
        $command = new GenerateWafRule;

        $routes = $command->routes(json_encode([]), [
            '/folio-placeholders/custom-key-alt/{user:name}',
            '/folio-placeholders/custom-key/{user:email}',
            '/folio-placeholders/fqcn-alt/{user}',
            '/folio-placeholders/fqcn/{user}',
            '/folio-placeholders/model-alt/{user}',
            '/folio-placeholders/model/{user}',
            '/folio-placeholders/multi-alt/{..paths}',
            '/folio-placeholders/multi/{..ids}',
            '/folio-placeholders/plain-alt/{slug}',
            '/folio-placeholders/plain/{id}',
        ]);

        $this->assertContains('/folio-placeholders/custom-key-alt/*', $routes);
        $this->assertContains('/folio-placeholders/custom-key/*', $routes);
        $this->assertContains('/folio-placeholders/fqcn-alt/*', $routes);
        $this->assertContains('/folio-placeholders/fqcn/*', $routes);
        $this->assertContains('/folio-placeholders/model-alt/*', $routes);
        $this->assertContains('/folio-placeholders/model/*', $routes);
        $this->assertContains('/folio-placeholders/multi-alt/*', $routes);
        $this->assertContains('/folio-placeholders/multi/*', $routes);
        $this->assertContains('/folio-placeholders/plain-alt/*', $routes);
        $this->assertContains('/folio-placeholders/plain/*', $routes);

        $this->assertFalse($routes->contains(fn (string $route) => str_contains($route, '{')));
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

    #[Test]
    public function it_includes_hostname_condition_in_rule_when_hostnames_configured(): void
    {
        Config::set('cfcache.features.waf.hostnames', ['app.example.com', 'www.example.com']);

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('(http.host in {"app.example.com" "www.example.com"}) and (', $output->fetch());
    }

    #[Test]
    public function it_omits_hostname_condition_when_hostnames_empty(): void
    {
        Config::set('cfcache.features.waf.hostnames', []);

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('http.host in {', $output->fetch());
    }
}
