[![codecov](https://codecov.io/gh/bladestan/bladestan/graph/badge.svg?token=YUVN1BPDES)](https://codecov.io/gh/bladestan/bladestan)

# Bladestan

Static analysis for Blade templates in Laravel projects.

## Install

Bladestan requires a Laravel PHPStan extension to bootstrap the application. Install it with either PHPStan Laravel:

```bash
composer require calebdw/phpstan-laravel tomasvotruba/bladestan --dev
```

or Larastan:

```bash
composer require larastan/larastan tomasvotruba/bladestan --dev
```

## Configure

If you run PHPStan with its [extension installer](https://phpstan.org/user-guide/extension-library#installing-extensions), Bladestan will just work. Otherwise, include the Laravel PHPStan extension followed by Bladestan in the `phpstan.neon` configuration file:

```neon
includes:
    - ./vendor/calebdw/phpstan-laravel/extension.neon
    - ./vendor/tomasvotruba/bladestan/config/extension.neon
```

Use `./vendor/larastan/larastan/extension.neon` instead of the first include when using Larastan.

<br>

## Features

### Custom Error Formatter

We provide custom PHPStan error formatter to better display the template errors:

* clickable template file path link to the error in blade template

```bash
 ------ -----------------------------------------------------------
  Line   app/Http/Controllers/PostCodexController.php
 ------ -----------------------------------------------------------
  20     Call to an undefined method App\Entity\Post::getContent().
         rendered in: post_codex.blade.php:15
 ------ -----------------------------------------------------------
```

How to use custom error formatter?

```bash
vendor/bin/phpstan analyze --error-format=blade
```

<br>

## Credits

- [Can Vural](https://github.com/canvural) - this package is based on that, with upgrade for Laravel 10 and active maintenance
- [All Contributors](https://github.com/TomasVotruba/bladestan/graphs/contributors)
