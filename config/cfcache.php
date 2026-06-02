<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare API Configuration
    |--------------------------------------------------------------------------
    |
    | These settings are used to authenticate with the Cloudflare API and
    | specify which zone and account to work with.
    |
    */

    'api' => [

        /*
        |--------------------------------------------------------------------------
        | API Token
        |--------------------------------------------------------------------------
        |
        | Your Cloudflare API token with permissions to edit WAF rules.
        | Recommended: Create a token with "Zone:Firewall Services:Edit" permission.
        |
        */

        'token' => env('CFCACHE_API_TOKEN'),

        /*
        |--------------------------------------------------------------------------
        | Zone ID
        |--------------------------------------------------------------------------
        |
        | The Cloudflare Zone ID for your domain. You can find this in your
        | Cloudflare dashboard on the overview page for your domain.
        |
        */

        'zone_id' => env('CFCACHE_ZONE_ID'),

        /*
        |--------------------------------------------------------------------------
        | API Settings
        |--------------------------------------------------------------------------
        |
        | Additional settings for API communication.
        |
        */

        'settings' => [

            /*
            |--------------------------------------------------------------------------
            | API Base URL
            |--------------------------------------------------------------------------
            |
            | The base URL for the Cloudflare API. You shouldn't need to change this
            | unless Cloudflare changes their API endpoint.
            |
            */

            'base_url' => env('CFCACHE_API_BASE_URL', 'https://api.cloudflare.com/client/v4'),

            /*
            |--------------------------------------------------------------------------
            | Timeout
            |--------------------------------------------------------------------------
            |
            | The timeout in seconds for API requests.
            |
            */

            'timeout' => env('CFCACHE_API_TIMEOUT', 30),

            /*
            |--------------------------------------------------------------------------
            | Retry Attempts
            |--------------------------------------------------------------------------
            |
            | Number of times to retry failed API requests.
            |
            */

            'retry_attempts' => env('CFCACHE_API_RETRY_ATTEMPTS', 3),

            /*
            |--------------------------------------------------------------------------
            | Retry Delay
            |--------------------------------------------------------------------------
            |
            | Delay in milliseconds between retry attempts.
            |
            */

            'retry_delay' => env('CFCACHE_API_RETRY_DELAY', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Purges
    |--------------------------------------------------------------------------
    |
    | Scheduled purge requests are stored on disk until your application's
    | scheduler runs them. You may customize this path if the default storage
    | location does not work for your deployment environment.
    |
    */

    'scheduled_purges' => [
        'file' => env('CFCACHE_SCHEDULED_PURGES_FILE', storage_path('app/laravel-cfcache/scheduled-purges.json')),
    ],

    'features' => [

        /*
        |--------------------------------------------------------------------------
        | WAF Rule Configuration
        |--------------------------------------------------------------------------
        |
        | Configuration for the WAF rule.
        |
        */

        'waf' => [

            /*
            |--------------------------------------------------------------------------
            | Rule Identifier
            |--------------------------------------------------------------------------
            |
            | The identifier or name of the WAF rule to update or create.
            | If a rule with this identifier doesn't exist, it will be created.
            |
            */

            'rule_identifier' => env('CFCACHE_RULE_ID', 'laravel-waf-rule'),

            /*
            |--------------------------------------------------------------------------
            | Rule Description
            |--------------------------------------------------------------------------
            |
            | Description for the WAF rule when creating a new one.
            |
            */

            'rule_description' => env('CFCACHE_RULE_DESCRIPTION', 'Valid Laravel Routes'),

            /*
            |--------------------------------------------------------------------------
            | Rule Action
            |--------------------------------------------------------------------------
            |
            | The action to take when the rule matches. Valid values are:
            | 'block', 'challenge', 'js_challenge', 'managed_challenge', 'allow', 'log', 'bypass'
            |
            | See https://developers.cloudflare.com/firewall/cf-firewall-rules/actions/
            |
            */

            'rule_action' => env('CFCACHE_RULE_ACTION', 'block'),

            /*
            |--------------------------------------------------------------------------
            | Hostnames
            |--------------------------------------------------------------------------
            |
            | When non-empty, the generated WAF rule only applies when the request
            | hostname matches one of these values (compared against the Host header).
            | When empty, the rule applies to all hostnames (current behavior).
            | Set as an array here, or use CFCACHE_RULE_HOSTNAMES (comma-separated) in .env.
            |
            */

            'hostnames' => env('CFCACHE_RULE_HOSTNAMES')
                ? array_filter(array_map('trim', explode(',', env('CFCACHE_RULE_HOSTNAMES'))))
                : [],

            /*
            |--------------------------------------------------------------------------
            | Ignorable Paths
            |--------------------------------------------------------------------------
            |
            | A list of path patterns that should be ignored when generating the WAF rule.
            | These paths will not be included in the allowlist, even if they exist in your routes.
            | Supports wildcards (e.g., "/_dusk/*" will match "/_dusk/test").
            |
            */

            'ignorable_paths' => ['/_dusk/*'],

            /*
            |--------------------------------------------------------------------------
            | Forced Allowed Paths
            |--------------------------------------------------------------------------
            |
            | Paths that should always be included in the WAF allowlist even though they
            | are not part of your application routes. Supports wildcards.
            |
            */

            'forced_allowed_paths' => [],
        ],
    ],
];
