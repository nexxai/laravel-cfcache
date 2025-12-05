<?php

namespace Tests\Feature\Commands;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Services\Cloudflare\CachePurgeService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurgeCacheTest extends TestCase
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
        Config::set('app.url', 'https://example.com');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_purges_all_cache_when_the_all_flag_is_provided(): void
    {
        Http::fake([
            '*/purge_cache' => Http::response([
                'success' => true,
                'result' => ['id' => 'purge-all-123'],
            ]),
        ]);

        $this->artisan('cloudflare:purge', ['--all' => true])
            ->expectsConfirmation('Are you sure you want to purge all cached content from Cloudflare?', 'yes')
            ->expectsOutput('Purging all cached content from Cloudflare...')
            ->expectsOutput('Cache purge completed successfully!');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'purge_cache')
                && $request->data() === ['purge_everything' => true];
        });
    }

    #[Test]
    public function it_errors_when_no_paths_or_routes_are_provided(): void
    {
        $this->artisan('cloudflare:purge')
            ->expectsOutput('You must specify at least one path or route to purge. Or purge everything with `--all`.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_purges_specific_paths(): void
    {
        Http::fake([
            '*/purge_cache' => Http::response([
                'success' => true,
                'result' => ['id' => 'purge-paths-456'],
            ]),
        ]);

        $this->artisan('cloudflare:purge', ['paths' => ['/', 'about']])
            ->expectsOutput('Purging specified paths from Cloudflare cache:')
            ->expectsOutput('https://example.com/')
            ->expectsOutput('https://example.com/about')
            ->expectsOutput('Successfully purged specified cached content')
            ->expectsOutput('  Purge ID: purge-paths-456')
            ->expectsOutput('  Paths purged: 2')
            ->expectsOutput('Cache purge completed successfully!')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'purge_cache')
                && isset($data['files'])
                && $data['files'] === ['https://example.com/', 'https://example.com/about'];
        });
    }

    #[Test]
    public function it_resolves_routes_to_paths(): void
    {
        $command = new \JTSmith\Cloudflare\Commands\PurgeCache;

        // Mock Route::getRoutes()
        $mockRouteCollection = Mockery::mock();
        $mockRouteCollection->shouldReceive('getByName')
            ->with('home')
            ->andReturn(Mockery::mock()->shouldReceive('uri')->andReturn('/')->getMock());
        $mockRouteCollection->shouldReceive('getByName')
            ->with('about')
            ->andReturn(Mockery::mock()->shouldReceive('uri')->andReturn('about')->getMock());
        $mockRouteCollection->shouldReceive('getByName')
            ->with('users.show')
            ->andReturn(Mockery::mock()->shouldReceive('uri')->andReturn('users/{id}')->getMock());
        $mockRouteCollection->shouldReceive('getByName')
            ->with('unknown')
            ->andReturn(null);

        Route::shouldReceive('getRoutes')
            ->andReturn($mockRouteCollection);

        $resolved = $command->resolveRoutes(['home', 'about', 'users.show', 'unknown']);

        $this->assertEquals(['/', '/about', '/users/*'], $resolved);
    }

    #[Test]
    public function it_processes_routes_in_command(): void
    {
        // Mock Route::getRoutes()
        $mockRouteCollection = Mockery::mock();
        $mockRouteCollection->shouldReceive('getByName')
            ->with('home')
            ->andReturn(Mockery::mock()->shouldReceive('uri')->andReturn('/')->getMock());
        $mockRouteCollection->shouldReceive('getByName')
            ->with('products.show')
            ->andReturn(Mockery::mock()->shouldReceive('uri')->andReturn('products/{id}')->getMock());

        Route::shouldReceive('getRoutes')
            ->andReturn($mockRouteCollection);

        Http::fake([
            '*/purge_cache' => Http::response([
                'success' => true,
                'result' => ['id' => 'purge-routes-integration-123'],
            ]),
        ]);

        $this->artisan('cloudflare:purge', ['--route' => ['home', 'products.show']])
            ->expectsOutput('Purging specified paths from Cloudflare cache:')
            ->expectsOutput('https://example.com/')
            ->expectsOutput('https://example.com/products/*')
            ->expectsOutput('Successfully purged specified cached content')
            ->expectsOutput('  Purge ID: purge-routes-integration-123')
            ->expectsOutput('  Paths purged: 2')
            ->expectsOutput('Cache purge completed successfully!')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'purge_cache')
                && isset($data['files'])
                && count($data['files']) === 2
                && in_array('https://example.com/', $data['files'])
                && in_array('https://example.com/products/*', $data['files']);
        });
    }

    #[Test]
    public function it_handles_api_errors_gracefully(): void
    {
        $mockService = Mockery::mock(CachePurgeService::class);
        $mockService->shouldReceive('purgeCache')
            ->once()
            ->andThrow(new CloudflareApiException(
                'Authentication failed',
                401,
                null,
                401,
                ['errors' => [['message' => 'Authentication failed', 'code' => 10000]]],
                'zones/zone_id/purge_cache',
                'POST'
            ));

        $this->app->instance(CachePurgeService::class, $mockService);

        $this->artisan('cloudflare:purge', ['--all' => true])
            ->expectsConfirmation('Are you sure you want to purge all cached content from Cloudflare?', 'yes')
            ->expectsOutput('Purging all cached content from Cloudflare...')
            ->expectsOutput('API error: Authentication failed')
            ->expectsOutput('Authentication failed. Please verify:')
            ->expectsOutput('  - Your API token is valid and not expired')
            ->expectsOutput('  - The token has "Zone:Cache Purge:Edit" permission')
            ->expectsOutput('  - The Zone ID matches your domain')
            ->expectsOutput('HTTP Status: 401')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_fails_when_api_token_is_not_set(): void
    {
        Config::set('cfcache.api.token', null);

        $this->artisan('cloudflare:purge', ['--all' => true])
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_zone_id_is_not_set(): void
    {
        Config::set('cfcache.api.zone_id', null);

        $this->artisan('cloudflare:purge', ['--all' => true])
            ->assertFailed();
    }
}
