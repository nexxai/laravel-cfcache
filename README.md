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

## Documentation

### Cloudflare Security / WAF rule

This command was inspired by Jason McCreary's tweet: [https://x.com/gonedark/status/1978458884948775294](https://x.com/gonedark/status/1978458884948775294)

```sh
php artisan cloudflare:waf-rule
```

Once generated, you can copy and paste the expression into your domain's security rules (Security -> Security Rules -> Create Rule -> Custom Rule -> Edit expression)

## Contributing

Contributions to this project are welcome. You may open a Pull Request against the `main` branch. Please ensure you write a clear description (ideally with code samples) and all workflows are passing.
