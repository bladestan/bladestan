# Upgrading Bladestan

## From 0.11 to the template-centric release

This release changes the unit of analysis from the `view()` call to the
template itself. Upgrading takes a one-time configuration step and a migration
pass to give each template a signature, most of which can be generated. This
guide explains the change and walks through both.

### What changed

Bladestan used to analyze a template at each `view()` call: it recompiled the
template with the types from that one call site and checked the result. A
template with no call site was never analyzed, and a template rendered from ten
places was recompiled ten times.

Now each template is compiled once to standalone PHP under `.bladestan` and
analyzed on its own, using the types it declares in a `@bladestan-signature`.
Call sites (`view()`, `@include`, Mailable content, and so on) are validated
separately against that same declared contract. The template is the unit of
analysis, the way a function is, and its signature is its parameter list.

The gain is that a template is now checked once against a stable contract, every
render site is validated against it, and the errors you see reflect the
template's own body rather than whichever call site happened to reach it. A
template nothing renders is still analyzed, and an error in a template rendered
from ten places is reported once, not ten times. In exchange, the types must be
declared: a template without a signature gives the
analyzer nothing to check, exactly as an untyped function does. Its variables
read as `mixed`, nothing on them can be verified, and at level 9 each one is
reported as possibly undefined. A template the old model typed implicitly from
its call sites therefore reports errors until it carries a signature, which is
what the steps below work through.

### Steps

1. **Point PHPStan at `.bladestan`, not your views.** Add the compiled-template
   directory to your analyzed paths, and add it to `.gitignore`. Do not add
   `resources/views`: it holds raw Blade, which PHPStan cannot read as PHP.
   Bladestan creates `.bladestan` for you on the first run.

   ```neon
   parameters:
       paths:
           - app
           - .bladestan
   ```

   ```gitignore
   .bladestan
   ```

   If `.bladestan` is missing from `paths`, call-site validation still works but
   template bodies are not analyzed, and Bladestan warns you so the omission is
   not silent. If a raw view directory ends up in `paths`, it warns about that
   too. To run call-site validation only and silence the first warning, set
   `parameters.bladestan.reportUnanalysedTemplates: false`.

2. **Generate a first pass of signatures.** Instead of writing every signature
   by hand, harvest the types your controllers already pass:

   ```bash
   php artisan bladestan:generate-signatures
   ```

   In a package (no `artisan` binary), run it through Testbench from the package
   root and point `--path` at your source:

   ```bash
   vendor/bin/testbench bladestan:generate-signatures --path=src
   ```

   Use `--dry-run` to preview and `--force` to overwrite existing signatures.

3. **Read errors against the `.blade.php` file.** Analyze with the Blade error
   formatter so errors point at the template and line, not the compiled PHP:

   ```bash
   vendor/bin/phpstan analyse --error-format=blade
   ```

   Bladestan reminds you to pass this when it sees compiled templates being
   analyzed without a chosen format.

4. **Fill the remaining gaps by hand, then generate again.** See the next
   section for what the generator handles and what it leaves for you. Because a
   partial's types can depend on a signature you write by hand, the loop is:
   generate, hand-sign the roots left as `mixed` (layouts, class-backed
   components, `@extends` parents), then re-run with `--force` so those types
   flow outward to everything downstream.

### What the generator covers, and what you sign by hand

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
  value where it has one, and left as `mixed` otherwise for you to fill in.

Left for you to sign by hand:

- **Class-backed and Livewire components.** Their public members are typed from
  the backing class automatically, so most need no signature at all. Add one only
  to type a prop, or to type the instance for a Livewire view resolved under a
  non-standard name (`@var \App\Livewire\Dashboard $this`).
- **Anonymous components without `@props`.** A component that reads passed
  attributes as bare variables (`{{ $model }}`) without declaring `@props` has
  nothing for the generator to scaffold from. Add a `@props` declaration, or a
  `@bladestan-signature`, listing the attributes it expects.
- **`@extends` layouts.** A layout receives the child's whole scope, so there is
  no explicit argument list to harvest. Sign the layout by hand; its contract is
  then merged with each child's, and call sites must satisfy both.

A generated signature typed entirely as `mixed` means the harvested type was
itself unresolvable at the call site. It still declares the input (so it is no
longer reported as undefined), but it checks nothing until you replace the
`mixed` with a real type. Below level 9 you can leave these as-is.

Expect a few new findings as types tighten. Once a variable is typed precisely
(a non-empty array, a non-null object), PHPStan can prove a guard around it is
always or never taken (`if.alwaysTrue`, `if.alwaysFalse`). That is correct, and
usually points at a check the template no longer needs, but it is normal to
adjudicate a handful of these after signing a large codebase.

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
