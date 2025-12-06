<?php

arch()
    ->expect('JTSmith\Cloudflare\Commands')
    ->toExtend('JTSmith\Cloudflare\Commands\BaseCommand');

arch()
    ->preset()
    ->laravel();

arch()
    ->preset()
    ->security();
