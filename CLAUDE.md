# Agent Guidelines for Bladestan

Bladestan is a PHPStan extension that runs static analysis on Blade templates in Laravel projects. It compiles each `.blade.php` template to plain PHP once, lets PHPStan analyze the compiled output as an ordinary file, and reports errors back against the original template. It also validates that every `view()` call and `@include` satisfies the target template's declared signature.

## Before You Start

Read these first to orient yourself:

- `private/ARCHITECTURE.md` for the template-centric design: how templates are compiled and analyzed, and how call sites are validated. This is the map for the whole extension.
- `config/extension.neon` for the service wiring and PHPStan registration. Everything the extension exposes is registered here.
- `src/Rules/ViewCallSiteRule.php`, the single PHPStan rule that validates `view()` and `@include` call sites.
- `src/Compiler/BladeToPHPCompiler.php`, which turns a template into standalone PHP (`compileStandalone()`).
- `src/PhpParser/BladeTemplateParser.php`, the seam that hands PHPStan that compiled PHP when it asks for a template's statements.

Note that `private/` (which holds `ARCHITECTURE.md`, `TODO.md`, task plans, upstream proposals and other notes), this file, and `phpstan-src/` are deliberately untracked: they are internal working documents. New plans and notes go in `private/`. Committed files (code, comments, README, CHANGELOG, commit messages) must never reference them, since no one else can see them or knows what they contain.

## Reference: phpstan-src

The `phpstan-src/` directory contains a checkout of PHPStan's own source. Bladestan lives inside PHPStan's analysis pipeline, so when you need to know how PHPStan actually behaves (how bootstrap files are executed, how the result cache decides staleness, how `paths` and `ignoreErrors` are resolved, how parallel workers are spawned), read the real code there rather than guessing. Several design decisions in this extension come directly from tracing that source (for example, bootstrap files run in every worker process, and extension-provided `paths` are ignored by file discovery). When a change depends on PHPStan internals, confirm the behaviour against `phpstan-src/` before relying on it.

## Project Structure

```
src/
├── Blade/
│   └── PhpLineToTemplateLineResolver.php   # Maps compiled PHP lines back to template lines
├── Bootstrap/
│   ├── ForkedWorkerDetector.php            # Recognises a forked worker, which inherits the main process's argv
│   └── UnanalysedTemplateDetector.php      # Warns when a view directory is missing from PHPStan's paths
├── Compiler/
│   ├── BladeToPHPCompiler.php              # Template → standalone PHP (compileStandalone)
│   ├── ComponentScopeResolver.php          # Types a component body: class members, Livewire state, @props
│   ├── FileNameAndLineNumberAddingPreCompiler.php  # Injects file/line comments for remapping
│   ├── LivewireTagCompiler.php             # Livewire component tag handling
│   ├── SignatureExtractor.php              # Reads @bladestan-signature / implicit docblocks / @extends
│   ├── SignatureMerger.php                 # Merges @extends chains (covariance rules)
│   └── TypeStringValidator.php             # Guards type strings before TypeStringResolver sees them
├── Console/
│   ├── GenerateBladeSignaturesCommand.php  # artisan bladestan:generate-signatures
│   └── Extraction/                         # The collectors + data rule the generator runs under the hood
├── Discovery/
│   └── TemplateDiscovery.php               # Finds all templates via Laravel's view finder
├── Laravel/
│   ├── ApplicationBooter.php               # Boots the project's Laravel app on demand, once per process
│   ├── BladestanServiceProvider.php        # Registers the artisan command in a Laravel app
│   └── View/BladeCompilerFactory.php       # Builds a Blade compiler outside a running app
├── NodeAnalyzer/                           # Matchers that recognise view()/Mailable/View::make call sites
├── PhpParser/
│   ├── ArrayStringToArrayConverter.php
│   ├── SimplePhpParser.php
│   ├── BladeTemplateParser.php             # Registered as PHPStan's pathRoutingParser; compiles a template on parse
│   └── NodeVisitor/                        # AST visitors used during compilation
│       ├── TemplateLineNumberNodeVisitor.php # Rewrites node lines from compiled PHP to template lines
│       └── TransformIncludesToViewCalls.php  # Rewrites compiled @include into view() call sites
├── PHPStan/
│   ├── BladeEnvironmentValueExtension.php    # Result-cache value: Blade config, shared/composer data, view finder
│   └── TemplateSignatureValueExtension.php   # Result-cache value: one template's signature slices
├── Rules/
│   └── ViewCallSiteRule.php                # The single PHPStan rule: validates call sites against signatures
└── ValueObject/
    ├── TemplateSignature.php               # A template's declared variable contract
    └── ...                                 # Compiled output, loop, and inlined-element value objects

tests/
├── Compiler/                               # Compiler unit tests + fixtures (input blade → expected PHP)
├── Rules/                                  # Rule tests with call-site fixtures and a shared neon config
├── skeleton/                               # A minimal Laravel view layout used as analysis input
│   ├── app/                                # Models and view classes the fixtures reference
│   └── resources/views/                    # Template fixtures (signed, implicit, extends, includes)
├── TestServiceProvider.php                 # Registers the skeleton view paths
└── laravel-test.sh                         # End-to-end run against a real project (Mailbook)
```

