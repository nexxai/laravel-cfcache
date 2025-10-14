<?php

namespace JTSmith\Cloudflare\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SimplifyWafRule
{
    /**
     * Optimize a list of paths.
     *
     * @param  Collection<string>  $paths
     * @return Collection<string>
     */
    public function optimize(Collection $paths): Collection
    {
        // Normalize and sort
        $paths = $paths->map(function (string $path) {
            return Str::startsWith($path, '/') ? $path : '/'.$path;
        })->sort()->values();

        $optimized = collect();

        foreach ($paths as $path) {
            // Skip exact duplicates
            if ($optimized->contains($path)) {
                continue;
            }

            // If path is already covered by an existing wildcard rule, skip it
            $isCovered = $optimized->contains(function ($existing) use ($path) {
                if (Str::contains($existing, '*')) {
                    return $this->pathMatchesWildcard($path, $existing);
                }

                return false;
            });

            if ($isCovered) {
                continue;
            }

            // If adding a wildcard path, remove entries it actually covers
            if (Str::contains($path, '*')) {
                $optimized = $optimized->reject(function ($existing) use ($path) {
                    return $this->pathMatchesWildcard($existing, $path);
                })->values();
            }

            $optimized->push($path);
        }

        return $optimized->values();
    }

    /**
     * Determine if a concrete path matches a wildcard rule.
     * Wildcard semantics implemented:
     * - Terminal star after slash matches any subtree (zero or more segments)
     * - Middle slash-star-slash matches exactly one segment
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
     * Condense a list of paths based on their nearest common ancestor.
     *
     * @param  Collection<string>  $paths
     * @return Collection<string>
     */
    public function condense(Collection $paths): Collection
    {
        // Separate paths: those ending with /* vs those that don't (including mid-path wildcards)
        $wildcardPaths = $paths->filter(fn ($path) => Str::endsWith($path, '/*'));
        $otherPaths = $paths->reject(fn ($path) => Str::endsWith($path, '/*'));

        $condensed = collect();

        // Group non-ending-wildcard paths by their first two segments
        $grouped = $otherPaths->groupBy(function ($path) {
            // For paths with wildcards in the middle, get prefix before first /*
            $beforeWildcard = Str::contains($path, '/*') ? Str::before($path, '/*') : $path;

            $segments = explode('/', trim($beforeWildcard, '/'));

            // If only one segment, that's the group
            if (count($segments) === 1) {
                return '/'.$segments[0];
            }

            // Use first two segments as the most specific shared ancestor
            return '/'.$segments[0].'/'.$segments[1];
        });

        foreach ($grouped as $prefix => $group) {
            // Check if any existing ending-wildcard already covers this prefix
            $alreadyCovered = $wildcardPaths->contains(function ($wildcard) use ($prefix) {
                $wildcardPrefix = Str::before($wildcard, '/*');

                return Str::startsWith($prefix, $wildcardPrefix.'/') || $prefix === $wildcardPrefix;
            });

            if ($alreadyCovered) {
                continue;
            }

            if ($group->count() === 1) {
                // Only one path with this prefix, keep as-is
                $condensed->push($group->first());
            } else {
                // Multiple paths with same prefix
                $allExactMatch = $group->every(fn ($path) => $path === $prefix);

                if ($allExactMatch) {
                    // All identical, add once
                    $condensed->push($prefix);
                } else {
                    // Multiple different paths with same prefix, use wildcard
                    $condensed->push($prefix.'/*');
                }
            }
        }

        // Group ending-wildcard paths by their top-level segment so successive passes can bubble up
        $wildcardGrouped = $wildcardPaths->groupBy(function ($path) {
            $cleanPath = Str::before($path, '/*');
            $segments = explode('/', trim($cleanPath, '/'));

            // Always group by the first segment (top-level ancestor)
            return isset($segments[0]) && $segments[0] !== '' ? '/'.$segments[0] : '/';
        });

        foreach ($wildcardGrouped as $prefix => $group) {
            if ($group->count() === 1) {
                // Only one wildcard path with this prefix, keep as-is
                $condensed->push($group->first());
            } else {
                // Multiple wildcard paths share this parent, condense to parent wildcard
                $segments = explode('/', trim($prefix, '/'));

                if (count($segments) === 1) {
                    // Already at top level, just use it
                    $condensed->push($prefix.'/*');
                } else {
                    // Go up one level: use only first segment
                    $condensed->push('/'.$segments[0].'/*');
                }
            }
        }

        return $condensed->sort()->values();
    }
}
