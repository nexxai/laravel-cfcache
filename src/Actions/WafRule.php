<?php

namespace JTSmith\Cloudflare\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WafRule
{
    public const ROOT_PARAMETER = '/[*]';

    protected Collection $routes;

    public function __construct()
    {
        $this->routes = collect();
    }

    /**
     * Optimize a list of paths by:
     * - Normalizing and sorting input
     * - Dropping duplicates
     * - Skipping entries covered by previously-added wildcard rules
     * - Removing entries that become redundant when a new wildcard is added
     *
     * @param  Collection<string>  $paths
     * @return Collection<string>
     */
    public function optimize(Collection $paths): Collection
    {
        $paths = $this->normalizeAndSort($paths);

        $optimized = collect();

        foreach ($paths as $path) {
            if ($this->isExactDuplicate($optimized, $path)) {
                continue;
            }

            if ($this->isCoveredByExistingWildcards($optimized, $path)) {
                continue;
            }

            if ($this->containsWildcard($path)) {
                $optimized = $this->removeEntriesCoveredByWildcard($optimized, $path);
            }

            $optimized->push($path);
        }

        $this->routes = collect($optimized);

        return $this->routes;
    }

    /**
     * Determine if a concrete path matches a wildcard rule.
     *
     * Semantics implemented:
     * - A terminal "/*" means: match this path and anything beneath it (any depth).
     * - An internal segment with a single-segment wildcard (written here as "[*]") means: match exactly one path segment at that position.
     * - Non-wildcard segments must match exactly.
     *
     * Examples (conceptual):
     * - Rule: "/blog/*" matches: "/blog", "/blog/", "/blog/2024/10/post", "/blog/tags/php"
     * - Rule: "/api/[*]/users" matches: "/api/v1/users", "/api/x/users" but NOT "/api/v1/admin/users" (two segments) and NOT "/api/users" (zero segments)
     * - Rule: "/assets/*" matches anything under "/assets" such as "/assets/app.css" or "/assets/js/app.js"
     */
    protected function pathMatchesWildcard(string $concretePath, string $wildcardRule): bool
    {
        // A root parameter is not a subtree and must never prune sibling paths.
        if ($concretePath === self::ROOT_PARAMETER || $wildcardRule === self::ROOT_PARAMETER) {
            return false;
        }

        // Ensure leading slash
        $concretePath = Str::startsWith($concretePath, '/') ? $concretePath : '/'.$concretePath;
        $wildcardRule = Str::startsWith($wildcardRule, '/') ? $wildcardRule : '/'.$wildcardRule;

        // Build regex from segments
        $segments = explode('/', ltrim($wildcardRule, '/'));
        $regex = '^/';
        $count = count($segments);
        foreach ($segments as $index => $seg) {
            $isLast = ($index === $count - 1);
            if ($seg === '*') {
                if ($isLast) {
                    // Terminal star: subtree (at least one char after slash)
                    $regex .= '.+';
                    break; // no need to append further slashes
                } else {
                    // Single segment wildcard
                    $regex .= '[^/]+';
                }
            } else {
                $regex .= preg_quote($seg, '~');
            }

            if (! $isLast) {
                $regex .= '/';
            }
        }
        $regex .= '$';

        return (bool) preg_match('~'.$regex.'~', $concretePath);
    }