## Key Architectural Concepts

**Template-centric analysis.** Each template is compiled once to standalone PHP and analyzed on its own, using the types it declares. This is the inverse of the old call-site-centric model where a template was recompiled and re-analyzed at every `view()` call. See `private/ARCHITECTURE.md` for the full rationale and the comparison with the previous design.

**Signatures are the contract.** A template declares the variables it expects with a `@bladestan-signature` docblock (standard `@var` tags plus a marker line). `SignatureExtractor` reads it, `SignatureMerger` combines it with any `@extends` parent or backing component class, and the merged result is what call sites are validated against. A template without a signature gives the analyzer nothing to check, exactly as an untyped function gives PHPStan nothing to check.

**Compilation pipeline (`compileStandalone`).** Extract and strip the signature docblock, strip `@extends` (it is enforced by signature merging, not compiled), compile the Blade string, run the AST visitors (delete inline HTML, add loop var types, transform `@each`/`@include`), then rewrite compiled `@include` calls into `view()` call sites so the one rule validates them. `@extends` is deliberately not turned into a call site because Blade forwards the whole scope to the layout, which no explicit call could satisfy.

**Call-site validation (`ViewCallSiteRule`).** The matchers in `NodeAnalyzer/` recognise `view(...)`, `View::make(...)`, Blade view methods, and Mailable content. For each, the rule resolves the template file, reads its merged signature, and reports missing required variables and type mismatches. `@include` inside a compiled template is validated the same way.

**Templates are analysed as themselves.** PHPStan discovers `.blade.php` files from the user's `paths` (its `fileExtensions` match by suffix, so `*.php` covers them) and asks the routing parser for their statements. `BladeTemplateParser` is registered in place of PHPStan's `pathRoutingParser`, wrapping the original: a template is compiled and its node lines rewritten to template lines, everything else is routed untouched. Nothing is written to disk, so there is no compiled directory, manifest, or worker race.

**Error coordinates.** Because template lines are on the AST before PHPStan sees it, an error's own path and line are the template's. Every formatter, `--generate-baseline`, and `ignoreErrors` therefore work in template coordinates with no remapping step; there is no Bladestan error formatter. PHPStan's inline ignore comments are deliberately made inert inside a template (see `private/ARCHITECTURE.md`), since a compiled line cannot say which template line the comment meant.

**Caching.** PHPStan's own file hashing covers template edits and its dependency graph covers component classes the compiled output constructs. Everything else is declared per file through PHPStan 2.3's `DependencyTracker`, so a change re-analyses only the files that read it. `ViewCallSiteRule` and `TemplateSignatureMergeRule` declare the signature of every template in the `@extends` chain (`TemplateSignatureValueExtension`, hashing only the signature slices so body edits leave callers alone), the files the view finder would try first (so a missing or shadowing template appearing counts), and the view finder config. The compiler's other inputs (reflected backing classes, Blade config, shared and composer data) cannot be declared from the parser, so `BladeTemplateParser` relays them on the AST and `TemplateCompilationDependencyRule` declares them (`BladeEnvironmentValueExtension`). Any new compile-time input must be added to that relay, or its changes will leave stale results.

## Completing a Feature

### 1. Write the code

- New template-contract logic goes in `Compiler/` (`SignatureExtractor`, `SignatureMerger`) and `ValueObject/TemplateSignature.php`.
- Compilation changes go in `Compiler/BladeToPHPCompiler.php` and the AST visitors in `PhpParser/NodeVisitor/`.
- Call-site recognition goes in `NodeAnalyzer/`; validation logic goes in `Rules/ViewCallSiteRule.php`.
- Template-to-PHPStan plumbing (what PHPStan is handed for a template, and in which coordinates) goes in `PhpParser/BladeTemplateParser.php`.
- Register any new service in `config/extension.neon`. If a class is only referenced through the container (a rule, a formatter, a meta extension), add it to the `class-leak` skip list in `.github/workflows/code_analysis.yml`, since the leak checker cannot see container wiring.

### 2. Write tests

