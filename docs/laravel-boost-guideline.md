# Bladestan signature guideline

A short guideline for AI coding agents (Laravel Boost, Claude, Cursor, and the like) so they author Bladestan signatures from real inferred types instead of guessing. Copy the block below into your project's agent guidelines, or point your agent at this file.

```md
## Bladestan signatures

Every Blade template that receives data declares a signature so PHPStan can
analyse it. Without one, every variable reads as `mixed` and every access is an
error.

- Put one `@bladestan-signature` docblock at the top of the template, before any
  markup or `@extends`:

      @php
      /**
       * @bladestan-signature
       * @var \App\Models\User $user
       * @var ?\App\Models\Post $post
       */
      @endphp

- Declared variables are required. If one may be absent, type it `?T` and pass
  `null` explicitly. There is no "optional".
- Do not guess types. Find the real type: add `\PHPStan\dumpType($data)` (or
  `\PHPStan\dumpType(get_defined_vars())`) just before the `view()` call, run
  `vendor/bin/phpstan analyse <controller>`, read the dumped array shape, then
  remove the dump. Or run `php artisan bladestan:generate-signatures`, which does
  this across the whole app for you.
- Use fully-qualified class names, so the template needs no `use` import.
- Write valid PHPDoc types. Some forms `dumpType` prints cannot be written back
  verbatim, such as a template placeholder (`TModel (class ..., argument)`) or an
  accessory type (`hasOffsetValue(...)`); simplify these to a concrete type. A
  type Bladestan cannot parse is reported against the template that declares it
  and never affects any other result.
- `@include` partials read the including template's scope, exactly as they do at
  runtime. A partial declares in its own signature every variable it uses; scope
  travels one level per signature, so if a partial forwards a variable to a
  deeper `@include` without using it, declare that variable too.
- Components need no signature: `$slot`, `$attributes`, `@props`, and a class or
  Livewire component's public members are typed automatically. Only add a
  signature to type a prop, or, for a Livewire view resolved under a non-standard
  name, to type the instance: `@var \App\Livewire\Dashboard $this`.
- Verify with `vendor/bin/phpstan analyse --error-format=blade`, which reports
  against the `.blade.php` file and line.
```
