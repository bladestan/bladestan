[![codecov](https://codecov.io/gh/bladestan/bladestan/graph/badge.svg?token=YUVN1BPDES)](https://codecov.io/gh/bladestan/bladestan)

# Bladestan

Static analysis for Blade templates in Laravel projects.

Every Blade template is compiled to plain PHP once and analyzed by PHPStan like any other file in your project, with errors reported against the original `.blade.php` file and line. Templates declare what variables they expect, and every `view()` call and `@include` is validated against that contract.

## Install

```bash
composer require tomasvotruba/bladestan --dev
```

If you run PHPStan with its [extension installer](https://phpstan.org/user-guide/extension-library#installing-extensions), Bladestan is loaded automatically. If not, include it in your `phpstan.neon`:

```neon
includes:
    - ./vendor/tomasvotruba/bladestan/config/extension.neon
```

## Configure

To have your templates analyzed, add the `.bladestan` directory to your analysed paths. This is where Bladestan writes the compiled templates:

```neon
parameters:
    paths:
        - app
        - .bladestan
```

Also add it to your `.gitignore`:

```gitignore
.bladestan
```

That's it. On each PHPStan run Bladestan recompiles only the templates that changed, and PHPStan's result cache re-analyzes only what's affected.

> **Note:** the `paths` entry is required because PHPStan extensions cannot add analysed paths on their own. Without it, call-site validation (see below) still works, but template bodies are not analyzed. Templates inside `vendor/` are never compiled, since you can't annotate those anyway.

## Declare template signatures

Templates declare the variables they expect with a `@bladestan-signature` docblock: standard `@var` PHPDoc that your IDE already understands for autocomplete, plus one marker line.

```blade
@php
/**
 * @bladestan-signature
 * @var string $title
 * @var \App\Models\User $user
 * @var ?\App\Models\Post $post
 */
@endphp

<h1>{{ $title }}</h1>
<p>{{ $user->email }}</p>

@if($post)
    <article>{{ $post->title }}</article>
@endif
```

**Signatures are essential for good results, at every level.** Just like regular PHPStan, where hinting everything as `mixed` gives you an easy pass at level 8 but tells you nothing, an unsigned template gives PHPStan nothing to check your template against. Variable types are unknown, method calls can't be verified, and at level 9 every variable is reported as possibly undefined. The signature is to a template what parameter types are to a function.

From the same annotation you get template body analysis (a typo like `{{ $user->emial }}` is reported, and `{{ $post->title }}` is checked against nullability), validation of every `view()` call against the contract, and IDE autocomplete.

Things to know:

- **All declared variables are required.** There is no "optional". If a variable may be absent, declare it nullable (`?Type`) and pass `null` explicitly.
- **One signature per template.**
- **Migration is one line.** If you already keep `@var` docblocks in templates for autocomplete, Bladestan treats the first docblock before any template code as an implicit signature; adding the `@bladestan-signature` line just makes it explicit.

## Call-site validation

Every `view()` call (and `Mailable` content, `View::make()`, etc.) is validated against the template's signature:

```php
return view('welcome', [
    'title' => 42,           // Template welcome expects parameter $title of type string, but int given.
    'user' => $user,
]);                          // Template welcome requires parameter $post of type ?App\Models\Post, but it was not provided.
```

`@include` inside a template is a call site too, validated against the included partial's signature. Just like at runtime, the partial sees the including template's scope: a variable the partial declares is satisfied by passing it explicitly or by a variable of the same name already in scope, and its type is checked either way.

```blade
@php
/**
 * @bladestan-signature
 * @var string $title
 */
@endphp

@include('partials.header')                        {{-- $title comes from the scope above --}}
@include('partials.header', ['title' => 'About']) {{-- or is passed explicitly --}}
```

One thing to keep in mind: scope only travels one level per signature. If a partial passes a variable on to a deeper `@include` without using it itself, declare that variable in the partial's signature too, so each layer's contract states what it needs.

### `@extends` and layouts

Layouts declare their own signature and are compiled and analyzed like any other template. A child template's contract is **merged** with its layout's: whoever calls `view('welcome', ...)` must satisfy both the child's variables and the layout's.

```blade
{{-- layouts/app.blade.php --}}
@php
/**
 * @bladestan-signature
 * @var ?string $title
 * @var string $siteName
 */
@endphp
```

```blade
{{-- welcome.blade.php --}}
@php
/**
 * @bladestan-signature
 * @var string $title
 * @var \App\Models\User $user
 */
@endphp

@extends('layouts.app')
```

Here the child narrows the layout's `?string $title` to `string`, which is allowed. Widening it (for example to `string|int`) would be reported.

`view('welcome', ['title' => ..., 'user' => ...])` now also reports the missing `$siteName` required by the layout.

## Error formatter

Errors from templates point directly at the `.blade.php` file with correct line numbers when using the Blade error formatter:

```bash
vendor/bin/phpstan analyse --error-format=blade
```

```bash
 ------ -----------------------------------------------
  Line   resources/views/welcome.blade.php
 ------ -----------------------------------------------
  10     Call to an undefined method App\Models\User::getEmail().
 ------ -----------------------------------------------
```

## Credits

- [Can Vural](https://github.com/canvural) - this package is based on that, with upgrade for Laravel 10 and active maintenance
- [All Contributors](https://github.com/TomasVotruba/bladestan/graphs/contributors)
