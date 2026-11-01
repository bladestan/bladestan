# Upgrading Bladestan

## From 0.11 to the template-centric release

This release changes the unit of analysis from the `view()` call to the
template itself. Upgrading takes a one-time configuration step and a migration
pass to give each template a signature, most of which can be generated. This
guide explains the change and walks through both.

### What changed

Bladestan used to analyze a template at each `view()` call: it recompiled the
template with that call site's types and checked the result. A template
rendered from ten places was recompiled and checked ten times, once per call
site, and because each check only saw one site's types, two call sites
passing different types could produce contradictory errors on the same line
(one report says a check is always true, another reports the same line as always
false).

Now each template is analyzed on its own, once, using the types it declares in
a `@bladestan-signature`.
Call sites (`view()`, `@include`, Mailable content, and so on) are validated
separately against that same declared contract. The template is the unit of
analysis, the way a function is, and its signature is its parameter list.

This removes the contradictory-error class above, and a template nothing
renders is analyzed too. It's also considerably faster: a layout rendered from
every page used to be recompiled and re-checked at every one of those call
sites, and now it's compiled and checked once no matter how many places render
it. In exchange, types must be declared: a template without a signature gives
the analyzer nothing to check, exactly as an untyped function does. Its
variables read as `mixed`, and PHPStan's normal rules for `mixed` apply from
there. A template the old model typed implicitly from its call sites will
report new findings until it carries a signature, which is what the steps
below work through.

### Steps

1. **Add your view directory to PHPStan's paths.** Templates are analyzed as
   themselves, so PHPStan has to be looking at them.

   ```neon
   parameters:
       paths:
           - app
           - resources/views
   ```

   If a view directory is missing from `paths`, call-site validation still works
   but template bodies are not analyzed, and Bladestan warns you and names the
   directory. To run call-site validation only and silence that warning, set
   `parameters.bladestan.reportUnanalysedTemplates: false`.

2. **Generate a first pass of signatures.** Instead of writing every signature
   by hand, you can harvest the types your controllers already pass using the
   provided Artisan command:

   ```bash
   php artisan bladestan:generate-signatures
   ```

   In a package (no `artisan` binary), run it through Testbench from the package
   root and point `--path` at your source:

   ```bash
   vendor/bin/testbench bladestan:generate-signatures --path=src
   ```

   Use `--dry-run` to preview and `--force` to overwrite existing signatures.

3. **Drop `--error-format=blade`.** Errors are reported against the
   `.blade.php` file and line by every formatter now, so the dedicated Blade
   formatter has been removed. If your scripts or CI pass
   `--error-format=blade`, or set `errorFormat: blade` in the config, change it
   to the format you actually want (or remove it for the default table). An
   existing baseline should be regenerated, since its entries point at the old
   compiled paths.

4. **Fill the remaining gaps, then run `generate-signatures` again.**
   Because a partial's types can depend on a signature that's still missing,
   the loop is: generate, add signatures for the roots left as `mixed`
   (layouts, class-backed components, `@extends` parents), then re-run with
   `--force` so those types flow outward to everything downstream.

### What the generator covers, and what still needs a signature

The generator harvests real inferred types, so it can only type what analysis
already understands. It covers the common cases and leaves the rest clearly
untyped (as `mixed`) rather than guessing.

Covered automatically:

- **Render call sites.** `view()`, `View::make()`, and Mailable content are
  read for the types they pass. A view rendered from several sites takes the
  union, and a variable some sites omit is made nullable.
- **`@include` partials.** A partial reached through `@include` is typed from the
  variables its including templates forward to it. This runs in passes, so a
  partial included by another partial is typed once its includers are.
- **Anonymous components with `@props`.** Each prop is typed from its default
  value where it has one, otherwise left as `mixed` for you to fill in.

Still needs a signature:

- **Class-backed and Livewire components.** Their public members are typed from
  the backing class automatically, so most need no signature at all. Add one only
  to type a prop, or to type the instance for a Livewire view resolved under a
  non-standard name (`@var \App\Livewire\Dashboard $this`).
- **Anonymous components without `@props`.** A component that reads passed
  attributes as bare variables (`{{ $model }}`) without declaring `@props` has
  nothing for the generator to scaffold from. Add a `@props` declaration, or a
  `@bladestan-signature`, listing the attributes it expects.
- **`@extends` layouts.** A layout receives the child's whole scope, so there is
  no explicit argument list to harvest. Give the layout its own signature; it's
  then merged with each child's, and call sites must satisfy both.

A generated signature typed entirely as `mixed` means the harvested type was
itself unresolvable at the call site. It still declares the input (so it is no
longer reported as undefined), but it checks nothing until you replace the
`mixed` with a real type.

Expect a few new findings as types tighten. Once a variable is typed precisely
(a non-empty array, a non-null object), PHPStan can prove a guard around it is
always or never taken (`if.alwaysTrue`, `if.alwaysFalse`). That is correct: the
check was never needed, it just was not visible until the variable had a real
type. Adjudicating a handful of these is a normal part of signing a large
codebase.

### For AI coding agents

Bladestan ships a signature guideline that [Laravel Boost](https://laravel.com/docs/boost)
loads when you run `boost:install`; running `boost:update --discover` after
installing Bladestan offers to add it to an existing setup. This needs a Boost
version new enough to discover third-party package guidelines; if `--discover`
does not pick it up, copy the block by hand as below. For any other agent
(Claude, Cursor, and the like), copy the block from
[`resources/boost/guidelines/core.blade.php`](resources/boost/guidelines/core.blade.php)
into your project's agent guidelines. It captures the rules that keep
hand-written and agent-written signatures correct and parseable: type from real
inferred types rather than guessing, declare all variables (nullable if they may
be absent), use fully-qualified names, and verify with `--error-format=blade`.
