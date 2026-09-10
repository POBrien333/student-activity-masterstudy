# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.2] — 2026-09-10

### Fixed
- Filtering the student list by course reported every student as having no
  activity at all. `$wpdb->prepare()` fills placeholders in SQL-text order, but
  the parameters were pushed in the order the clauses were assembled, so the
  course ID landed in a timestamp comparison and a timestamp landed in the course
  filter — which matched no rows. The unfiltered list was never affected.

### Added
- `tests/placeholder-order.php`, a dependency-free regression test asserting that
  every substituted value is the right *kind* of value across six query shapes.
  It fails against the 0.1.1 code and passes against this one.
- A CI workflow running that test plus a lint pass on PHP 7.4 and 8.3.

## [0.1.1] — 2026-08-27

Housekeeping release. No functional change: no SQL was altered, and the only
code edits are semantically identical ternaries, a removed dead method and an
internal parameter rename.

### Fixed
- Several `phpcs:ignore` comments sat above multi-line statements, where they
  only cover the single following line — so the violation on the line below was
  never actually suppressed. Now `phpcs:disable`/`enable` blocks spanning the
  whole statement, each documenting why the query is safe.
- `readme.txt` carried an invalid `Contributors` placeholder, which WordPress
  Plugin Check flagged.

### Removed
- `Schema::drop()`, which was dead code; its docblock claimed `uninstall.php`
  called it, but `uninstall.php` issues its own `DROP TABLE`.

## [0.1.0] — 2026-08-27

First public release.

### Added
- Student Activity admin screen: sortable list of every student with last-active date,
  active-day counts, lessons completed, courses touched and membership status.
- Status classification — Active / Slipping / Dormant / Never started — with configurable
  thresholds applied at query time, so changes reclassify instantly with no reprocessing.
- Live capture of lesson views via `stm_lms_lesson_started`, recording the re-watches
  MasterStudy itself discards.
- One-time batched import of existing history from `stm_lms_user_lessons` and the
  `stm_lms_course_started_*` usermeta keys, handling both the current and legacy key formats.
- Per-student drill-down with per-course breakdown and a day-by-day timeline.
- CSV export of the current filtered view, with spreadsheet formula-injection escaping.
- Paid Memberships Pro integration for the membership column and filter.
- Lazy 12-hour caching of report aggregates, with a Refresh control and a visible
  "computed X ago" indicator.
