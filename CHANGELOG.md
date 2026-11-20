# Changelog

All notable changes to this plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The section for each released version is published verbatim as the GitHub
Release body and shown under "View details" in the WordPress plugins screen,
so keep entries user-facing and concise.

## [0.2.9] - 2026-11-20

### Fixed
- The admin now shows the full name of a registrant whose form asks for the first name and the surname in two separate fields. The registration list, the registration detail screen and the CSV export used to show only whichever of the two came first, because the stored name is a snapshot of a single form field. The name is now composed from the answers whenever it is displayed, so it is right on registrations that were already saved, and no data is rewritten. Fields are recognised by the first word of their key (`imie`, `nazwisko`, `first_name`, `last_name`, `surname`, and suffixed variants such as `imie_uczestnika`), given names always precede surnames, and a form with a single name field is unaffected. The `{imie}` placeholder in e-mails is unchanged and still carries the first name alone.

## [0.2.8] - 2026-11-19

### Changed
- The occupancy panel now shows how many registrations are taking a seat, on a row of its own above the seat count. The seat count row is named "Occupied seats", so the two read together: registrations plus companions make up the occupied seats. The registrations row counts confirmed and pending entries only, which is what its label says, because waiting-list and cancelled entries take no seat.

## [0.2.7] - 2026-11-19

### Fixed
- The occupancy panel no longer understates how full an event is. When companions count towards the event limit, the "Taking a seat" row counts them, the way the registration form has always counted them when accepting a registration. An event with 100 registrations and 47 companions and a limit of 180 used to report 80 seats free while registrations were in fact going to stop 47 people earlier; it now reports 33. The companions are still listed separately underneath. Nothing changes for an event where companions do not take a seat.
- The last free seat no longer invites a registration that cannot be accepted. When only one seat is left and companions take a seat of their own, the companion tick box is shown switched off and marked as having no room, instead of letting someone fill in the whole form and be refused after sending it.
- The two "event is full" messages shown to visitors had their default wording written down twice, once for the page and once for the settings screen. The settings screen now shows exactly the wording a visitor would see, so changing one can no longer leave the other out of step. Nothing changes for a site that has already written its own messages.

## [0.2.6] - 2026-11-19

### Added
- When an event has no seats left, visitors are now told before they fill in the form. If the waiting list is on, a notice explains that signing up puts them on it and the form stays available. If there is no waiting list, only the notice is shown and the form is hidden. Both texts can be edited in the event's settings and translated per language; leaving them empty uses the default wording.

### Fixed
- When a registration was refused because no seats were left, the form came back empty. It now keeps everything the visitor had typed.

### Removed
- The English translation files have been dropped. They had not been kept up to date for months, so a visitor on an English-language site already saw a mixture of English and Polish. Everything is now shown in Polish, consistently. Nothing changes on a Polish site.

## [0.2.5] - 2026-11-18

### Added
- Organizers can now add a registration by hand from the Registrations screen, for people who signed up by phone or e-mail and never filled in the form. The entry is confirmed straight away and never expires. No e-mail is sent by default; a tick box lets you send the attendee a confirmation. Seat limits still apply as usual: if the event or the chosen night is full, the entry goes onto the waiting list, and a waiting-list entry added by hand is confirmed without an e-mail when it later moves up. The attendee's e-mail address may be left blank. Whenever the entry is refused — no seats left for the chosen type, no beds left for the chosen night, or a save that failed — the form comes back with everything you typed still in it and says why, so a transcribed phone call is never lost.

### Changed
- The message shown when an event's form configuration cannot be read no longer claims the problem is in the accommodation settings, because it is also shown for other configuration faults.

## [0.2.4] - 2026-11-18

### Changed
- Event organizers are no longer caught by the registration form's anti-spam limit: anyone allowed to manage events can submit the form as often as they need, for example to enter attendees by hand.
- The anti-spam limit for everyone else is raised from 10 to 30 submissions per hour from one device, and a site can adjust it. A visitor who does reach it now sees a message saying the block lifts after an hour, instead of the generic spam notice.

## [0.2.3] - 2026-11-18

### Added
- A note under the "companion" tick box on the registration form. For each event, and for each language of a translated site, you can write one sentence there, for example saying whether a companion needs a registration form of their own. Basic formatting and links are allowed. Left empty, nothing is shown.

## [0.2.2] - 2026-11-18

