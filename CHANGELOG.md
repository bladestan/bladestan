# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Analysis is now template-centric: each Blade template compiles once and is
analysed on its own, against the variables it declares, instead of being
recompiled and re-analysed at every `view()` call. A template rendered from
many places is now checked once instead of once per call site, which is
considerably faster, and two call sites passing different types can no longer
produce contradictory errors on the same line. A template nothing renders is
analysed too. See [`UPGRADE.md`](UPGRADE.md) for migrating from 0.11.

### Added

- Templates declare their expected variables with a `@bladestan-signature`
  docblock, validated at every `view()`, `View::make()`, `@include`,
  `@includeFirst`, and Mailable call site.
- `bladestan:generate-signatures` artisan command to generate signatures from
  existing usage.
- Errors are now reported directly against the `.blade.php` file and line
  where they occur, instead of at the `view()` call site, so
  `--error-format=blade` lists each template as its own entry instead of
  bucketing several templates' errors under one caller.
- A template that fails to compile is reported as an error against the
  template, instead of dropping out of analysis without a trace.
- Bladestan warns when `.bladestan` is missing from PHPStan's analysed
  `paths`, or when a raw view directory is left in `paths` instead; the first
  warning can be silenced with `parameters.bladestan.reportUnanalysedTemplates: false`.
- Bladestan reminds you to pass `--error-format=blade` when it sees compiled
  templates analysed without a chosen error format; the same setting silences
  this reminder.
- AI agent guidance for writing signatures, discoverable via Laravel Boost.

### Changed

- `.bladestan` (the compiled template directory) must now be included in
  PHPStan's analysed `paths`.

### Fixed

- A string literal in a template whose text resembles a `use` statement is no
  longer corrupted during compilation.
- A component tag attribute whose value contains a bracket, such as a
  Tailwind arbitrary-value class, no longer truncates the component's data
  and fails to compile.
- A component whose data cannot be parsed is reported as an error against the
  template instead of aborting analysis with no file attributed.
- A `<livewire:...>` tag attribute written in kebab-case is camelized to
  match the property Livewire actually sets at runtime.
- A `@livewire` or `<livewire:...>` tag with a dynamic component name is
  skipped instead of aborting analysis of the whole template.
- A Livewire component is resolved to the class Livewire itself would render,
  so a component registered outside `livewire.class_namespace` is analysed
  against the class that actually backs it instead of one that does not exist.

## [0.11.7] - 2026-07-18

### Fixed

- Ignore `@bladestan-signature` docblocks instead of failing on them, so a template can be
  annotated ahead of the template-centric 0.12 release while still analysing cleanly on 0.11.

## [0.11.6] - 2026-07-17

The final 0.11.x release before the template-centric rewrite, focused on keeping
projects that are not ready to upgrade stable.

### Added

- Laravel 13 support

### Fixed

- Compile templates to the same PHP regardless of the installed Livewire
  version, so analysis is consistent across Livewire 3 and 4

## [0.11.5] - 2026-03-16

### Fixed

