<p align="right">
    <a href="https://github.com/nexxai/laravel-cfcache/actions"><img src="https://github.com/nexxai/laravel-cfcache/workflows/Build/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/nexxai/laravel-cfcache"><img src="https://poser.pugx.org/nexxai/laravel-cfcache/v/stable.svg" alt="Latest Stable Version"></a>
    <a href="https://github.com/badges/poser/blob/master/LICENSE"><img src="https://poser.pugx.org/nexxai/laravel-cfcache/license.svg" alt="License"></a>
</p>

# Laravel Cloudflare Cache

This package provides tools to manage Cloudflare integration with your Laravel
application. It includes commands for generating Cloudflare security rules and
purging cached content.

The WAF rule generation was inspired by Jason McCreary's tweet: [https://x.com/gonedark/status/1978458884948775294](https://x.com/gonedark/status/1978458884948775294)

## Requirements

A Laravel application running Laravel 12 or higher. _Not running a stable
version of Laravel?_ [Upgrade with Shift](https://laravelshift.com).

## Installation

You can install this package by running the following command:

```sh
composer require -W nexxai/laravel-cfcache
```

To publish the configuration file (needed for WAF rule syncing):

```sh
php artisan vendor:publish --tag=cfcache-config
```

### Basic Usage

#### WAF Rule Generation

Generate the WAF rule expression for your Laravel routes:

```sh
php artisan cloudflare:waf-rule
```

Once generated, you can copy and paste the expression into your domain's
security rules by going to Security -> Security Rules -> Create Rule ->
Custom Rule -> Edit expression

#### Cache Purging

Purge all cached content or specific paths/routes:

```sh
# Purge all cache
php artisan cloudflare:purge

# Purge specific paths (absolute or relative, separated by spaces, wildcards supported)
php artisan cloudflare:purge / /about https://example.com/faq https://example.com/our-team/*

# Purge by route names
php artisan cloudflare:purge --route=home --route=users.index --route=auth.login
```

Please see the [Notes](#notes) section below for additional information and
examples, and potential gotchas.

### Advanced Usage

#### Cloudflare API Configuration

To use the live Cloudflare features, you need to configure your Cloudflare API
credentials. Add the following to your `.env` file:

```env
CFCACHE_API_TOKEN=your-api-token-here
CFCACHE_ZONE_ID=your-zone-id-here
```

#### Getting Your Cloudflare Credentials

1. **API Token**:
    - Go to [Cloudflare Dashboard](https://dash.cloudflare.com/profile/api-tokens)
    - Click "Create Token"
    - Use the "Custom token" template
    - Grant the following permissions (choose based on features you need):
        - Zone -> Firewall Services -> Edit (for WAF rule management)
        - Zone -> Cache Purge -> Edit (for cache purging)
    - Include your specific zone in the Zone Resources
    - Create the token and copy it to your `.env` file as `CFCACHE_API_TOKEN`

2. **Zone ID**:
    - Go to your domain's overview page in Cloudflare
    - Find the Zone ID in the right sidebar under "API"
    - Copy it to your `.env` file as `CFCACHE_ZONE_ID`

#### Sync to Cloudflare API

Automatically create or update the WAF rule in Cloudflare:

```sh
php artisan cloudflare:waf-rule --sync
```

#### Configuration

After publishing the configuration file, you can customize additional settings in `config/cfcache.php`:

```php
return [
    'api' => [
        'token' => env('CFCACHE_API_TOKEN'),
        'zone_id' => env('CFCACHE_ZONE_ID'),
        'settings' => [
            'base_url' => env('CFCACHE_API_BASE_URL', 'https://api.cloudflare.com/client/v4'),
            'timeout' => env('CFCACHE_API_TIMEOUT', 30),
            'retry_attempts' => env('CFCACHE_API_RETRY_ATTEMPTS', 3),
            'retry_delay' => env('CFCACHE_API_RETRY_DELAY', 1000),
        ],
    ],

    'features' => [
        'waf' => [
            'rule_identifier' => env('CFCACHE_RULE_ID', 'laravel-waf-rule'),
            'rule_description' => env('CFCACHE_RULE_DESCRIPTION', 'Valid Laravel Routes'),
            'rule_action' => env('CFCACHE_RULE_ACTION', 'block'),
            'ignorable_paths' => ['/_dusk/*'],
        ],
    ],
];
```

#### Available Rule Actions

- `block` - Block the request entirely
- `challenge` - Present a challenge to the visitor
- `js_challenge` - Present a JavaScript challenge
- `managed_challenge` - Use Cloudflare's managed challenge
- `allow` - Allow the request
- `log` - Log the request without taking action
- `bypass` - Bypass all security features

#### Ignorable Paths

You can configure paths that should be excluded from the WAF rule generation.
This is useful for local development routes that shouldn't be included in
production security rules:

```php
'ignorable_paths' => [
    '/_dusk/*',     // Laravel Dusk testing routes
    '/admin/test',  // Specific test routes
    '/debug/*',     // Debug routes
],
```

The patterns support wildcards using Laravel's `Str::is()` syntax:

- `/_dusk/*` matches `/dusk/login`, `/dusk/test`, etc.
- `/admin/*` matches any path under `/admin/`
- Exact matches like `/debug` work too

By default, only `/_dusk/*` is ignored to prevent Dusk testing routes from being
included in production rules.

## Cache Purging

This package also provides commands to purge Cloudflare's cache for specific paths or routes in your Laravel application.

### Basic Cache Purging

Purge all cached content from Cloudflare:

```sh
php artisan cloudflare:purge-cache
```

### Purge Specific Paths

Purge specific paths (relative paths will be prefixed with your app URL):

```sh
# Purge specific relative paths
php artisan cloudflare:purge / /about /contact

# Purge full URLs
php artisan cloudflare:purge https://example.com/page1 https://example.com/page2

# Mix relative and full URLs
php artisan cloudflare:purge /blog https://example.com/api/data

# Purge by route names
php artisan cloudflare:purge --route=home --route=users.index --route=auth.login

# Wildcards are supported
php artisan cloudflare:purge /blog/*
```

### Purge by Route Names

Purge cache for specific Laravel routes by name (route parameters are converted to wildcards):

```sh
# Purge by route names
php artisan cloudflare:purge --routes=home --routes=about --routes=users.show

# Combine routes and paths
php artisan cloudflare:purge /blog --routes=contact --routes=api.users.index
```

### Cloudflare API Configuration for Cache Purging

To use cache purging, you need to configure your Cloudflare API credentials with
cache purge permissions. Add the following to your `.env` file:

```env
CFCACHE_API_TOKEN=your-api-token-here
CFCACHE_ZONE_ID=your-zone-id-here
```

#### Getting Cache Purge Permissions

For cache purging, your API token needs the following permission:

- **Zone -> Cache Purge -> Edit**

You can create a token with both WAF and Cache Purge permissions if you plan to use both features.

## Notes

#### Multiple subdomains

If you use multiple subdomains (e.g., `example.com` and `sub.example.com`),
you will need to add a separate rule for each subdomain, and prefix
each with `http.host eq "example.com" and ` or
`http.host eq "sub.example.com" and `
when generating WAF rules.

#### Certbot / .well-known

If you're using [Certbot](https://certbot.org/) and the `.well-known` directory
to manage your SSL certificates (or for other purposes), you will need to manually
add a `.well-known/*` rule to the wildcard section of your WAF rule.

#### Cache Purging Notes

- Route parameters (like `{id}`) are converted to wildcards (`*`) for Cloudflare compatibility
- Relative paths are automatically prefixed with your `APP_URL`
- Full URLs (starting with `http://` or `https://`) are used as-is
- Unknown route names are silently skipped
- Cache purging requires different API permissions than WAF rule management

## Contributing

Contributions to this project are welcome. You may open a Pull Request against
the `main` branch. Please ensure you write a clear description (ideally with
code samples) and all workflows are passing. PRs without tests confirming
the proposed behavior will not be accepted.
