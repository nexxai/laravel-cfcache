<?php

namespace JTSmith\Cloudflare\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WafRule
{
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
     * @return string Cloudflare expression for the optimized routes
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
                $regex .= preg_quote($seg, '/');
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
     * - Before: ["/", "/*", "/docs/*"] — if "/" exists, the root wildcard "/*" is pruned as redundant.
     *   After:  ["/", "/docs/*"]
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
            // If the base route '/' exists, prune the root wildcard '/*' as redundant
            if ($prefix === '/' && ($nonEnding->contains('/') || $condensed->contains('/'))) {
                continue;
            }

            $condensed->push($this->condenseEndingWildcardGroup($prefix, $group));
        }

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
            return Str::startsWith($path, '/') ? $path : '/'.$path;
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
        return Str::contains($path, '*');
    }

    /**
     * Build a Cloudflare WAF expression from the given routes (or from $this->routes if omitted).
     *
     * Shape:
     * not (
     *   http.request.uri.path wildcard "/segment/*" or
     *   http.request.uri.path in {"/a" "/b"}
     * )
     *
     * The expression places all trailing-wildcard routes (those ending with "/*") in the wildcard portion and
     * all exact paths in the set literal
     *
     * Examples:
     * - Routes: ["/blog/*", "/about", "/contact"]
     *   Expression: not (http.request.uri.path wildcard "/blog/*" or http.request.uri.path in {"/about" "/contact"})
     *
     * - Routes: ["/docs/*"]
     *   Expression: not (http.request.uri.path wildcard "/docs/*")
     *
     * @param  Collection<string>|null  $routes
     */
    public function expression(?Collection $routes = null): string
    {
        $routes = $routes ?? $this->routes;
        $expression = 'not (';

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
            $expression .= implode('" "', $paths->toArray());
            $expression .= '"}';
        }

        $expression .= ')';

        return $expression;
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
        return $endingWildcards->contains(function ($wildcard) use ($prefix) {
            $wildcardPrefix = Str::before($wildcard, '/*');

            // Special case: root wildcard '/*' should not be considered covering the root '/' itself
            if ($wildcardPrefix === '' && $prefix === '/') {
                return false;
            }

            return ($wildcardPrefix !== '' && (Str::startsWith($prefix, $wildcardPrefix.'/') || $prefix === $wildcardPrefix))
                || ($wildcardPrefix === '' && $prefix !== '/');
        });
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
