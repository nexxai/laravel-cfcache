<?php

namespace JTSmith\Cloudflare\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SimplifyWafRule
{
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
        [$endingWildcards, $nonEnding] = $this->splitByEndingWildcard($paths);

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

        return $condensed->sort()->values();
    }

    /**
     * @param  Collection<string>  $paths
     */
    protected function normalizeAndSort(Collection $paths): Collection
    {
        return $paths->map(function (string $path) {
            return Str::startsWith($path, '/') ? $path : '/'.$path;
        })->sort()->values();
    }

    /**
     * @param  Collection<string>  $optimized
     */
    protected function isExactDuplicate(Collection $optimized, string $path): bool
    {
        return $optimized->contains($path);
    }

    /**
     * @param  Collection<string>  $optimized
     */
    protected function isCoveredByExistingWildcards(Collection $optimized, string $path): bool
    {
        return $optimized->contains(function ($existing) use ($path) {
            return $this->containsWildcard($existing) && $this->pathMatchesWildcard($path, $existing);
        });
    }

    /**
     * @param  Collection<string>  $optimized
     */
    protected function removeEntriesCoveredByWildcard(Collection $optimized, string $wildcard): Collection
    {
        return $optimized->reject(function ($existing) use ($wildcard) {
            return $this->pathMatchesWildcard($existing, $wildcard);
        })->values();
    }

    protected function containsWildcard(string $path): bool
    {
        return Str::contains($path, '*');
    }

    /**
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

    /** @param Collection<string> $endingWildcards */
    protected function prefixCoveredByEndingWildcard(Collection $endingWildcards, string $prefix): bool
    {
        return $endingWildcards->contains(function ($wildcard) use ($prefix) {
            $wildcardPrefix = Str::before($wildcard, '/*');

            return Str::startsWith($prefix, $wildcardPrefix.'/') || $prefix === $wildcardPrefix;
        });
    }

    /**
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
     * @param  Collection<string>  $endingWildcards
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
     * @param  Collection<string>  $group
     */
    protected function condenseEndingWildcardGroup(string $prefix, Collection $group): string
    {
        if ($group->count() === 1) {
            return $group->first();
        }

        // Determine the nearest shared ancestor among the group's paths
        $segmentLists = $group->map(function ($path) {
            $clean = \Illuminate\Support\Str::before($path, '/*');

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
