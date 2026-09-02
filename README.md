[![codecov](https://codecov.io/gh/bladestan/bladestan/graph/badge.svg?token=YUVN1BPDES)](https://codecov.io/gh/bladestan/bladestan)

# Bladestan

Static analysis for Blade templates in Laravel projects.

Every Blade template is compiled to plain PHP once and analyzed by PHPStan like any other file in your project, with errors reported against the original `.blade.php` file and line. Templates declare what variables they expect, and every `view()` call and `@include` is validated against that contract.

For example, a template that expects a `$user` and a controller that forgets to pass one:

```php
return view('profile', ['title' => 'My profile']);
```

```
Template profile requires parameter $user of type App\Models\User, but it was not provided.
```

That's caught the moment you run PHPStan, not when a customer hits the page in production.

## Install

```bash
composer require tomasvotruba/bladestan --dev
```

If you run PHPStan with its [extension installer](https://phpstan.org/user-guide/extension-library#installing-extensions), Bladestan is loaded automatically. If not, include it in your `phpstan.neon`:

```neon
includes:
    - ./vendor/tomasvotruba/bladestan/config/extension.neon
```

> [!IMPORTANT]
> Upgrading from 0.11? Analysis is now template-centric: templates declare the variables they expect, and a one-time setup is needed. See [`UPGRADE.md`](UPGRADE.md) before your first run, and [`CHANGELOG.md`](CHANGELOG.md) for the full history.

## Configure

To have your templates analyzed, add your view directory to PHPStan's analysed paths:

```neon
parameters:
    paths:
        - app
        - resources/views
```

That's it. PHPStan discovers your `.blade.php` files the way it discovers any other file, and Bladestan hands it their compiled form, so a template is analyzed exactly like a PHP file: errors point at the template, the result cache re-analyzes only what a change affects, and parallel workers share the load. There is no generated directory to create, ignore, or keep in sync.

The `paths` entry is required because PHPStan extensions cannot add analysed paths on their own. Leave it out and Bladestan tells you which directory is missing, so the mistake doesn't fail silently: call-site validation still works, but template bodies aren't analyzed. Silence that warning with `parameters.bladestan.reportUnanalysedTemplates: false` if it's intentional. Templates inside `vendor/` are skipped, since you can't annotate those anyway.

Changing a template's signature re-analyzes everything, because PHPStan cannot see which `view()` calls depend on a template. That is conservative but correct, and a finer-grained invalidation is planned upstream in PHPStan.

Passing paths on the command line (`vendor/bin/phpstan analyse app/Http`) scopes the run to those paths, which leaves templates out of it, exactly as PHPStan skips every other file you didn't ask for. Run without the paths argument to analyze your templates again.

> [!NOTE]
> Because templates sit under an analysed path, your own custom PHPStan rules run against them too, so a rule you wrote for your PHP now also checks your Blade templates. A rule that assumes hand-written PHP (for example one that forbids `echo`, which compiled Blade uses throughout) can exclude `*.blade.php` where its findings are not useful.

## Generate signatures

The fastest way to give templates signatures is to generate them from the types your controllers already pass:

```bash
php artisan bladestan:generate-signatures
```

Run it once to bootstrap an existing project, then run it again any time you add a template or a new render site. It's idempotent: it only writes a signature to templates that don't already have one, so re-running it costs nothing extra, and keeping templates typed never gets harder than it already was as long as the rest of your code has clean types.

This reads with PHPStan the real type passed at each render site (`view()`, `View::make()`, Mailable content) and writes a `@bladestan-signature` accordingly. Partials reached through `@include` are signed too, typed from the variables the including template forwards to them. Anonymous components are scaffolded from their `@props`: each prop is typed from its default value where it has one, and left as `mixed` otherwise for you to fill in. A view rendered with no data is left alone, since it has no contract to declare.

When the type at a call site is itself unresolvable, the generated signature declares the variable as `mixed`. It stops the variable being reported as undefined, but checks nothing until you replace the `mixed` with a real type. The command flags each such template as it writes it and reports how many carry only `mixed` types, so you can see at a glance which signatures are load-bearing and which still need work.

By default it scans the same `paths` already declared in your PHPStan config, so wildcard `excludePaths` and everything else you already tuned there apply unchanged. Use `--dry-run` to preview, `--force` to overwrite existing signatures, and `--path` to narrow the scan to specific directories or files instead. The command is available only when Bladestan is installed as a dev dependency.

In a package that has no `artisan` binary, run the command through Testbench from the package root:

```bash
vendor/bin/testbench bladestan:generate-signatures
```

Signing is iterative. The command already runs in passes, so a partial is typed once its includers are. But when a type depends on a signature that's still missing (a layout, a class-backed component, an `@extends` root), run the command again afterwards so that type flows outward to everything downstream. Pass `--force` to refresh signatures that already exist. The rhythm is: generate, add signatures for the roots the generator left as `mixed`, then generate again.

To find the type for a single variable by hand, add `\PHPStan\dumpType($data)` (or `\PHPStan\dumpType(get_defined_vars())`) just before the `view()` call, run `vendor/bin/phpstan analyse` over that controller, read the array shape it prints, and remove the dump. That is the same inference the generator automates across the whole app.

A template that no call site renders has nothing to harvest, so it stays untyped. To tell a genuinely dead template apart from one that simply still needs a signature, enable [Larastan](https://github.com/larastan/larastan)'s unused-views check (`parameters: checkUnusedViews: true`), which lists every view with no render site in the project.

When writing signatures by hand or with an AI coding agent, the [signature guideline](resources/boost/guidelines/core.blade.php) captures the rules that keep the types correct and parseable. [Laravel Boost](https://laravel.com/docs/boost) picks it up automatically on `boost:install`; for any other agent, copy the block into your project's agent guidelines.

## Declare template signatures

The generator above writes a `@bladestan-signature` docblock to each template: standard `@var` PHPDoc that your IDE already understands for autocomplete, plus one marker line. Here's the format, useful when you're writing or adjusting one by hand:

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

**Signatures are essential for good results, at every level.** Just like hinting everything as `mixed` in regular PHP code gives PHPStan nothing to check, an unsigned template gives it nothing to check your template against. Variable types are unknown, method calls can't be verified, and PHPStan's normal rules for `mixed` apply throughout. The signature is to a template what parameter types are to a function.

From the same annotation you get template body analysis (a typo like `{{ $user->emial }}` is reported, and `{{ $post->title }}` is checked against nullability), validation of every `view()` call against the contract, and IDE autocomplete.

Things to know:

- **All declared variables are required.** There is no "optional". If a variable may be absent, declare it nullable (`?Type`) and pass `null` explicitly.
- **Write valid PHPDoc types.** Some forms printed by `dumpType` are not valid to write as a type, such as a template placeholder (`TModel (class ..., argument)`) or an accessory type (`hasOffsetValue(...)`); simplify these to a concrete type. A type Bladestan cannot parse is reported against the template that declares it and never affects any other result.
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

## Error output

Errors from templates point at the `.blade.php` file, on the line you wrote, in whatever output format you already use:

```bash
 ------ -----------------------------------------------
  Line   resources/views/welcome.blade.php
 ------ -----------------------------------------------
  10     Call to an undefined method App\Models\User::getEmail().
 ------ -----------------------------------------------
```

There is no Bladestan-specific formatter to select. The default table, the machine-readable formatters (`json`, `raw`, `checkstyle`, and the rest), your editor's PHPStan integration, and CI annotations all report the template path and line, because that is what PHPStan itself records for the error. The same goes for anything that matches on a path: `--generate-baseline` writes entries against your templates, and an `ignoreErrors` entry scoped to `resources/views/*.blade.php` matches.

> [!NOTE]
> PHPStan's inline ignore comments (`@phpstan-ignore-line` and friends) have no effect inside a template. Blade expands one template line into several lines of PHP, which leaves no reliable way to tell which template line such a comment was meant for, and guessing would silence a line you did not write it for. Use `ignoreErrors` in your config instead; it matches on the template path and message, both of which are exact.

## Credits

- [Can Vural](https://github.com/canvural), whose original package this one is based on
- [All Contributors](https://github.com/bladestan/bladestan/graphs/contributors)
