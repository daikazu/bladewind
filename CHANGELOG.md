# Changelog

All notable changes to BladeWind are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-23

First public release.

### Added

- Per-page stylesheets: `@bladewindStyles` serves each page a shared root plus a page file holding only the rules that page can use, split in PHP from the stylesheet Vite already built.
- Compile-time analysis of Blade views: static `class` attributes, `@class` arrays, enumerable PHP expressions, `wire:*.class` directives, `wire:current`, Alpine `:class` bindings, and view and component dependencies.
- Framework drivers for Tailwind CSS 4 and 3, Tachyons, Bootstrap 5, Bulma 1 and Foundation for Sites 6, detected from the compiled stylesheet, plus support for custom drivers.
- Per-page shaking of Tailwind 4 theme variables, `--tw-*` defaults and `@property` registrations.
- `link` and `inline` delivery, with `wire:navigate` support.
- Fallback to the full stylesheet whenever page styles cannot be served, logged once per process.
- Debug panel and `X-BladeWind-Styles` header.
- `bladewind:analyze`, `bladewind:inspect` and `bladewind:clear` commands.
- `AssertsPageStyles` testing trait for host applications.
- Support for Livewire 4, Alpine and livewire/blaze when present.
- A Laravel Boost guideline and a `bladewind-development` agent skill.

[Unreleased]: https://github.com/daikazu/bladewind/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/daikazu/bladewind/releases/tag/v0.1.0