    /**
     * Condense routes by collapsing siblings to their nearest common ancestor.
     *
     * Usage patterns:
     * - Typical flow: call optimize($paths) first to compute $this->routes, then condense() with no args.
     * - Functional flow: pass a list directly via condense($paths) to condense without prior optimize().
     *
     * Architectural note: Allowing an optional input here lets consumers use this API in a more
     * functional/pure manner, and avoids surprises if condense() is invoked before optimize().
     * When no input is provided, the method uses the internally stored routes (if any).
     *
     * What it does, in two passes:
     * 1) Non-ending-wildcard paths (e.g., "/shop", "/shop/cart", "/shop/cart/[*]") are grouped by a likely parent
     *    (either "/segment" or "/segment/segment"). Each group is reduced to either the parent itself or parent/* if
     *    not all members are exact matches of the parent.
     * 2) Ending-wildcard paths (e.g., "/admin/*", "/admin/tools/*") are grouped by their top-level segment and
     *    replaced by their nearest shared ancestor plus /*.
     *
     * Examples:
     * - Before: ["/shop", "/shop/cart", "/blog/*", "/blog/tags/*"]
     *   After:  ["/shop/*", "/blog/*"]
     *
     * Root parameters and paths beginning with an internal wildcard are preserved
     * unless widening to a root wildcard has explicitly been enabled.
     *
     * @param  Collection<string>|array|null  $paths  Optional set of paths to condense; if null, uses internal routes.
     */
    public function condense(Collection|array|null $paths = null): Collection
    {
        // Determine the working set of paths
        if ($paths instanceof Collection) {
            $working = $this->normalizeAndSort($paths);
        } elseif (is_array($paths)) {
            $working = $this->normalizeAndSort(collect($paths));
        } elseif (count($this->routes) === 0) {
            throw new \InvalidArgumentException('Routes have not been provided here or optimized previously');
        } else {
            $working = $this->routes;
        }

        $protected = $working->filter(fn (string $path) => $path === self::ROOT_PARAMETER
            || (! config('cfcache.features.waf.allow_root_wildcard', false) && Str::startsWith($path, '/*')));
        $working = $working->diff($protected);

        [$endingWildcards, $nonEnding] = $this->splitByEndingWildcard($working);

        $condensed = collect();

        // Condense non-ending-wildcard paths by grouping on likely common ancestor
        $grouped = $this->groupNonEndingWildcardPaths($nonEnding);
        foreach ($grouped as $prefix => $group) {
            if ($this->prefixCoveredByEndingWildcard($endingWildcards, $prefix)) {
                continue;
            }

            $condensed->push($this->condenseNonEndingGroup($prefix, $group));
        }

        // Condense ending-wildcard paths by their top-level segment
        $wildcardGrouped = $this->groupEndingWildcardsByTopLevel($endingWildcards);
        foreach ($wildcardGrouped as $prefix => $group) {
            $condensed->push($this->condenseEndingWildcardGroup($prefix, $group));
        }

        // A subtree wildcard does not cover its bare parent (e.g. /activate).
        $condensed = $condensed->merge($nonEnding->reject(fn (string $path) => $this->containsWildcard($path)
            || $this->isCoveredByExistingWildcards($condensed, $path)))->merge($protected);

        // Final pruning: if any broader wildcard exists (e.g., "/account/*"),
        // remove any remaining routes covered by it (e.g., "/account/subscription/*" or "/account/profile").
        $final = $condensed->values();
        $wildcards = $final->filter(fn ($route) => Str::endsWith($route, '/*'))->values();
        foreach ($wildcards as $wc) {
            $final = $final->filter(function ($existing) use ($wc) {
                if ($existing === $wc) {
                    return true; // keep the wildcard itself
                }

                return ! $this->pathMatchesWildcard($existing, $wc);
            })->values();
        }

        // De-duplicate any accidentally duplicated routes produced during condensation
        $final = $final->unique()->values();

        $this->routes = collect($final->sort()->values());

        return $this->routes;
    }

    /**
     * Normalize all input paths to start with a leading slash and sort them alphabetically and lexicographically.
     *
     * Examples:
     * - Before: ["users", "/admin", "blog/posts"]
     *   After:  ["/admin", "/blog/posts", "/users"]
     *
     * @param  Collection<string>  $paths
     * @return Collection<string>
     */
    protected function normalizeAndSort(Collection $paths): Collection
    {
        return $paths->map(function (string $path) {
            $path = Str::startsWith($path, '/') ? $path : '/'.$path;

            return $path === '/*' && ! config('cfcache.features.waf.allow_root_wildcard', false)
                ? self::ROOT_PARAMETER
                : $path;
        })->sort()->values();
    }

    /**
     * Check if the given path is an exact duplicate of one already in the optimized set.
     *
     * Examples:
     * - optimized: ["/about", "/blog/*"] and path "/about"  => true
     * - optimized: ["/about", "/blog/*"] and path "/contact" => false
     *
     * @param  Collection<string>  $optimized
     */
    protected function isExactDuplicate(Collection $optimized, string $path): bool
    {
        return $optimized->contains($path);
    }

    /**
     * Determine whether the given path is already covered by any wildcard in the optimized set.
     *
     * A path is considered covered if there exists an entry like "/section/*" that matches the path
     * (e.g., "/section/page"), or an internal one-segment wildcard like "/api/[*]/users" that matches.
     *
     * Examples:
     * - optimized: ["/blog/*"] and path "/blog/2024/post" => true
     * - optimized: ["/api/[*]/users"] and path "/api/v1/users" => true
     * - optimized: ["/blog/*"] and path "/about" => false
     *
     * @param  Collection<string>  $optimized
     */
    protected function isCoveredByExistingWildcards(Collection $optimized, string $path): bool
    {
        return $optimized->contains(function ($existing) use ($path) {
            return $this->containsWildcard($existing) && $this->pathMatchesWildcard($path, $existing);
        });
    }

