<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateWafRule extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a Cloudflare WAF whitelist rule based on your routes';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloudflare:waf-rule';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Artisan::call('route:list', ['--json' => true]);
        $json = Artisan::output();

        $routes = collect(json_decode($json, true))
            ->pluck('uri')
            ->merge($this->publicPaths())
            ->unique();

        $routes = $this->placeholders($routes);
        $routes = $this->condense($routes);
        $rule = $this->expression($routes);

        $this->info('Generated Cloudflare WAF rule:');
        $this->info($rule);
    }

    private function placeholders(Collection $routes): Collection
    {
        return $routes->map(function ($route) {
            if (! str_contains($route, '{')) {
                return $route;
            }

            return preg_replace('/\{[^}]+\}/', '*', $route);
        });
    }

    private function condense(Collection $routes): Collection
    {
        return $routes->groupBy(fn ($route) => str_contains($route, '*'));
    }

    private function expression(Collection $routes): string
    {
        $expression = '';

        if ($routes->get(0)->isNotEmpty()) {
            $expression .= sprintf(
                'http.request.uri.path in {"%s"}',
                $routes->get(0)->join('" "')
            );
        }

        if ($routes->get(1)->isNotEmpty()) {
            if ($expression !== '') {
                $expression .= ' and ';
            }

            $expression .= $routes->get(1)->map(function ($route) {
                return 'http.request.uri.path wildcard "'.$route.'"';
            })->join(' and ');
        }

        return $expression;
    }

    private function publicPaths(): array
    {
        $glob = File::glob(base_path('public/{,.}*'), GLOB_BRACE);

        return collect($glob)
            ->map(fn ($path) => Str::after($path, '/public/'))
            ->reject(fn ($path) => in_array($path, ['.', '..', '.htaccess', 'index.php']))
            ->map(fn ($path) => File::isDirectory(public_path($path)) ? $path.'/*' : $path)
            ->all();
    }
}
