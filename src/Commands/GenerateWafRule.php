<?php

namespace JTSmith\Cloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JTSmith\Cloudflare\Actions\WafRule;
use JTSmith\Cloudflare\Exceptions\CloudflareApiException;
use JTSmith\Cloudflare\Exceptions\CloudflareException;
use JTSmith\Cloudflare\Services\Cloudflare\WafRuleService;

class GenerateWafRule extends Command
{
    protected $description = 'Generate a Cloudflare security rule for the endpoints available in your application';

    protected $signature = 'cloudflare:waf-rule
                            {--sync : Sync the generated rule to Cloudflare via API}';

    public function handle(): void
    {
        Artisan::call('route:list', ['--json' => true]);
        $json = Artisan::output();

        $routes = $this->routes($json);
        $rule = $this->generateRule($routes);

        $this->info('Generated Cloudflare WAF rule:');
        $this->line($rule);

        if ($this->option('sync')) {
            $this->syncToCloudflare($rule);
        }
    }

    public function routes(string $json): Collection
    {
        $routes = collect(json_decode($json, true))
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
            ->reject(function ($route) {
                return $this->isIgnorablePath($route);
            })
            ->values();

        return $routes;
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
        $waf_rule->optimize($routes);

        if (Str::length($waf_rule->expression()) <= 4000) {
            return $waf_rule->expression();
        }

        do {
            $previous = $waf_rule->expression();
            $waf_rule->condense();

            if (Str::length($waf_rule->expression()) <= 4000) {
                return $waf_rule->expression();
            }

        } while ($waf_rule->expression() !== $previous);

        if (Str::length($waf_rule->expression()) > 4000) {
            $this->error('Unable to generate a single expression under the 4096 characters limit. You will need to review and condense the expression yourself.');
        }

        return $waf_rule->expression();
    }

    protected function isIgnorablePath(string $route): bool
    {
        $ignorable = config('cfcache.features.waf.ignorable_paths') ?: ['/_dusk/*'];

        return collect($ignorable)->contains(function ($pattern) use ($route) {
            return Str::is($pattern, $route);
        });
    }

    protected function syncToCloudflare(string $expression): void
    {
        $this->newLine();
        $this->info('Syncing WAF rule to Cloudflare...');
        $this->newLine();

        try {
            $service = app(WafRuleService::class);
            $result = $service->syncRule($expression);

            $this->info($result->message);
            $this->line("  Action: {$result->action} WAF rule");
            $this->line("  Rule ID: {$result->ruleId}");
            $this->line("  Filter ID: {$result->filterId}");
            $this->line('  Expression length: '.Str::length($expression).' characters');
            $this->newLine();
            $this->info('Sync completed successfully!');
        } catch (CloudflareApiException $e) {
            $this->error('API error: '.$e->getMessage());
            $this->newLine();
            if ($e->isAuthenticationError()) {
                $this->warn('Authentication failed. Please verify:');
                $this->line('  - Your API token is valid and not expired');
                $this->line('  - The token has "Zone:Firewall Services:Edit" permission');
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
