# Student Activity for MasterStudy LMS

Answers one question MasterStudy LMS cannot: **is this student actually active?**

MasterStudy tells you which courses a student enrolled in. It does not tell you whether they ever
watched anything, or when. This plugin adds that — a sortable list of every student with their
last-active date, activity streak and membership status, plus a per-student timeline.

> Not affiliated with or endorsed by StyleMix Themes, the makers of MasterStudy LMS.

## The problem it solves

MasterStudy records lesson activity in two places, and both have a blind spot:

| What | Where | Blind spot |
|---|---|---|
| Lesson completed | `{prefix}stm_lms_user_lessons.end_time` | Only if the student clicks **Complete** |
| Lesson first opened | usermeta `stm_lms_course_started_{lesson}_{course}` | Written **once**, and **deleted** on completion |

`STM_LMS_Course::lesson_started()` writes the "opened" timestamp only if it is not already set, and
`STM_LMS_Lesson::complete_lesson()` deletes it on completion. So **a student re-watching material
they already finished leaves no trace at all.**

On the site this was built for, of 127 paying members: **33 looked like they had never touched a
lesson if you counted only completions — but only 8 genuinely never had.** The other 25 were
watching without ever clicking Complete.

This plugin backfills everything MasterStudy already knows, then records lesson views itself from
that point on — including the re-watches.

## What you get

**MasterStudy LMS → Student Activity**

- Every student with **last active**, **active days** (in each window), **lessons completed**,
  **courses touched** and **membership status**
- Status badges — Active / Slipping / Dormant / Never started, with configurable thresholds
- Summary tiles, and filters for status, membership, course and free-text search
- Per-student drill-down: per-course breakdown and a day-by-day activity timeline
- CSV export of whatever is currently filtered
- Paid Memberships Pro integration, when present, to separate paying members from lapsed ones

## Requirements

- WordPress 6.0 or newer (tested up to 7.1)
- PHP 7.4+
- MasterStudy LMS (free edition is enough — the hooks used are in the free plugin)
- Paid Memberships Pro *(optional)* — enables the membership column and filter

## Install

Download `student-activity-masterstudy-x.y.z.zip` from the
[latest release](https://github.com/POBrien333/student-activity-masterstudy/releases)
and upload it via **Plugins → Add New → Upload Plugin**.

> Use the release zip, not GitHub's "Source code (zip)". The auto-generated one
> unpacks to a folder named after the tag, which WordPress treats as a different
> plugin on every version.

1. Copy the plugin folder into `wp-content/plugins/`, or upload the zip via **Plugins → Add New**.
2. Activate. The table is created and a one-time import of your existing history is queued.
3. Open **MasterStudy LMS → Student Activity**. The import runs in the background via WP-Cron; a
   notice shows progress and there is a **Run import now** button if cron is unreliable on your host.

The import is idempotent — re-running it never duplicates anything.

## How it works

One table, `{prefix}mssa_activity`, holding one row per student per item per event type per day:

```sql
UNIQUE KEY uniq_daily (user_id, item_id, event, event_date)
```

That unique key is load-bearing rather than merely a constraint. Combined with `INSERT IGNORE` it:

- makes repeat views collapse into a single row, capping table growth,
- turns "distinct active days" into a plain `COUNT(DISTINCT event_date)`,
- and removes the need to check before writing, so a lesson view costs **at most one query**.

**Live capture** hooks `stm_lms_lesson_started`, which fires on every render of the course player
for a student with access — not just the first. That is what catches re-watches.

**Backfill** imports two passes: completions and first-opens from `stm_lms_user_lessons`, then
opened-but-never-completed from usermeta. The usermeta keys exist in two formats — MasterStudy
migrates an old `..._{course}_{lesson}` form to `..._{lesson}_{course}` lazily — so both are parsed.
On the reference dataset the legacy form was 63% of rows.

## Performance

The plugin is built for sites that are already heavy.

| Context | Cost |
|---|---|
| Any front-end page that is not a lesson | **0 queries, 0 CSS, 0 JS** |
| A lesson view | **1 `INSERT IGNORE`**, or 0 with a persistent object cache |
| Admin list, first view | 2 queries |
| Admin list, repeat sort / filter / page | **0 queries** (cached 12h) |

Admin CSS loads only on this plugin's own screen. There is no front-end asset, no external request,
and no scheduled recompute — the report is cached lazily on read, so it costs nothing on days you
do not open it.

If you install a persistent object cache (Redis, Memcached, APCu), repeat lesson views cost zero
writes rather than one.

## Privacy

All data stays in your own database. Nothing is sent anywhere. The plugin records which lesson a
logged-in student opened and when — no IP addresses, no user agents, no page-level tracking.

Deactivating changes nothing. Deleting the plugin keeps your data unless you tick **Delete the
activity table when this plugin is deleted** in Settings first, because view history cannot be
reconstructed once gone.

## Configuration

**MasterStudy LMS → Student Activity → Settings**

| Setting | Default | Notes |
|---|---|---|
| Active within | 30 days | |
| Dormant after | 90 days | Between the two is "Slipping" |
| Count opening a lesson | on | Turn off to count only completions. Recommended on — many students never click Complete |
| Delete data on uninstall | off | |

Thresholds are applied at query time, so changing them reclassifies everyone instantly with no
reprocessing.

## Contributing

Issues and pull requests welcome:
<https://github.com/POBrien333/student-activity-masterstudy>

The plugin is deliberately dependency-free — no Composer, no build step, plain PHP and one
stylesheet. Clone it straight into `wp-content/plugins/` and it runs.

Areas that would benefit most from other people's setups:

- Quizzes and assignments — the reference site uses neither, so those paths are lightly exercised
- WPML / multisite
- MasterStudy versions other than free 3.7.x / Pro 4.8.x

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
