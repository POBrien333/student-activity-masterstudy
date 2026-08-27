# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
this project adheres to [Semantic Versioning](https://semver.org/).

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
