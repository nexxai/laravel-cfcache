<?php

namespace Tests;

use JMac\Cloudflare\PageCacheServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app)
    {
        return [
            PageCacheServiceProvider::class,
        ];
    }
}
