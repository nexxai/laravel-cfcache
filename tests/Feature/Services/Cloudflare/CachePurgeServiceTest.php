<?php

namespace Tests\Feature\Services\Cloudflare;

use Illuminate\Support\Facades\Http;
use JTSmith\Cloudflare\DTOs\CachePurgeResult;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Services\Cloudflare\CachePurgeService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CachePurgeServiceTest extends TestCase
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
        ];
    }

    #[Test]
    public function it_purges_all_cache_successfully(): void
    {
        Http::fake([
            '*/purge_cache' => Http::response([
                'success' => true,
                'result' => ['id' => 'purge-123'],
            ]),
        ]);

        $service = new CachePurgeService($this->validConfig);
        $result = $service->purgeCache();

        $this->assertInstanceOf(CachePurgeResult::class, $result);
        $this->assertEquals('purge-123', $result->id);
        $this->assertStringContainsString('all cached content', $result->message);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'purge_cache')
                && $request->data() === ['purge_everything' => true];
        });
    }

    #[Test]
    public function it_purges_specific_paths_successfully(): void
    {
        $paths = ['https://example.com/page1', 'https://example.com/page2'];

        Http::fake([
            '*/purge_cache' => Http::response([
                'success' => true,
                'result' => ['id' => 'purge-456'],
            ]),
        ]);

        $service = new CachePurgeService($this->validConfig);
        $result = $service->purgeCache($paths);

        $this->assertInstanceOf(CachePurgeResult::class, $result);
        $this->assertEquals('purge-456', $result->id);
        $this->assertStringContainsString('specified cached content', $result->message);

        Http::assertSent(function ($request) use ($paths) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'purge_cache')
                && $request->data() === ['files' => $paths];
        });
    }

    #[Test]
    public function it_purges_all_cache_when_empty_array_provided(): void
    {
        Http::fake([
            '*/purge_cache' => Http::response([
                'success' => true,
                'result' => ['id' => 'purge-789'],
            ]),
        ]);

        $service = new CachePurgeService($this->validConfig);
        $result = $service->purgeCache([]);

        $this->assertInstanceOf(CachePurgeResult::class, $result);
        $this->assertEquals('purge-789', $result->id);
        $this->assertStringContainsString('all cached content', $result->message);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'purge_cache')
                && $request->data() === ['purge_everything' => true];
        });
    }

    #[Test]
    public function it_throws_api_exception_for_purge_failure(): void
    {
        Http::fake([
            '*/purge_cache' => Http::response([
                'success' => false,
                'errors' => [
                    ['message' => 'Purge failed', 'code' => 10000],
                ],
            ], 400),
        ]);

        $service = new CachePurgeService($this->validConfig);

        $this->expectException(CloudflareApiException::class);
        $this->expectExceptionMessage('Purge failed');

        $service->purgeCache();
    }
}
