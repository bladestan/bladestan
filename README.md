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

That's it. Bladestan creates the `.bladestan` directory for you on the first run, so there is nothing to set up by hand. On each run it recompiles only the templates that changed, and PHPStan's result cache re-analyzes only what's affected.

> [!NOTE]
> The `paths` entry is required because PHPStan extensions cannot add analysed paths on their own. Without it, call-site validation (see below) still works, but template bodies are not analyzed. Bladestan warns when `.bladestan` is missing from your paths so the omission is not silent; if you only want call-site validation, set `parameters.bladestan.reportUnanalysedTemplates: false` to silence it. Templates inside `vendor/` are never compiled, since you can't annotate those anyway.

> [!WARNING]
> Add `.bladestan`, not your view directory. Your `resources/views` folder holds raw `.blade.php` source, which PHPStan cannot read as PHP: at best it reports nothing useful, at worst it reports errors that have nothing to do with your templates. If a view directory ends up in `paths`, Bladestan warns you so you can remove it. Templates are always analyzed from the compiled output, never from their source.

Compiled templates are written under `.bladestan/__templates__/`.

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
- **Write valid PHPDoc types.** Some forms printed by `dumpType` are not valid to write as a type, such as a template placeholder (`TModel (class ..., argument)`) or an accessory type (`hasOffsetValue(...)`); simplify these to a concrete type. A type Bladestan cannot parse is reported against the template that declares it and never affects any other result.
- **One signature per template.**
- **Migration is one line.** If you already keep `@var` docblocks in templates for autocomplete, Bladestan treats the first docblock before any template code as an implicit signature; adding the `@bladestan-signature` line just makes it explicit.

## Generating signatures

On an existing project the fastest way to add signatures to templates is to generate them from the types your controllers already pass:

```bash
php artisan bladestan:generate-signatures
```

This reads the real type of each `view()`, `View::make()`, and Mailable `->markdown()` call with PHPStan and writes a `@bladestan-signature` to every template rendered with data from PHP that does not already have one. Partials reached through `@include` are signed too, typed from the variables the including template forwards to them. A view rendered with no data is left alone, since it has no contract to declare. Use `--dry-run` to preview, `--force` to overwrite existing signatures, and `--path` to scan somewhere other than `app`. Templates used only as components (`<x-...>`) may still need a signature written by hand. The command is available only when Bladestan is installed as a dev dependency.

In a package that has no `artisan` binary, run the command through Testbench from the package root, pointing `--path` at your source directory:

```bash
vendor/bin/testbench bladestan:generate-signatures --path=src
```

When writing signatures by hand or with an AI coding agent, the [signature guideline](docs/laravel-boost-guideline.md) captures the rules that keep the types correct and parseable.

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

## Components

Blade components are analyzed like any other template. The variables Blade makes available inside a component body are provided automatically, so you never declare them: the default slot, the attribute bag, the component name, every `@props` variable, and, for a class component, the component's public properties and methods. A prop is typed from its default value; a prop declared without a default reads as untyped until you give it a type.

To type a prop, add a `@bladestan-signature` as usual. It takes precedence over the inferred types, so the body and every call site are checked against the type you declare.

Livewire component views are analyzed the same way: `$this` and the component's public properties are typed from the backing class, so `{{ $this->count }}` and `{{ $someProperty }}` are checked. This works for views resolved by Livewire's naming convention. If a component renders a view under a different name, declare the instance in that view's signature and everything on it is typed:

```blade
@php
/**
 * @bladestan-signature
 * @var \App\Livewire\Dashboard $this
 */
@endphp
```

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

Without it, template errors point at the compiled PHP under `.bladestan` instead of your `.blade.php` files, so Bladestan reminds you to pass `--error-format=blade` when it sees compiled templates being analyzed without a chosen format. Selecting any format, on the command line or with the `errorFormat` config parameter, silences the reminder.

## Credits

- [Can Vural](https://github.com/canvural) - this package is based on that, with upgrade for Laravel 10 and active maintenance
- [All Contributors](https://github.com/TomasVotruba/bladestan/graphs/contributors)
