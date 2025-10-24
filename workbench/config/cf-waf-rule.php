<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cloudflare API Configuration
    |--------------------------------------------------------------------------
    |
    | These values are used to authenticate with the Cloudflare API and manage
    | WAF rules. You can obtain these credentials from your Cloudflare dashboard.
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
        'token' => env('CF_WAF_API_TOKEN'),

        /*
        |--------------------------------------------------------------------------
        | Zone ID
        |--------------------------------------------------------------------------
        |
        | The Cloudflare Zone ID for your domain. You can find this in your
        | Cloudflare dashboard on the overview page for your domain.
        |
        */
        'zone_id' => env('CF_WAF_ZONE_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WAF Rule Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the specific WAF rule to manage.
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
        'rule_identifier' => env('CF_WAF_RULE_ID', 'laravel-waf-rule'),

        /*
        |--------------------------------------------------------------------------
        | Rule Description
        |--------------------------------------------------------------------------
        |
        | Description for the WAF rule when creating a new one.
        |
        */
        'rule_description' => env('CF_WAF_RULE_DESCRIPTION', 'Valid Laravel Routes'),

        /*
        |--------------------------------------------------------------------------
        | Rule Action
        |--------------------------------------------------------------------------
        |
        | The action to take when the rule matches. Common values:
        | 'block', 'challenge', 'js_challenge', 'managed_challenge', 'allow', 'log', 'bypass'
        |
        */
        'rule_action' => env('CF_WAF_RULE_ACTION', 'block'),
    ],

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
        'base_url' => env('CF_WAF_API_BASE_URL', 'https://api.cloudflare.com/client/v4'),

        /*
        |--------------------------------------------------------------------------
        | Timeout
        |--------------------------------------------------------------------------
        |
        | The timeout in seconds for API requests.
        |
        */
        'timeout' => env('CF_WAF_API_TIMEOUT', 30),

        /*
        |--------------------------------------------------------------------------
        | Retry Attempts
        |--------------------------------------------------------------------------
        |
        | Number of times to retry failed API requests.
        |
        */
        'retry_attempts' => env('CF_WAF_API_RETRY_ATTEMPTS', 3),

        /*
        |--------------------------------------------------------------------------
        | Retry Delay
        |--------------------------------------------------------------------------
        |
        | Delay in milliseconds between retry attempts.
        |
        */
        'retry_delay' => env('CF_WAF_API_RETRY_DELAY', 1000),
    ],
];