### Fixed
- A registration form whose required field sits in a section or a field hidden by a visibility condition can be submitted again. Clicking the submit button did nothing at all — no message, no error, nothing saved — because the browser refuses to send a form that insists on an answer to a field nobody can see. A hidden field no longer insists on one, and the companion's name is asked for only while the companion box is both ticked and on screen.
- Switching an event's accommodation mode can no longer slip through while a registration with accommodation is being taken. The check for existing accommodation and the saving of the settings now happen together, under the same per-event lock the registration form takes, so a registration that lands at that exact moment either comes first and the mode change is refused, or waits and is counted. Before, such a registration could be accepted between the two steps and end up booked under a mode the refusal exists to prevent.
- Promoting a submission from the waiting list now tries to give it the accommodation it asked for. A waitlisted submission holds no booking — it takes up no place — so the room it chose was quietly dropped on promotion even when beds were free, and the admin was told only "Promoted". The promotion now books the chosen room at the current prices when there is room for it, and says the accommodation was lost when there is not, instead of saying nothing. A submission that asked for no accommodation is promoted as before. Accommodation never blocks the promotion itself: a room that has since been filled, taken off the list, or marked as a room for two while the submission has no companion is reported as lost rather than standing in the way, so marking a room as a room for two does not leave everyone who wanted it stuck on the waiting list. A promotion is still refused when the submission already holds a booking for a room now marked as a room for two, since that is a bed it was actually given — settle that one by editing the answers.
- The submission detail and edit screens now show the booked nights of an event whose accommodation field has been taken off the form. Which accommodation mode an event uses is a property of the event, so it is now read from the event's accommodation settings everywhere rather than from the form: an event that still has nights booked is no longer treated as if it used packages and rooms once the field is gone. The detail screen lists those nights, and the edit screen shows them beside the form, read-only, since there is no field left to render them in. The CSV and privacy exports already reported them.
- Editing a submission no longer destroys its accommodation when the accommodation field is hidden by a visibility condition. Changing the answer that condition depends on — typically the registration type — took the field out of the form mid-edit, and the booking (or the booked nights), its seats and its price quietly disappeared under "Submission answers updated". A waitlisted submission, which holds its accommodation only in its answers, lost it there the same way, so a later promotion had nothing left to grant. An edit that does not put the accommodation field in play now leaves the accommodation exactly as it was, while unticking it with the field visible still drops it on purpose.


### Changed
- Editing a submission is now refused, rather than saved with contradictory data, when the companion box is changed while the accommodation field is hidden by a visibility condition and the room already booked does not match the new choice. Before, such an edit saved a single room holding two people, or billed one person for two beds, under "Submission answers updated". The refusal explains that the room and the companion choice do not match. Settle it in two saves: first restore the answer that brings the accommodation field back and choose the room there, then change the other answer.
## [0.2.1] - 2026-11-17

### Fixed
- The nights list in the accommodation settings is laid out like the registration types and the rooms: each night is a card of its own, with space between it and the next one, instead of one unbroken column of fields.
- A single event with leftover accommodation data can no longer stop the e-mail queue. Building a confirmation for such an event failed, and because the queue is driven by cron the same message was retried every few minutes forever, which held up confirmations for every other event too. The accommodation line now falls back to the names stored with the booking.

## [0.2.0] - 2026-11-17

### Added
- A second accommodation mode, "nights", chosen per event. Instead of packages and room types, you list the nights and give each a price, a number of seats and, optionally, a limit on registrations that bring a companion. Participants simply tick the nights they want.
- Nights with no seats left are shown as unavailable, and a night that has a seat but no room for two people is closed only while "companion" is ticked and opens again when it is unticked. A night that gets closed after you ticked it is unticked with a note, so nobody submits believing they still hold it.
- A registration that cannot get all of its ticked nights is refused as a whole rather than half booked. The nights appear in the admin detail and edit screens, the CSV export ("Nights"), the confirmation e-mail and the translations.
- The mode cannot be switched once an event has accommodation registrations.

### Fixed
- Editing a submission is now refused, naming the night, when any of its booked nights has since been removed from the event — including when the removed night was its only one. Before, the night, its seat and its price quietly disappeared the next time anyone saved that submission. The refusal explains that the night is gone rather than full, and says to either restore it in the event's accommodation settings or untick every night to drop the accommodation on purpose. An edit that touches neither the nights nor the companion box still saves, since it changes nothing about the accommodation.
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

[Unreleased]: https://github.com/kobysz/event-registration-wp-plugin/compare/v0.2.9...HEAD
[0.2.9]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.9
[0.2.8]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.8
[0.2.7]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.7
[0.2.6]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.6
[0.2.5]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.5
[0.2.4]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.4
[0.2.3]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.3
[0.2.2]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.2
[0.2.1]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.1
[0.2.0]: https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v0.2.0
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
