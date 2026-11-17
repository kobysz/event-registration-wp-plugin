# Changelog

All notable changes to this plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The section for each released version is published verbatim as the GitHub
Release body and shown under "View details" in the WordPress plugins screen,
so keep entries user-facing and concise.

## [Unreleased]

### Added
- A second accommodation mode, "nights", chosen per event. Instead of packages and room types, you list the nights and give each a price, a number of seats and, optionally, a limit on registrations that bring a companion. Participants simply tick the nights they want.
- Nights with no seats left are shown as unavailable, and a night that has a seat but no room for two people is closed only while "companion" is ticked and opens again when it is unticked. A night that gets closed after you ticked it is unticked with a note, so nobody submits believing they still hold it.
- A registration that cannot get all of its ticked nights is refused as a whole rather than half booked. The nights appear in the admin detail and edit screens, the CSV export ("Nights"), the confirmation e-mail and the translations.
- The mode cannot be switched once an event has accommodation registrations.

### Fixed
- Editing a submission is now refused, naming the night, when one of its booked nights has since been removed from the event. Before, the night, its seat and its price quietly disappeared the next time anyone saved that submission.
- Ticking a night that no longer exists alongside one that does is now refused instead of being accepted without the missing night.
- The CSV export and the privacy data export now report the booked nights even when the accommodation field has been taken off the form.
- The translations tab no longer asks for translations of packages and room types on an event that uses nights (and the other way round).
- Editing a submission that has accommodation no longer shows the accommodation as unselected. The stored answers kept the selection under internal names the edit screen did not read, so the form opened with no room chosen and saving could drop the booking and lower the price. Submissions saved before this fix are read correctly too.

## [0.1.17] - 2026-11-16

### Changed
- The companion name is now marked as required, with the browser asking for it before the form is sent, as soon as the "companion" box is ticked. It is only required while that box is ticked, and it now has a visible label instead of placeholder text only.
- The companion block has room above and below it, so the checkbox no longer sits against the accommodation list.

## [0.1.16] - 2026-11-16

### Added
- Ticking "companion" now also closes the accommodation options that have only one seat left, since a companion needs two.
- When a waitlisted registration is promoted but its accommodation is no longer available, the admin notice now says so.
- Each room can now be set to hold one or two people in the accommodation settings. The number of people drives the seats the room takes from its slot and the price multiplier.
- The "companion" checkbox now sits above the accommodation list and filters it: with a companion only two-person rooms are offered, without one only single rooms (rooms with no number set stay available either way).
- Promoting a waitlisted registration is now refused, with an explanation, when its room no longer matches the companion choice.

### Fixed
- A room and companion combination that does not match (a two-person room without a companion, or a companion in a single room) is now rejected on the public form and when a registration is edited, so seats and price can no longer drift apart.
- An accommodation option that has run out of seats is now shown as disabled with a "no seats" note instead of looking available.
- A registration whose chosen accommodation filled up in the meantime is no longer accepted without it. The form comes back with the entered data and an explanation, so nobody is confirmed for a bed that does not exist.
- Saved field translations (and mail templates) are no longer erased when the event configuration is saved. On a freshly opened event screen the configuration response could overwrite the translations the editor had already loaded, and the next save then wrote that empty value over the stored one.
- Translations and mail templates are now sent only when they were loaded and actually edited, and their endpoints refuse a save that would replace stored content with nothing.
- The translations and mail templates tabs now refuse editing when their stored content failed to load, instead of letting the next save overwrite it.

## [0.1.15] - 2026-11-16

### Added
- Each choice option (single choice, multiple choice, dropdown) can carry its own description, shown under that option only once it is selected — useful for a note or a link to an external page that is relevant to one answer. Option and field descriptions now accept simple HTML such as links and bold text; scripts are stripped.

## [0.1.14] - 2026-09-24

### Fixed
- A booking now stores the package and room names it was made under, so a submission keeps showing readable accommodation even after that room or package is renamed or removed from the event configuration. Previously such a submission fell back to the internal key (for example "room_1"). Applies to the submission detail, CSV export, confirmation mail and the privacy export; older bookings keep falling back to the current configuration.

### Changed
- The accommodation editor now warns that changing a package or room key disconnects existing bookings.

## [0.1.13] - 2026-09-24

### Fixed
- The submission detail screen now shows the accommodation package and room by name instead of their internal keys (for example "Noc 1–2 — Pokój 2-osobowy" instead of "pkg_1, room_1,"), both in the answers table and in the accommodation section. An unknown key still falls back to the raw key rather than showing nothing.

## [0.1.12] - 2026-09-24

### Fixed
- Updating the plugin no longer leaves a phantom "update available" notice behind. WordPress re-checks for updates in the same request that swaps the plugin files, while the previous version's code is still in memory; the comparison now reads the version from disk. The fix shipped in 0.1.11 but could only take effect from this update onwards.

## [0.1.11] - 2026-09-23

### Fixed
- The plugins screen now offers "Enable auto-updates" for this plugin. The update check reports the plugin as up to date instead of reporting nothing, which WordPress treated as "this plugin does not support updates".
- A stale "update available" entry is now cleared on the next update check, and the installed version is re-read from disk when comparing it against the latest release.

## [0.1.10] - 2026-09-23

### Added
- Registration overview on the Submissions screen: pick an event in the filter to see, at a glance, how many registrations occupy a seat against the event limit, the per-type and per-room occupancy, waitlisted and cancelled counts, companions, and the total amount.

## [0.1.9] - 2026-09-20

### Added
- Optional short label per form field, used as the column header in the CSV export instead of the full label (handy for long consent/legal fields). Translatable per language.
- Optional help text per form field, shown to the user below the field on the public form (and in the admin edit screen). Translatable per language.

## [0.1.8] - 2026-09-20

### Changed
- Update details shown on the WordPress plugins screen now come from the changelog instead of a raw commit list.

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

[Unreleased]: https://github.com/kobysz/event-registration-wp-plugin/compare/v0.1.17...HEAD
[0.1.17]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.17
[0.1.16]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.16
[0.1.15]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.15
[0.1.14]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.14
[0.1.13]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.13
[0.1.12]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.12
[0.1.11]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.11
[0.1.10]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.10
[0.1.9]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.9
[0.1.8]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.8
[0.1.7]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.7
[0.1.6]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.1.6