- Ensure PHPStan's result cache is invalidated when any Blade template changes (#174, @AJenbo)
- Fix broken `use` keyword in import bubble-up (#176, @rikvdh)

## [0.11.4] - 2026-01-16

### Added

- Support Livewire 3.3.x and Symfony 8 (#169, @AJenbo)
- Support Livewire 4.0 (#172, @AJenbo)

## [0.11.3] - 2025-06-05

### Changed

- Use the error identifier to match ignored errors (#160, @AJenbo)
- Respect configured rules (#161, @AJenbo)

## [0.11.2] - 2025-03-10

### Fixed

- Add a workaround for a bug in Laravel Telescope
- Fix parsing of `@include` where arrays or values from arrays are used (#158, @calebdw)

## [0.11.1] - 2025-02-27

### Added

- Laravel 12 support

## [0.11.0] - 2025-02-19

### Added

- Support for data provided via `->with*()` on all view methods
- Support for non-HTML mail templates
- Analyse `Facades\Response::view()`
- Read template paths in full from the application
- Read the Livewire component namespace from the application config
- Read shared and event data from the application
- Support PHP 8 syntax in templates

## [0.10.0] - 2025-02-11

### Added

- Recognise that public properties are passed to views (@spawnia, @AJenbo)
- Improved Livewire support
- Use the application's `BladeCompiler` (@aoi, @AJenbo)

### Fixed

- Fix reading data from variables
- Fix `->with*()` affecting more than the view instance it was called on
- Fix PHP 8.1 support
- Fix typos (@szepeviktor)

## [0.9.0] - 2025-02-07

### Added

- Support for `$errors`, `@once`, `@each`, `@includeWhen`, and `@includeUnless`
- Support for analysing Laravel packages
- Support for `renderEach()` and `first()` on `View\Factory`
- Analyse more of Blade's internal scope

### Changed

- More robust parsing

## [0.8.0] - 2025-01-22

### Added

- Support for non-terminated expressions
- Support for `use` statements in partials
- Support for dynamic method calls
- Support for dynamic components
- Analyse anonymous components
- Analyse use of components
- Analyse use of Livewire
- Analyse `@extends` and other indirect statements
- Analyse all statements on a line
- Report missing templates
- Report template syntax errors

### Fixed

- Correct the reported template line
- Correct some typos (#121, @szepeviktor)
- Update formatter output to align with the latest PHPStan

## [0.7.0] - 2024-12-17

### Changed

- Migrate to PHPStan 2.0 (#119, @AJenbo)

### Fixed

- Correct cases that were not being analysed (#120, @ondrejmirtes)

## [0.6.0] - 2024-09-04

### Added

- Display an error tip (#95, @ngmy)
- Basic support for components and Livewire attribute validation (#98, @robchett)
- Parse view data when it is a variable (#108, @mrhn)
- Support `\Illuminate\Support\Facades\View::make` (#115, @williamdes)
- Support Mailable `content()` (#114, @williamdes)
- Support `Arrayable::toArray()` as data (#116, @AJenbo)

### Fixed

- Fix the `$this` type (#90, #99, @robchett)
- Remove unnecessary ignores in `ErrorFilter.php` (#105, @AJenbo)

## [0.5.0] - 2024-01-10

### Added

- Support for `MailMessage::view()` (#78, @AJenbo)
- Recognise calling `view()` with a single argument (#80, @spawnia)

### Changed

- Drop Laravel 8 and allow Laravel 11 (#93, @TomasVotruba)
- Decouple internals (#88, @staabm)

### Fixed

- Fix a false positive when echoing `Htmlable` objects (#91, @AJenbo)

## [0.4.1] - 2023-08-12

### Fixed

- Resolve a Larastan conflict in `BladeToPHPCompiler` (#76, @AJenbo)

## [0.4.0] - 2023-07-26

### Added

- Support for `Mailable::view()` (#62, @AJenbo)
- Basic support for components (#61, @AJenbo)

### Changed

- More flexibility in template spacing (#72)

## [0.3.1] - 2023-05-04

### Fixed

- Recursively fetch and compile includes (#52, @AJenbo)

## [0.3.0] - 2023-05-04

### Added

- Import mapped variables into scope (#48, @AJenbo)
- Support passing variables via `compact()` (#47, @AJenbo)

### Fixed

- Fix `$loop` in nested `@foreach` (#53, @AJenbo)
- Create a new `$loop` rather than replacing it with a docblock (#50, @AJenbo)
- Fix warnings about empty lines in generated PHP (#49, @AJenbo)
- Fix processing `@include` with data given in a variable (#46, @AJenbo)

## [0.2.1] - 2023-03-26

### Changed

- Simpler Blade error formatter

## [0.2.0] - 2023-03-25

### Fixed

- Fix the regex handling `e()` output (#16)
- Various false-positive suppressions and parsing robustness

## [0.1.0] - 2023-03-16

Initial release. Bladestan compiles each Blade template to PHP, runs PHPStan over
the result, and maps the errors back to the original template. Based on earlier
work by Can Vural (see Credits in the README).
