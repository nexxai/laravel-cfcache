<p align="right">
    <a href="https://github.com/nexxai/laravel-cfcache/actions"><img src="https://github.com/nexxai/laravel-cfcache/workflows/Build/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/nexxai/laravel-cfcache"><img src="https://poser.pugx.org/nexxai/laravel-cfcache/v/stable.svg" alt="Latest Stable Version"></a>
    <a href="https://github.com/badges/poser/blob/master/LICENSE"><img src="https://poser.pugx.org/nexxai/laravel-cfcache/license.svg" alt="License"></a>
</p>

# Laravel Cloudflare Cache

This package is a WIP. It currently contains a command to generate the expression for a Cloudflare security rule for your Laravel application routes.

## Requirements

A Laravel application running Laravel 12 or higher. _Not running a stable version of Laravel?_ [Upgrade with Shift](https://laravelshift.com).

## Installation

You can install this package by running the following command:

```sh
composer require -W nexxai/laravel-cfcache
```

To publish the configuration file (only needed when using the `--sync` argument):

```sh
php artisan vendor:publish --tag=cf-waf-rule-config
```

## Documentation

### Cloudflare API Configuration

To use the automatic sync feature, you need to configure your Cloudflare API credentials. Add the following to your `.env` file:

```env
CF_WAF_API_TOKEN=your-api-token-here
CF_WAF_ZONE_ID=your-zone-id-here
```

#### Getting Your Cloudflare Credentials

1. **API Token**:
   - Go to [Cloudflare Dashboard](https://dash.cloudflare.com/profile/api-tokens)
   - Click "Create Token"
   - Use the "Custom token" template
   - Grant the following permissions:
     - Zone -> Firewall Services -> Edit
   - Include your specific zone in the Zone Resources
   - Create the token and copy it to your `.env` file

2. **Zone ID**:
   - Go to your domain's overview page in Cloudflare
   - Find the Zone ID in the right sidebar under "API"
   - Copy it to your `.env` file

### Cloudflare Security / WAF rule

This command was inspired by Jason McCreary's tweet: [https://x.com/gonedark/status/1978458884948775294](https://x.com/gonedark/status/1978458884948775294)

#### Basic Usage

Generate the WAF rule expression:

```sh
php artisan cloudflare:waf-rule
```

Once generated, you can copy and paste the expression into your domain's security rules (Security -> Security Rules -> Create Rule -> Custom Rule -> Edit expression)

#### Sync to Cloudflare API

Automatically create or update the WAF rule in Cloudflare:

```sh
php artisan cloudflare:waf-rule --sync
```

### Advanced Configuration

After publishing the configuration file, you can customize additional settings in `config/cf-waf-rule.php`:

```php
return [
    'api' => [
        'token' => env('CF_WAF_API_TOKEN'),
        'zone_id' => env('CF_WAF_ZONE_ID'),
    ],

    'waf' => [
        'rule_identifier' => env('CF_WAF_RULE_ID', 'laravel-waf-rule'),
        'rule_description' => env('CF_WAF_RULE_DESCRIPTION', 'Valid Laravel Routes'),
        'rule_action' => env('CF_WAF_RULE_ACTION', 'block'),
    ],

    'settings' => [
        'timeout' => env('CF_WAF_API_TIMEOUT', 30),
        'retry_attempts' => env('CF_WAF_API_RETRY_ATTEMPTS', 3),
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

## Contributing

Contributions to this project are welcome. You may open a Pull Request against the `main` branch. Please ensure you write a clear description (ideally with code samples) and all workflows are passing.