    /**
     * Remove any existing entries that are made redundant by the newly added wildcard.
     *
     * Example:
     * - optimized before: ["/blog/2024", "/blog/*", "/about"] and wildcard "/blog/*"
     *   After remove:      ["/blog/*", "/about"] (the concrete "/blog/2024" is covered by the wildcard)
     *
     * @param  Collection<string>  $optimized
     * @return Collection<string>
     */
    protected function removeEntriesCoveredByWildcard(Collection $optimized, string $wildcard): Collection
    {
        return $optimized->reject(function ($existing) use ($wildcard) {
            return $this->pathMatchesWildcard($existing, $wildcard);
        })->values();
    }

    /**
     * Quick check: does the string contain any wildcard character ("*")?
     *
     * Examples:
     * - "/blog/*"    => true
     * - "/api/[*]/x" => true (conceptual one-segment wildcard)
     * - "/about"     => false
     */
    protected function containsWildcard(string $path): bool
    {
        return $path !== self::ROOT_PARAMETER && Str::contains($path, '*');
    }

    /**
     * Build a Cloudflare WAF expression from the given routes (or from $this->routes if omitted).
     *
     * Shape:
     * not (
     *   http.request.uri.path in {"/a" "/b"} or
     *   http.request.uri.path wildcard "/segment/*"
     * )
     *
     * The expression places all exact paths in the set literal first, followed by
     * all trailing-wildcard routes (those ending with "/*") in the wildcard portion
     *
     * Examples:
     * - Routes: ["/about", "/contact", "/blog/*"]
     *   Expression: not (http.request.uri.path in {"/about" "/contact"} or http.request.uri.path wildcard "/blog/*")
     *
     * - Routes: ["/docs/*"]
     *   Expression: not (http.request.uri.path wildcard "/docs/*")
     *
     * @param  Collection<string>|null  $routes
     */
    public function expression(?Collection $routes = null): string
    {
        $routes = $this->normalizeAndSort($routes ?? $this->routes);
        $conditions = collect();
        $wildcards = $routes->filter(fn (string $route) => $this->containsWildcard($route));
        $paths = $routes->reject(fn (string $route) => $this->containsWildcard($route) || $route === self::ROOT_PARAMETER);

        if ($paths->isNotEmpty()) {
            $conditions->push('http.request.uri.path in {'.$paths->map(fn (string $path) => json_encode($path, JSON_UNESCAPED_SLASHES))->join(' ').'}');
        }

        $conditions = $conditions->merge($wildcards->map(fn (string $route) => 'http.request.uri.path wildcard '.json_encode($route, JSON_UNESCAPED_SLASHES)));

        if ($routes->contains(self::ROOT_PARAMETER)) {
            $condition = 'not http.request.uri.path wildcard "/*/*"';

            if (config('cfcache.features.waf.exclude_dotted_root_parameters', true)) {
                $condition .= ' and not http.request.uri.path contains "."';
            }

            $conditions->push('('.$condition.')');
        }

        return $conditions->isEmpty() ? 'true' : 'not ('.$conditions->join(' or ').')';
    }

    /**
     * Split the paths into two buckets:
     * - endingWildcards: those that end with "/*" (e.g., "/admin/*", "/docs/*")
     * - nonEnding:      everything else (exact paths or internal single-segment wildcards)
     *
     * Example:
     * - Input:  ["/about", "/blog/*", "/api/[*]/users", "/docs/*"]
     *   Output: [endingWildcards: ["/blog/*", "/docs/*"], nonEnding: ["/about", "/api/[*]/users"]]
     *
     * @param  Collection<string>  $paths
     * @return array{0: Collection<string>, 1: Collection<string>} [endingWildcards, nonEnding]
     */
    protected function splitByEndingWildcard(Collection $paths): array
    {
        $ending = $paths->filter(fn ($path) => Str::endsWith($path, '/*'));
        $nonEnding = $paths->reject(fn ($path) => Str::endsWith($path, '/*'));

        return [$ending, $nonEnding];
    }

    /**
     * Group non-ending-wildcard paths by a likely parent prefix to prepare for condensing
     *
     * Grouping rule:
     * - Take the path up to the first two segments (after stripping any trailing "/*" if present before grouping);
     *   that becomes the group key.
     *
     * Examples:
     * - Input: ["/shop", "/shop/cart", "/shop/orders", "/blog/tags", "/blog/*"]
     *   Groups:
     *   - "/shop" => ["/shop", "/shop/cart", "/shop/orders"]
     *   - "/blog/tags" => ["/blog/tags"]
     *
     * @param  Collection<string>  $nonEnding
     * @return Collection<string, Collection<string>>
     */
    protected function groupNonEndingWildcardPaths(Collection $nonEnding): Collection
    {
        return $nonEnding->groupBy(function ($path) {
            $beforeWildcard = Str::contains($path, '/*') ? Str::before($path, '/*') : $path;
            $segments = explode('/', trim($beforeWildcard, '/'));

            if (count($segments) === 1) {
                return '/'.$segments[0];
            }

            return '/'.$segments[0].'/'.$segments[1];
        });
    }

