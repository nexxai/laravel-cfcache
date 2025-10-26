<?php

namespace Tests\Feature;

use JTSmith\Cloudflare\Actions\WafRule;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WafRuleTest extends TestCase
{
    #[Test]
    public function it_can_optimize_a_list_of_paths(): void
    {
        // TODO:
        // - All paths are prefixed with /
        // - optimize - removing above first level wildcards
        // - condense under 4k (repeat above process); start *adding* wildcards
        // - Run pre-optimization again
        // - Wildcards should be sorted above exact matches
        // TODO:
        // - filter by wildcard stuff
        // - sort them by longest
        // - loop over each; see if wildcard is a prefix match to anything else
        // - once the prefix doens't match anything else, break and go on to the next one

        $paths = collect([
            '/api/users/archive', // should be removed
            '/blog/*',
            'api/users/*',  // should become /api/users/*
            '/api/users/*/posts/*/comments', // should be removed
            '/api/users/*/posts/*', // should be removed
            'api/pages',
        ]);

        // The collection should be sorted alphabetically
        $expected = collect([
            '/api/pages',
            '/api/users/*',
            '/blog/*',
        ]);

        $optimized = (new WafRule)->optimize($paths);
        $this->assertEquals($expected, $optimized);
    }

    #[Test]
    public function it_can_find_similar_paths_and_condense_using_a_wildcard(): void
    {
        $paths = collect([
            '/mailcoach/5678/*',
            '/mailcoach/1234/1234',
            '/api/users',
            '/mailcoach/1234/1234/1234',
            'mailcoach/1234/*/1234/1234',
            '/mailcoach/1234',
            '/images/*',
            '/mailcoach/1234/1234/1234/1234/1234',
            'mailcoach/5678/9101',
            'blog/*',
            '/mailcoach/1234/1234/1234/1234/1234/1234',
            '/mailcoach/1234/1234/1234/*/1234/1234',
        ]);

        $waf_rule = new WafRule;
        $paths = $waf_rule->optimize($paths);

        // First we condense down to the two mailcoach paths
        $first_expected = collect([
            '/api/users',
            '/blog/*',
            '/images/*',
            '/mailcoach/1234/*',
            '/mailcoach/5678/*',
        ]);

        $first_condensed = $waf_rule->condense($paths);
        $this->assertEquals($first_expected, $first_condensed);

        // Then we can do a second pass to condense those two mailcoach paths down to a single one
        $second_expected = collect([
            '/api/users',
            '/blog/*',
            '/images/*',
            '/mailcoach/*',
        ]);

        $second_condensed = $waf_rule->condense($first_condensed);
        $this->assertEquals($second_expected, $second_condensed);
    }

    #[Test]
    public function it_will_handle_duplicate_paths_when_optimizing(): void
    {
        $paths = collect([
            '/api/users/*/posts/*',
            '/api/users/*/posts/*',
            '/api/users/*/comments/*',
            '/api/users/*/comments/*',
        ]);

        // The collection should be de-duplicated and sorted alphabetically
        $expected = collect([
            '/api/users/*/comments/*',
            '/api/users/*/posts/*',
        ]);

        $optimized = (new WafRule)->optimize($paths);
        $this->assertEquals($expected, $optimized);
    }

    #[Test]
    public function it_will_handle_duplicate_paths_when_condensing(): void
    {
        $paths = collect([
            '/api/users/*/posts/*',
            '/api/users/*/comments/*',
        ]);

        $expected = collect([
            '/api/users/*',
        ]);

        $condensed = (new WafRule)->condense($paths);
        $this->assertEquals($expected, $condensed);
    }

    #[Test]
    public function it_properly_groups_routes_with_trailing_wildcards_in_the_wildcard_group(): void
    {
        $paths = collect([
            '/@*',
            '/prefix*',
        ]);

        $rule = new WafRule;
        $optimized = $rule->optimize($paths);

        $expected = 'not (http.request.uri.path wildcard "/@*" or http.request.uri.path wildcard "/prefix*")';

        $this->assertEquals($expected, $rule->expression());
    }
}