- Compiler tests live in `tests/Compiler/`. Input-to-output fixtures pair a Blade template with its expected compiled PHP.
- Rule tests live in `tests/Rules/` and extend PHPStan's `RuleTestCase`. Add a fixture `.php` file that makes the call, and assert the expected messages and lines.
- Template fixtures go in `tests/skeleton/resources/views/`. Models and view classes they reference go in `tests/skeleton/app/`.
- Test the contract from both ends: the compiled output of a template and the call-site validation against it. For `@extends`, cover the merge (a child that narrows a parent type, and a call site missing a parent-only variable).

### 3. Update user-facing docs

If the change affects how people install, configure, or annotate templates, update `README.md`. If it changes the design, update `private/ARCHITECTURE.md`. Follow the writing style in Conventions below.

Keep `CHANGELOG.md` current: add a line under `[Unreleased]` for any change a user would notice (a new capability, a changed default, a removed option, a fix to already-released behaviour). Write it as a general claim, not a checklist of sub-features, in the same voice as the rest of the file. Two things do not belong there: fixes to code that has never been released (from a user's view the bug never existed, so it is just part of the unreleased feature), and internal-only work (tests, refactors, dead-code removal, changes to the untracked planning docs). When in doubt about whether something is user-facing, it probably belongs in `README.md` rather than the changelog.

### 4. Run the checks

```bash
composer test        # phpunit
composer phpstan     # analyzes Bladestan's own source
vendor/bin/ecs       # coding standard (composer fix-cs to apply)
vendor/bin/rector --dry-run   # run without --dry-run to apply
```

Everything must pass with zero failures before the task is done. ECS and Rector both rewrite code, so run the fixing form and re-check rather than hand-editing around them. When a change is nontrivial and touches the compilation or validation path, also run the end-to-end script (`tests/laravel-test.sh` clones Mailbook and analyzes it) or at least a manual `phpstan analyse` against a real Laravel project, since the unit tests do not exercise parallel workers or the bootstrap.

## Discovered Bugs

If you find a bug while working, whether or not it relates to your task, fix it properly or note it clearly for the user. Do not paper over it with a workaround (renaming a variable to dodge a collision, suppressing a diagnostic, catching and swallowing an exception) and move on. A workaround that hides a real problem is worse than the problem, because the next person has to rediscover it.

## Conventions

- **No suppression to lower the error count.** Every error Bladestan emits must come from correct signature resolution and type analysis. Hiding a false positive by adding a special case, widening a type to `mixed`, or scoping an `ignoreErrors` entry to make a symptom disappear is not a fix. A tool that reports nothing has no false positives either. If an error fires incorrectly, fix the resolution that feeds it; if that is too large for the current task, tell the user rather than masking it. The scoped `ignoreErrors` in `extension.neon` exist only for identifiers that are genuinely meaningless in generated template code, and new entries there need the same justification.
- **Reuse the one validation path.** There is a single call-site rule and a single signature pipeline. When you need to answer "what does this template expect?" or "does this call satisfy it?", use `SignatureMerger` and `ViewCallSiteRule`. Do not add a second, lighter resolver that handles only today's case; it will diverge and silently miss every future fix. If the shared path lacks a capability, add it there so all call-site kinds benefit.
- **Docblocks on public API.** Public methods and their parameters carry PHPDoc, especially the type shapes PHPStan relies on (`array<string, string>`, `list<...>`, value-object shapes).
- **PHP resolution order.** When merging members or signatures, respect PHP's actual precedence: a class's own members over trait members over the parent chain over mixins; a child signature may narrow a parent's type but never widen it.

### User-facing writing style

These apply to `README.md`, `CHANGELOG.md`, `UPGRADE.md`, release notes, and any other documentation a user reads. Internal untracked documents (everything in `private/`, this file) are exempt.

- **Write for users, not for the code.** Describe what someone sees or does, not the internal class or method that makes it happen. Names like `SignatureMerger` belong in this file, not in the README.
- **Prefer general claims over checklists of sub-features.** Enumerating what works implies the unlisted parts do not. Write "`@include` is validated against the partial's signature" rather than listing every include variant. The reader should come away trusting the feature, not auditing it.
- **No em-dashes.** They read as machine-written. Use a period, a comma, parentheses, or start a new sentence. This is a hard rule for user-facing text.
- **Organize by impact.** Put what most users hit first (install, signatures, call-site errors) before edge cases (variance rules, third-party template overrides).
- **No transient identifiers in code or comments.** Describe the behaviour a piece of code handles, not a backlog ticket or a version it came from. The git history links a change to its reason.

### Terminal safety

Prefer the Write and Edit tools for creating or changing files rather than shell heredocs (`<< 'EOF'`) or long `echo`/`printf` pipelines. Heredocs can hang an interactive terminal, and the dedicated tools give the user a reviewable diff. Use the shell for running commands and for read-only inspection (`grep`, `sed`, `find`), not for authoring multi-line file content.
