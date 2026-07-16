# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Analysis is now template-centric. Each Blade template is compiled once to
standalone PHP under `.bladestan` and analysed on its own against the variables
it declares, instead of being recompiled and re-analysed at every `view()` call.
See [`UPGRADE.md`](UPGRADE.md) for the migration from 0.11.

### Added

- Templates declare their expected variables with a `@bladestan-signature`
  docblock, and every `view()`, `View::make()`, `@include`, and Mailable call
  site is validated against it. An `@extends` layout's signature is merged into
  its children, so call sites must satisfy both.
- `bladestan:generate-signatures` artisan command writes signatures from the
  types your controllers already pass. It also signs `@include` partials from the
  scope their includers forward, and scaffolds anonymous components from their
  `@props` declaration.
- `@includeFirst` call sites are validated, against the last (fallback) candidate
  in the view list.
- Blade error formatter (`--error-format=blade`) reports errors against the
  original `.blade.php` file and line.
- Warnings when `.bladestan` is missing from the analysed `paths`, when a raw
  view directory is analysed directly, and when compiled templates are analysed
  without a chosen error format.
- The signature generator reports how many generated signatures carry only
  `mixed` types, so load-bearing signatures are distinguished from those that
  still need real types.
- A signature guideline for AI coding agents, discovered automatically by
  Laravel Boost and available to copy for any other agent.

### Changed

- The `.bladestan` compiled-template directory must be among PHPStan's analysed
  `paths` for template bodies to be analysed. It is created automatically and
  only changed templates are recompiled on each run.

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

[Unreleased]: https://github.com/bladestan/bladestan/compare/0.11.6...HEAD
[0.11.6]: https://github.com/bladestan/bladestan/compare/0.11.5...0.11.6
[0.11.5]: https://github.com/bladestan/bladestan/compare/0.11.4...0.11.5
[0.11.4]: https://github.com/bladestan/bladestan/compare/0.11.3...0.11.4
[0.11.3]: https://github.com/bladestan/bladestan/compare/0.11.2...0.11.3
[0.11.2]: https://github.com/bladestan/bladestan/compare/0.11.1...0.11.2
[0.11.1]: https://github.com/bladestan/bladestan/compare/0.11.0...0.11.1
[0.11.0]: https://github.com/bladestan/bladestan/compare/0.10.0...0.11.0
[0.10.0]: https://github.com/bladestan/bladestan/compare/0.9.0...0.10.0
[0.9.0]: https://github.com/bladestan/bladestan/compare/0.8.0...0.9.0
[0.8.0]: https://github.com/bladestan/bladestan/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/bladestan/bladestan/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/bladestan/bladestan/compare/0.5.0...0.6.0
[0.5.0]: https://github.com/bladestan/bladestan/compare/0.4.1...0.5.0
[0.4.1]: https://github.com/bladestan/bladestan/compare/0.4.0...0.4.1
[0.4.0]: https://github.com/bladestan/bladestan/compare/0.3.1...0.4.0
[0.3.1]: https://github.com/bladestan/bladestan/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/bladestan/bladestan/compare/0.2.1...0.3.0
[0.2.1]: https://github.com/bladestan/bladestan/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/bladestan/bladestan/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/bladestan/bladestan/releases/tag/0.1.0
