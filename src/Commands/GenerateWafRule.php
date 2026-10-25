<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JTSmith\Cloudflare\Actions\WafRule;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Exceptions\CloudflareException;
use JTSmith\Cloudflare\Exceptions\ConfigValidationException;
use JTSmith\Cloudflare\Services\Cloudflare\WafRuleService;
use Throwable;

class GenerateWafRule extends BaseCommand
{
    protected $description = 'Generate a Cloudflare security rule for the endpoints available in your application';

    protected $signature = 'cloudflare:waf-rule
                            {--sync : Sync the generated rules to Cloudflare via API}
                            {--single-rule : Combine domain rules into one expression}';

    public function handle(): void
    {
        if ($this->option('sync')) {
            try {
                $this->validateRequiredConfig(['cfcache.api.token', 'cfcache.api.zone_id']);
            } catch (ConfigValidationException $e) {
                $this->fail($e->getMessage());
            }
        }

        Artisan::call('route:list', ['--json' => true]);
        $json = Artisan::output();

        $rules = $this->rules($json, $this->folioRoutes(), $this->option('single-rule'));

        foreach ($rules as $domain => $rule) {
            $this->info($domain === '' ? 'Generated Cloudflare WAF rule:' : "Generated Cloudflare WAF rule for {$domain}:");
            $this->line($rule);
        }

        if ($rules->contains(fn (string $rule) => Str::length($rule) > 4096)) {
            $this->fail('Unable to generate an expression under the 4096 character limit. Use separate rules or review the allowed paths.');
        }

        if ($this->option('sync')) {
            $this->syncToCloudflare($rules);
        }
    }

    /** @return Collection<string, string> */
    public function rules(string $json, Collection|array $additionalRoutes = [], bool $singleRule = false): Collection
    {
        $groups = collect(json_decode($json, true) ?: [])->groupBy(fn (array $route) => strtolower($route['domain'] ?? ''));
        $domains = $groups->keys()->reject(fn (string $domain) => $domain === '');

        if ($domains->isEmpty()) {
            $hostnames = config('cfcache.features.waf.hostnames', []);
            $prefix = '';
            $suffix = '';

            if (is_array($hostnames) && $hostnames !== []) {
                $list = implode(' ', array_map(fn (string $host) => json_encode($host, JSON_UNESCAPED_SLASHES), $hostnames));
                $prefix = '(http.host in {'.$list.'}) and (';
                $suffix = ')';
            }

            $rule = $this->generateRule($this->routes($json, $additionalRoutes), maxLength: 4096 - Str::length($prefix.$suffix));

            return collect(['' => $prefix.$rule.$suffix]);
        }

        $hosts = $domains->mapWithKeys(fn (string $domain) => [$domain => preg_replace('/\{[^}]+\}/', '*', $domain)]);
        $literalHosts = $hosts->reject(fn (string $host) => str_contains($host, '*'));
        $commonRoutes = $groups->get('', collect());
        $rules = collect();

        foreach ($hosts as $domain => $host) {
            $condition = $this->hostCondition($host);

            if (str_contains($host, '*')) {
                foreach ($literalHosts as $literalHost) {
                    if (Str::is($host, $literalHost)) {
                        $condition .= ' and http.host ne '.json_encode($literalHost);
                    }
                }
            }

            $routes = $this->routes($groups->get($domain)->merge($commonRoutes)->toJson(), $additionalRoutes);
            $rules->put($domain, $this->generateRule($routes, $condition));
        }

        // Unscoped routes also apply to hosts not covered by an explicit domain.
        if ($commonRoutes->isNotEmpty()) {
            $condition = 'not ('.$hosts->map(fn (string $host) => $this->hostCondition($host))->join(' or ').')';
            $rules->put('*', $this->generateRule($this->routes($commonRoutes->toJson(), $additionalRoutes), $condition));
        }

        return $singleRule
            ? collect(['' => $rules->map(fn (string $rule) => '('.$rule.')')->join(' or ')])
            : $rules;
    }

    protected function hostCondition(string $host): string
    {
        return 'http.host '.(str_contains($host, '*') ? 'wildcard' : 'eq').' '.json_encode($host);
    }

    public function routes(string $json, Collection|array $additionalRoutes = []): Collection
    {
        $routes = collect(json_decode($json, true) ?: [])
            ->reject(fn (array $route) => $this->isFolioFallbackRoute($route))
            ->pluck('uri')
            ->merge($additionalRoutes)
            ->merge($this->publicPaths())
            ->merge($this->forcedAllowedPaths())
            ->unique()
            ->flatMap(fn (string $route) => $this->routePaths($route))
            ->reject(function ($route) {
                return $this->isIgnorablePath($route);
            })
            ->unique()->values();

        return $routes;
    }

