<?php

namespace Tests\Feature\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use JTSmith\Cloudflare\Actions\WafRule;
use JTSmith\Cloudflare\Commands\GenerateWafRule;
use JTSmith\Cloudflare\Services\Cloudflare\WafRuleService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\CloudflareExpression;
use Tests\TestCase;

class DomainWafRuleTest extends TestCase
{
    protected string $publicDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDirectory = storage_path('framework/testing/waf-public-'.uniqid());
        File::ensureDirectoryExists($this->publicDirectory);
        $this->app->usePublicPath($this->publicDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDirectory);

        parent::tearDown();
    }

    #[Test]
    public function it_groups_domain_routes_and_excludes_literal_hosts_from_parameterized_hosts(): void
    {
        // route:list sorts by URI, not registration order; domain precedence must not depend on JSON order.
        $routes = collect(json_decode($this->fixture('domains'), true))->reverse()->toJson();
        $rules = (new GenerateWafRule)->rules($routes);

        $this->assertCount(4, $rules);
        $this->assertStringStartsWith('(http.host eq "example.com") and not (', $rules['example.com']);
        $this->assertStringStartsWith('(http.host eq "admin.example.com") and not (', $rules['admin.example.com']);
        $this->assertStringStartsWith('(http.host wildcard "*.example.com" and http.host ne "admin.example.com") and not (', $rules['{account}.example.com']);
        $this->assertStringNotContainsString('/backoffice/audit', $rules['{account}.example.com']);

        foreach ($rules as $rule) {
            $this->assertStringContainsString('"/health"', $rule);
        }

        $this->assertFalse($this->blocked($rules, 'unlisted.test', '/health'));
        $this->assertTrue($this->blocked($rules, 'unlisted.test', '/company/about'));
    }

    #[Test]
    public function it_allows_registered_routes_and_blocks_scanners_and_cross_host_paths_in_both_modes(): void
    {
        $this->writePublicFile('vendor/livewire/livewire.js');
        $this->writePublicFile('build/assets/app.js');

        foreach ([false, true] as $singleRule) {
            $rules = (new GenerateWafRule)->rules($this->fixture('domains'), singleRule: $singleRule);

            foreach (json_decode($this->fixture('domains'), true) as $route) {
                $host = str_replace('{account}', 'acme', $route['domain'] ?? 'example.com');
                $path = '/'.ltrim(preg_replace('/\{[^}]+\}/', 'sample', $route['uri']), '/');
                $this->assertFalse($this->blocked($rules, $host, $path), "Registered route blocked: {$host}{$path}");
            }

            foreach (['example.com', 'admin.example.com', 'acme.example.com', 'unlisted.test'] as $host) {
                foreach (['/.env', '/.git/config', '/wp-login.php', '/xmlrpc.php', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php'] as $path) {
                    $this->assertTrue($this->blocked($rules, $host, $path), "Scanner allowed: {$host}{$path}");
                }

                foreach (['/health', '/vendor/livewire/livewire.js', '/build/assets/app.js'] as $path) {
                    $this->assertFalse($this->blocked($rules, $host, $path), "Shared path blocked: {$host}{$path}");
                }
            }

            $this->assertTrue($this->blocked($rules, 'acme.example.com', '/backoffice/audit'));
            $this->assertTrue($this->blocked($rules, 'example.com', '/backoffice/audit'));
            $this->assertTrue($this->blocked($rules, 'admin.example.com', '/workspace/projects'));
            $this->assertFalse($this->blocked($rules, 'admin.example.com', '/acme/stores'));
            $this->assertFalse($this->blocked($rules, 'admin.example.com', '/acme/stores/123'));
            $this->assertFalse($this->blocked($rules, 'acme.example.com', '/activate'));
            $this->assertFalse($this->blocked($rules, 'admin.example.com', '/activate'));
        }
    }

    #[Test]
    public function it_keeps_the_no_domain_expression_and_hostname_config_unchanged(): void
    {
        $command = new GenerateWafRule;
        $expected = 'not (http.request.uri.path in {"/" "/about" "/api/users"})';

        $this->assertSame(['' => $expected], $command->rules($this->fixture('no-domains'))->all());

        Config::set('cfcache.features.waf.hostnames', ['example.com', 'www.example.com']);
        $rules = $command->rules($this->fixture('no-domains'));

        $this->assertSame('(http.host in {"example.com" "www.example.com"}) and ('.$expected.')', $rules['']);
        $this->assertFalse($this->blocked($rules, 'unrelated.test', '/.env'));
        $this->assertTrue($this->blocked($rules, 'example.com', '/.env'));

        // In domain-aware apps, inferred domains take precedence over the legacy hostnames setting.
        $this->assertTrue($this->blocked($command->rules($this->fixture('domains')), 'admin.example.com', '/.env'));
    }

    #[Test]
    public function it_keeps_root_parameters_separate_from_subtrees_and_preserves_internal_wildcards(): void
    {
        $paths = (new GenerateWafRule)->routes($this->fixture('paths'));
        $waf = new WafRule;
        $optimized = $waf->optimize($paths);

        foreach ([WafRule::ROOT_PARAMETER, '/*/stores', '/*/stores/*', '/api/*/users'] as $path) {
            $this->assertContains($path, $optimized);
        }

        $expression = $waf->expression();
        $this->assertStringContainsString('(not http.request.uri.path wildcard "/*/*" and not http.request.uri.path contains ".")', $expression);
        $this->assertStringContainsString('http.request.uri.path wildcard "/*/stores"', $expression);
        $this->assertStringContainsString('http.request.uri.path wildcard "/api/*/users"', $expression);
        $this->assertStringNotContainsString('http.request.uri.path wildcard "/*"', $expression);
        $this->assertFalse((new CloudflareExpression($expression, 'example.com', '/api/v1/users'))->matches());
        $this->assertTrue((new CloudflareExpression($expression, 'example.com', '/api/v1/secrets'))->matches());

        $waf->condense();
        $this->assertNotContains('/*', $waf->condense());
        $this->assertStringContainsString('http.request.uri.path wildcard "/*/stores"', $waf->expression());
        $this->assertTrue((new CloudflareExpression($waf->expression(), 'example.com', '/.git/config'))->matches());
    }

    #[Test]
    public function it_can_allow_dots_in_root_parameters_without_allowing_nested_paths(): void
    {
        $command = new GenerateWafRule;
        $this->assertTrue($this->blocked($command->rules($this->fixture('paths')), 'example.com', '/acme.inc'));

        Config::set('cfcache.features.waf.exclude_dotted_root_parameters', false);
        $rules = $command->rules($this->fixture('paths'));

        $this->assertFalse($this->blocked($rules, 'example.com', '/acme.inc'));
        $this->assertTrue($this->blocked($rules, 'example.com', '/acme.inc/private'));
    }

    #[Test]
    public function it_never_widens_to_a_root_wildcard_without_explicit_opt_in(): void
    {
        $command = new GenerateWafRule;
        $paths = $command->routes($this->fixture('paths'))->push('/*');
        $waf = new WafRule;
        $this->assertNotContains('/*', $waf->optimize($paths));
        $this->assertNotContains('/*', $waf->condense());

        Config::set('cfcache.features.waf.allow_root_wildcard', true);
        $this->assertContains('/*', $waf->optimize($paths));
        $this->assertContains('/*', $waf->condense());
        $this->assertFalse((new CloudflareExpression($waf->expression(), 'example.com', '/anything/deep'))->matches());
    }

    #[Test]
    public function it_expands_all_trailing_optional_parameters_including_the_bare_path(): void
    {
        $command = new GenerateWafRule;
        $paths = $command->routes($this->fixture('paths'));

        foreach (['/activate', '/activate/*', '/archive', '/archive/*', '/archive/*/*'] as $path) {
            $this->assertContains($path, $paths);
        }

        $waf = new WafRule;
        $waf->optimize($paths);

        foreach ([false, true] as $condense) {
            if ($condense) {
                $waf->condense();
            }

            foreach (['/activate', '/activate/token', '/archive', '/archive/2026', '/archive/2026/10'] as $path) {
                $this->assertFalse((new CloudflareExpression($waf->expression(), 'example.com', $path))->matches(), $path);
            }
        }
    }

    #[Test]
    public function it_descends_all_public_directories_and_supports_the_legacy_behavior(): void
    {
        foreach (['vendor/livewire/app.js', 'vendor/manifest.json', 'wp-content/themes/style.css', 'admin/assets/app.js', 'phpmyadmin/themes/app.css', 'cgi-bin/assets/app.css', 'build/assets/app.js', '.well-known/acme-challenge/token', 'robots.txt'] as $file) {
            $this->writePublicFile($file);
        }

        $command = new GenerateWafRule;
        $paths = $command->routes($this->fixture('no-domains'));

        foreach (['/vendor/livewire/*', '/vendor/manifest.json', '/wp-content/themes/*', '/admin/assets/*', '/phpmyadmin/themes/*', '/cgi-bin/assets/*', '/build/assets/*', '/.well-known/acme-challenge/*', '/robots.txt'] as $path) {
            $this->assertContains($path, $paths);
        }

        $this->assertNotContains('/vendor/*', $paths);
        $this->assertNotContains('/build/*', $paths);
        $this->assertTrue($this->blocked($command->rules($this->fixture('no-domains')), 'example.com', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php'));

        Config::set('cfcache.features.waf.descend_public_directories', false);
        $paths = $command->routes($this->fixture('no-domains'));
        $this->assertContains('/vendor/*', $paths);
        $this->assertContains('/build/*', $paths);
    }

    #[Test]
    public function it_outputs_separate_rules_by_default_and_combines_them_on_request(): void
    {
        $this->withoutMockingConsoleOutput();
        $this->fakeRouteList($this->fixture('domains'));
        $output = new BufferedOutput;
        $this->assertSame(0, $this->app->make(Kernel::class)->call('cloudflare:waf-rule', [], $output));
        $text = $output->fetch();
        $this->assertStringContainsString('Generated Cloudflare WAF rule for admin.example.com:', $text);
        $this->assertStringContainsString('Generated Cloudflare WAF rule for {account}.example.com:', $text);

        $this->fakeRouteList($this->fixture('domains'));
        $expected = (new GenerateWafRule)->rules($this->fixture('domains'), singleRule: true)[''];
        $this->assertStringContainsString(') or (', $expected);
        $output = new BufferedOutput;
        $this->assertSame(0, $this->app->make(Kernel::class)->call('cloudflare:waf-rule', ['--single-rule' => true], $output));
        $text = $output->fetch();

        foreach (["\n", "\r\n"] as $lineEnding) {
            $platformOutput = str_replace(PHP_EOL, $lineEnding, $text);
            $this->assertSame(['Generated Cloudflare WAF rule:', $expected], preg_split('/\r?\n/', trim($platformOutput)));
        }
    }

    #[Test]
    public function it_does_not_widen_public_directories_when_condensing_large_route_lists(): void
    {
        $this->writePublicFile('vendor/livewire/app.js');
        $this->writePublicFile('vendor/telescope/app.js');
        $template = json_decode($this->fixture('no-domains'), true)[2];
        $routes = collect(json_decode($this->fixture('no-domains'), true));

        for ($i = 0; $i < 250; $i++) {
            $routes->push(array_replace($template, ['uri' => 'api/users/unique-action-'.$i]));
        }

        $rules = (new GenerateWafRule)->rules($routes->toJson());
        $this->assertLessThanOrEqual(4096, strlen($rules['']));
        $this->assertStringContainsString('http.request.uri.path wildcard "/vendor/livewire/*"', $rules['']);
        $this->assertStringNotContainsString('http.request.uri.path wildcard "/vendor/*"', $rules['']);
        $this->assertFalse($this->blocked($rules, 'example.com', '/api/users'));
        $this->assertTrue($this->blocked($rules, 'example.com', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php'));
    }

    #[Test]
    public function it_budgets_each_host_separately_and_refuses_to_sync_an_oversized_combined_rule(): void
    {
        $template = json_decode($this->fixture('domains'), true)[1];
        $routes = collect();

        foreach (['example.com', 'admin.example.com'] as $host) {
            for ($i = 0; $i < 130; $i++) {
                $routes->push(array_replace($template, ['domain' => $host, 'uri' => 'unique-endpoint-'.$i]));
            }
        }

        $rules = (new GenerateWafRule)->rules($routes->toJson());
        $this->assertCount(2, $rules);

        foreach ($rules as $rule) {
            $this->assertLessThanOrEqual(4096, strlen($rule));
        }

        $this->assertGreaterThan(4096, strlen((new GenerateWafRule)->rules($routes->toJson(), singleRule: true)['']));
        Config::set('cfcache.api.token', 'test-token');
        Config::set('cfcache.api.zone_id', str_repeat('a', 32));
        $this->mock(WafRuleService::class)->shouldNotReceive('syncRules');
        $this->fakeRouteList($routes->toJson());
        $this->withoutMockingConsoleOutput();
        $output = new BufferedOutput;
        $this->assertSame(1, $this->app->make(Kernel::class)->call('cloudflare:waf-rule', ['--single-rule' => true, '--sync' => true], $output));
        $this->assertStringContainsString('4096 character limit', $output->fetch());
    }

    #[Test]
    public function it_includes_the_legacy_hostname_wrapper_in_the_expression_budget(): void
    {
        $template = json_decode($this->fixture('no-domains'), true)[2];
        $routes = collect();

        for ($i = 0; $i < 130; $i++) {
            $routes->push(array_replace($template, ['uri' => 'api/users/list-item-'.$i]));
        }

        $command = new GenerateWafRule;
        $unscoped = $command->rules($routes->toJson())[''];
        $this->assertLessThan(4096, strlen($unscoped));
        $hosts = array_map(fn (int $i) => "hostname-{$i}.example.com", range(1, 40));
        Config::set('cfcache.features.waf.hostnames', $hosts);
        $rules = $command->rules($routes->toJson());

        $this->assertLessThanOrEqual(4096, strlen($rules['']));
        $this->assertStringContainsString('http.request.uri.path wildcard "/api/users/*"', $rules['']);
        $this->assertFalse($this->blocked($rules, $hosts[0], '/api/users/list-item-0'));
    }

    protected function fixture(string $name): string
    {
        return File::get(__DIR__."/../../Fixtures/routes/{$name}.json");
    }

    protected function blocked(Collection $rules, string $host, string $path): bool
    {
        return $rules->contains(fn (string $expression) => (new CloudflareExpression($expression, $host, $path))->matches());
    }

    protected function writePublicFile(string $path): void
    {
        File::ensureDirectoryExists(dirname(public_path($path)));
        File::put(public_path($path), 'fixture');
    }

    protected function fakeRouteList(string $json): void
    {
        Artisan::registerCommand(new class($json) extends Command
        {
            protected $signature = 'route:list {--json}';

            public function __construct(private string $json)
            {
                parent::__construct();
            }

            public function handle(): void
            {
                $this->line($this->json);
            }
        });
    }
}