    /**
     * Check if a non-ending prefix is already covered by any ending-wildcard route.
     *
     * Coverage rules:
     * - If there is a wildcard like "/docs/*", then prefixes "/docs" and "/docs/x" are considered covered.
     * - Special case: the root wildcard "/*" should NOT cover the root path "/" itself (we keep "/").
     *
     * Examples:
     * - endingWildcards: ["/docs/*"] and prefix "/docs"     => true
     * - endingWildcards: ["/docs/*"] and prefix "/docs/api" => true
     * - endingWildcards: ["/*"] and prefix "/"             => false (special case)
     * - endingWildcards: ["/*"] and prefix "/health"        => true
     *
     * @param  Collection<string>  $endingWildcards
     */
    protected function prefixCoveredByEndingWildcard(Collection $endingWildcards, string $prefix): bool
    {
        return $endingWildcards->contains(fn (string $wildcard) => $this->pathMatchesWildcard($prefix, $wildcard));
    }

    /**
     * Reduce a group of non-ending-wildcard paths under the same prefix to either the prefix itself or prefix/*.
     *
     * Rules:
     * - If the group contains only the prefix exactly (e.g., only "/shop"), return the prefix.
     * - If the group contains the prefix and other children (e.g., "/shop", "/shop/cart"), return prefix/*.
     * - If the group has multiple children (e.g., "/shop/cart", "/shop/orders"), return prefix/*.
     *
     * Examples:
     * - prefix: "/shop" and group: ["/shop"]                    => "/shop"
     * - prefix: "/shop" and group: ["/shop", "/shop/cart"]     => "/shop/*"
     * - prefix: "/shop" and group: ["/shop/cart", "/shop/x"]    => "/shop/*"
     *
     * @param  Collection<string>  $group
     */
    protected function condenseNonEndingGroup(string $prefix, Collection $group): string
    {
        if ($group->count() === 1) {
            return $group->first();
        }

        $allExactMatch = $group->every(fn ($path) => $path === $prefix);

        return $allExactMatch ? $prefix : $prefix.'/*';
    }

    /**
     * Group ending-wildcard routes by their top-level segment (first segment after the root).
     *
     * Example:
     * - Input: ["/admin/*", "/admin/tools/*", "/docs/*", "/*"]
     *   Groups:
     *   - "/admin" => ["/admin/*", "/admin/tools/*"]
     *   - "/docs"  => ["/docs/*"]
     *   - "/"      => ["/*"]
     *
     * @param  Collection<string>  $endingWildcards
     * @return Collection<string, Collection<string>>
     */
    protected function groupEndingWildcardsByTopLevel(Collection $endingWildcards): Collection
    {
        return $endingWildcards->groupBy(function ($path) {
            $cleanPath = Str::before($path, '/*');
            $segments = explode('/', trim($cleanPath, '/'));

            return isset($segments[0]) && $segments[0] !== '' ? '/'.$segments[0] : '/';
        });
    }

    /**
     * Reduce a group of ending-wildcard routes to their nearest shared ancestor plus /*.
     *
     * Algorithm:
     * - Strip the trailing "/*" from each path, split into segments, and compute the longest common prefix (LCP).
     * - If the LCP is empty (no shared segment beyond root), fall back to the provided $prefix and append /*.
     * - Otherwise, join the LCP with slashes and append /*.
     *
     * Examples:
     * - prefix: "/admin" and group: ["/admin/*", "/admin/tools/*"]
     *   LCP = ["admin"] => result: "/admin/*"
     * - prefix: "/" and group: ["/blog/*", "/docs/*"]
     *   LCP = [] => result: "/*"
     *
     * @param  Collection<string>  $group
     */
    protected function condenseEndingWildcardGroup(string $prefix, Collection $group): string
    {
        if ($group->count() === 1) {
            return $group->first();
        }

        // Determine the nearest shared ancestor among the group's paths
        $segmentLists = $group->map(function ($path) {
            $clean = Str::before($path, '/*');

            return explode('/', trim($clean, '/'));
        })->values();

        // Compute longest common prefix of segments
        $lcp = [];
        $first = $segmentLists->first();
        foreach ($first as $i => $seg) {
            $allMatch = $segmentLists->every(function ($segments) use ($i, $seg) {
                return isset($segments[$i]) && $segments[$i] === $seg;
            });

            if (! $allMatch) {
                break;
            }

            $lcp[] = $seg;
        }

        // If no common segments beyond root, fall back to provided prefix
        if (count($lcp) === 0) {
            return rtrim($prefix, '/').'/*';
        }

        return '/'.implode('/', $lcp).'/*';
    }
}