    protected function routePaths(string $route): array
    {
        $paths = [];

        do {
            $path = trim($route, '/');
            $paths[] = preg_match('/^\{[^}]+\}$/', $path)
                ? WafRule::ROOT_PARAMETER
                : '/'.preg_replace('/\{[^}]+\}/', '*', $path);

            if (! preg_match('~(?:^|/)\{[^/{}]+\?\}$~', $route)) {
                break;
            }

            $route = preg_replace('~(?:^|/)\{[^/{}]+\?\}$~', '', $route);
        } while (true);

        return $paths;
    }

    protected function folioRoutes(): Collection
    {
        if (! class_exists('Laravel\\Folio\\FolioManager')) {
            return collect();
        }

        try {
            Artisan::call('folio:list', ['--json' => true]);
        } catch (Throwable) {
            return collect();
        }

        return collect(json_decode(Artisan::output(), true) ?: [])
            ->pluck('uri')
            ->values();
    }

    protected function isFolioFallbackRoute(array $route): bool
    {
        return ($route['name'] ?? null) === 'laravel-folio'
            && ($route['uri'] ?? null) === '{fallbackPlaceholder}';
    }

    public function publicPaths(): array
    {
        $glob = File::glob(public_path('{,.}*'), GLOB_BRACE);

        return collect($glob)
            ->flatMap(function (string $path) {
                if (File::isDirectory($path) && ! in_array(basename($path), ['.', '..']) && config('cfcache.features.waf.descend_public_directories', true)) {
                    return File::glob($path.'/{,.}*', GLOB_BRACE);
                }

                return [$path];
            })
            ->reject(fn (string $path) => in_array(basename($path), ['.', '..', '.htaccess', 'index.php']))
            ->map(fn (string $path) => substr(Str::replace('\\', '/', $path), strlen(Str::replace('\\', '/', public_path())) + 1).(File::isDirectory($path) ? '/*' : ''))
            ->values()
            ->all();
    }

    public function generateRule($routes, string $hostCondition = '', int $maxLength = 4096): string
    {
        $waf_rule = new WafRule;
        $optimized = $waf_rule->optimize($routes);
        $prefix = $hostCondition === '' ? '' : '('.$hostCondition.') and ';

        if (Str::length($prefix.$waf_rule->expression()) <= $maxLength) {
            return $prefix.$waf_rule->expression();
        }

        // Condensing public assets must not reintroduce blanket directory wildcards.
        $publicPaths = collect($this->publicPaths())->flatMap(fn (string $path) => $this->routePaths($path))->intersect($optimized);

        do {
            $previous = $waf_rule->expression();
            $condensed = $waf_rule->condense($optimized->diff($publicPaths));
            $optimized = $waf_rule->optimize($condensed->merge($publicPaths));

            if (Str::length($prefix.$waf_rule->expression()) <= $maxLength) {
                return $prefix.$waf_rule->expression();
            }

        } while ($waf_rule->expression() !== $previous);

        return $prefix.$waf_rule->expression();
    }

    public function forcedAllowedPaths(): array
    {
        $paths = config('cfcache.features.waf.forced_allowed_paths') ?: [];

        return collect($paths)
            ->map(fn (string $path) => Str::startsWith($path, '/') ? $path : '/'.$path)
            ->reject(fn (string $path) => $path === '/' || $path === '*')
            ->values()
            ->all();
    }

    protected function isIgnorablePath(string $route): bool
    {
        $ignorable = config('cfcache.features.waf.ignorable_paths') ?: ['/_dusk/*'];

        // Normalize route to have leading slash to match pattern format
        $route = Str::startsWith($route, '/') ? $route : '/'.$route;

        return collect($ignorable)->contains(function ($pattern) use ($route) {
            return Str::is($pattern, $route);
        });
    }

    protected function syncToCloudflare(Collection $rules): void
    {
        $this->newLine();
        $this->info('Syncing WAF rule to Cloudflare...');
        $this->newLine();

        try {
            $service = app(WafRuleService::class);
            foreach ($service->syncRules($rules->all()) as $result) {
                $this->info($result->message);
                $this->line("  Action: {$result->action} WAF rule");
                $this->line("  Rule ID: {$result->ruleId}");
                $this->line("  Ruleset ID: {$result->rulesetId}");
                $this->line('  Expression length: '.Str::length($result->expression).' characters');
            }
            $this->newLine();
            $this->info('Sync completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->error('API error: '.$e->getMessage());
            $this->newLine();
            if ($e->isAuthenticationError()) {
                $this->warn('Authentication failed. Please verify:');
                $this->line('  - Your API token is valid and not expired');
                $this->line('  - The token has "Zone WAF:Edit" permission');
                $this->line('  - The Zone ID matches your domain');
            } elseif ($e->isRateLimitError()) {
                $this->warn('Rate limit exceeded. Please wait a moment and try again.');
            } else {
                $this->warn('Please check the error message above and verify your Cloudflare settings.');
            }
            if ($e->getStatusCode()) {
                $this->line('HTTP Status: '.$e->getStatusCode());
            }
        } catch (CloudflareException $e) {
            $this->error('Failed to sync with Cloudflare: '.$e->getMessage());
            $this->newLine();
            $this->warn('An unexpected error occurred. Please check your configuration and try again.');
        }
    }
}
