<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JTSmith\Cloudflare\Actions\WafRule;

class GenerateWafRule extends Command
{
    protected $description = 'Generate a Cloudflare security rule for the endpoints available in your application';

    protected $signature = 'cloudflare:waf-rule';

    public function handle(): void
    {
        Artisan::call('route:list', ['--json' => true]);
        $json = Artisan::output();

        $routes = $this->routes($json);
        $rule = $this->generateRule($routes);

        $this->info('Generated Cloudflare WAF rule: ('.Str::length($rule).' characters)');
        $this->line($rule);
    }

    public function routes(string $json): Collection
    {
        return collect(json_decode($json, true))
            ->pluck('uri')
            ->merge($this->publicPaths())
            ->unique()
            ->map(function ($route) {
                if (! str_contains($route, '{')) {
                    return $route;
                }

                return preg_replace('/\{[^}]+\}/', '*', $route);
            })
            ->reject(function ($route) {
                return $route === '*';
            })
            ->values();
    }

    public function publicPaths(): array
    {
        $glob = File::glob(base_path('public/{,.}*'), GLOB_BRACE);

        return collect($glob)
            ->map(fn ($path) => Str::after($path, '/public/'))
            ->reject(fn ($path) => in_array($path, ['.', '..', '.htaccess', 'index.php']))
            ->map(fn ($path) => File::isDirectory(public_path($path)) ? $path.'/*' : $path)
            ->all();
    }

    public function generateRule($routes): string
    {
        $waf_rule = new WafRule;
        $rule = $waf_rule->optimize($routes);

        if (Str::length($rule) <= 4000) {
            return $rule;
        }

        do {
            $previous = $rule;
            $rule = $waf_rule->condense();

            if (Str::length($rule) <= 4000) {
                return $rule;
            }
        } while ($rule !== $previous);

        if (Str::length($rule) > 4000) {
            $this->error('Unable to generate a single expression under the 4096 characters limit. You will need to review and condense the expression yourself.');
        }

        return $rule;
    }
}
