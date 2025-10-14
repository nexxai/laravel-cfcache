<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JTSmith\Cloudflare\Actions\SimplifyWafRule;

class GenerateSimplifiedWafRule extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a simplified Cloudflare WAF whitelist rule based on your routes';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloudflare:new';

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

        $rule = $this->generateRule($routes);

        $this->info('Generated Cloudflare WAF rule: ('.Str::length($rule).' characters)');
        $this->line($rule);
    }

    /**
     * @param  Collection<string>  $routes
     * @return Collection<string>
     */
    private function simplify(Collection $routes): Collection
    {
        $prefixes = collect([]);

        // Replace placeholders with asterisks
        $routes = $routes->map(function ($route) {
            return preg_replace('/\{[^}]+\}/', '*', $route);
        });

        foreach ($routes as $route) {
            if (! $prefixes->contains($route)) {
                $prefixes[] = $route;
            }
        }

        return $prefixes;
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

    private function expression(Collection $routes): string
    {
        $expression = 'not {';

        $wildcards = $routes->filter(function ($route) {
            return Str::endsWith($route, '/*');
        });

        $paths = $routes->filter(function ($route) {
            return ! Str::endsWith($route, '/*');
        });

        if ($wildcards->isNotEmpty()) {
            $expression .= $wildcards->map(function ($route) {
                return sprintf(
                    'http.request.uri.path wildcard "%s"',
                    $route
                );
            })->join(' or ');

            if ($paths->isNotEmpty()) {
                $expression .= ' or ';
            }
        }

        if ($paths->isNotEmpty()) {
            $expression .= 'http.request.uri.path in {"';
            $expression .= implode('" or "', $paths->toArray());
            $expression .= '"}';
        }

        $expression .= '}';

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

    private function generateRule($routes): string
    {
        // Start by replacing placeholders
        $work = $this->placeholders($routes);

        $previous = null;
        $attempts = 0;
        $maxAttempts = 5; // safety cap to avoid infinite loops
        $rule = '';
        $waf_rule = new SimplifyWafRule;

        do {
            $optimized = $waf_rule->optimize($work);
            $rule = $this->expression($optimized);

            if (Str::length($rule) <= 4000) {
                return $rule;
            }

            // If no change from previous iteration, we can't reduce further
            if ($previous !== null && $optimized->values()->toJson() === $previous->values()->toJson()) {
                break;
            }

            $condensed = $waf_rule->condense($optimized);

            $previous = $condensed;
            $work = $condensed; // feed condensed paths back in for another pass
            $attempts++;
        } while ($attempts < $maxAttempts);

        // If we reach here, we could not get under 4000 characters
        $this->error('Unable to create a single Cloudflare WAF rule under 4000 characters after simplification.');
        $this->error('This rule will need to be manually condensed further.');

        return $rule; // return best-effort expression even if too long
    }
}
