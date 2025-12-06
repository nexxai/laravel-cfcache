<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Console\Command;
use JTSmith\Cloudflare\Concerns\ValidatesConfig;

class BaseCommand extends Command
{
    use ValidatesConfig;
}
