<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use JTSmith\Cloudflare\DTOs\CachePurgeResult;
use JTSmith\Cloudflare\Exceptions\RouteNotFoundException;
use JTSmith\Cloudflare\Facades\Purge;
use JTSmith\Cloudflare\Services\Cloudflare\CachePurgeService;

it('can purge specific urls via facade', function () {
    Config::set('app.url', 'https://example.com');

    $mockService = $this->mock(CachePurgeService::class);
    $mockService->shouldReceive('purgeCache')
        ->once()
        ->with(['https://example.com/bios/*', 'https://example.com/other'])
        ->andReturn(new CachePurgeResult('123', 'Success'));

    $result = Purge::url(['/bios/*', 'other']);

    expect($result->id)->toBe('123');
});

it('can resolve routes to urls', function () {
    Config::set('app.url', 'https://example.com');
    Route::get('/users/{id}', fn () => 'users')->name('users.show');
    Route::getRoutes()->refreshNameLookups();

    $mockService = $this->mock(CachePurgeService::class);
    $mockService->shouldReceive('purgeCache')
        ->once()
        ->with(['https://example.com/users/*'])
        ->andReturn(new CachePurgeResult('123', 'Success'));

    Purge::route('users.show');
});

it('throws exception when route not found', function () {
    $this->mock(CachePurgeService::class);
    Purge::route('missing.route');
})->throws(RouteNotFoundException::class, 'Route [missing.route] not found.');

it('handles everything purge', function () {
    $mockService = $this->mock(CachePurgeService::class);
    $mockService->shouldReceive('purgeCache')
        ->once()
        ->with(null)
        ->andReturn(new CachePurgeResult('123', 'Purged All'));

    Purge::everything();
});

it('returns empty result when no urls provided', function () {
    $mockService = $this->mock(CachePurgeService::class);
    $mockService->shouldNotReceive('purgeCache');

    $result = Purge::url([]);

    expect($result->id)->toBe('')
        ->and($result->message)->toBe('No URLs provided to purge.');
});
