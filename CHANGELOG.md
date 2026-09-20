# Changelog

All notable changes to this plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The section for each released version is published verbatim as the GitHub
Release body and shown under "View details" in the WordPress plugins screen,
so keep entries user-facing and concise.

## [Unreleased]

## [0.1.7] - 2026-09-19

- No functional changes; release used to verify the GitHub auto-update flow end to end.

## [0.1.6] - 2026-09-19

### Added
- Accommodation package and room labels are now translatable per language (Polylang), alongside the rest of the form content. Only labels are translated; keys, prices and capacities are untouched.

## [0.1.5] - 2026-09-19

### Fixed
- A registration is no longer charged for accommodation that was not granted. When a room slot is full, the registration is accepted without accommodation and priced for the registration type only (a companion is no longer double-charged).
- Promoting a waitlisted registration whose type was removed from the event configuration is now rejected instead of silently zeroing its price.

## [0.1.4] - 2026-09-19

### Added
- Companion person: an optional "companion" checkbox with a name field on the form. When a companion is present and accommodation is selected, two seats are taken from the accommodation pool (instead of one) and the accommodation price is doubled. Optionally the companion also counts toward the event capacity (configurable per event; never toward the registration-type limit). The companion appears in the submissions detail/edit screens, CSV export, confirmation mail, and the WordPress privacy export/erase tools.

## [0.1.3] - 2026-09-18

### Added
- Confirmation emails and their confirmation link are delivered in the language the registration was submitted in (Polylang): the mail template resolves per language and the link points to the correct-language page.

## [0.1.2] - 2026-09-18

### Added
- Multilingual foundation (Polylang): plugin UI strings are translation-ready (English catalog included), and per-event content — section, field, option and type labels — can be translated from a Translations tab in the event editor.

### Changed
- Plugin author set to Eorta.pl.

## [0.1.1] - 2026-08-24

### Added
- Conditional visibility: show or hide any field or section based on another field's value, configured in the form builder and mirrored live on the public form.
- Optional Bootstrap 5 rendering for the public form (opt-in global setting), alongside the existing `evreg-*` classes.

## [0.1.0] - 2026-08

### Added
- Initial internal release covering the full roadmap: event configuration (custom post type, REST API, React admin), public registration form with transactional seat reservation and double opt-in, transactional mail queue with templates and an admin queue screen, submissions panel with lifecycle actions, answer editing and CSV export, data-lifecycle/compliance tooling (gated uninstall, WordPress privacy export/erase), and self-hosted auto-update from GitHub Releases.

[Unreleased]: https://github.com/kobysz/event-registration-wp-plugin/compare/v0.1.7...HEAD
[0.1.7]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.7
[0.1.6]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.6
