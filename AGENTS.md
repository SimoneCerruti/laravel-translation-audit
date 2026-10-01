# Laravel Translation Audit

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `simonecerruti/laravel-translation-audit`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer qa`
- Pest tests: `composer test`
- Type coverage: `composer coverage`
- Static analysis: `composer stan`
- Formatting check: `composer pint:dry`
- Formatting fix: `composer pint`
- Rector check: `composer rector:dry`
- Rector fix: `composer rector`
- Workbench build: `composer build`
- Workbench server: `composer serve`
- Workbench audit: `composer workbench:audit -- <options>` (syncs the skeleton, then runs `translation:audit --follow-links`)

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
