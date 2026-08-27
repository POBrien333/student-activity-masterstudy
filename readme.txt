=== Student Activity for MasterStudy LMS ===
Contributors: (your wordpress.org username)
Tags: masterstudy, lms, student activity, elearning, engagement
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which students are actually active in MasterStudy LMS — last-active date, activity streaks and re-watches that MasterStudy itself never records.

== Description ==

MasterStudy LMS tells you which courses a student enrolled in. It does not tell you whether they ever watched anything, or when.

This plugin adds a Student Activity screen listing every student with their last-active date, how many days they were active, lessons completed, courses touched and membership status — plus a per-student timeline.

**The blind spot it closes**

MasterStudy writes a lesson's "opened" timestamp only once, and deletes it when the lesson is completed. A student re-watching material they already finished therefore leaves no trace at all. This plugin imports everything MasterStudy already knows, then records lesson views itself from that point on, including re-watches.

**Features**

* Status badges: Active / Slipping / Dormant / Never started, with configurable thresholds
* Summary tiles and filters for status, membership, course and free-text search
* Per-student drill-down with a per-course breakdown and day-by-day timeline
* CSV export of the current filtered view
* Paid Memberships Pro integration (optional) to separate paying members from lapsed ones
* One-time import of your existing MasterStudy history, run in the background

**Built for heavy sites**

Front-end pages that are not lessons cost zero queries and load no CSS or JavaScript. A lesson view costs at most one insert. The admin report is cached on read for 12 hours, so repeat sorting and filtering is free and nothing is recomputed on days you do not open it.

This plugin is not affiliated with or endorsed by StyleMix Themes, the makers of MasterStudy LMS. "MasterStudy" is their trademark, used here only to describe compatibility.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`, or install through Plugins → Add New.
2. Activate it. The activity table is created and a one-time import of existing history is queued.
3. Go to MasterStudy LMS → Student Activity.

The import runs via WP-Cron. A notice shows progress, and a "Run import now" button is available if cron is unreliable on your host. Re-running the import never duplicates data.

== Frequently Asked Questions ==

= Does this need MasterStudy LMS Pro? =

No. The hooks it uses are in the free edition.

= Will it slow my site down? =

Front-end pages other than lessons do no database work at all and load no assets. A lesson view costs one small insert, or zero if you run a persistent object cache. That is less than MasterStudy itself does on the same page render.

= Does it track visitors or send data anywhere? =

No. It records which lesson a logged-in student opened and when, and nothing else. No IP addresses, no user agents, no external requests. All data stays in your database.

= What happens to my data if I uninstall? =

Nothing is deleted unless you opt in first, under Settings → "Delete the activity table when this plugin is deleted". View history cannot be reconstructed once removed.

= Why do some events show no course? =

A small number of historical usermeta records point at courses that have since been deleted, so they cannot be attributed. The timestamp still counts toward activity, which is what the report needs.

== Screenshots ==

1. The Student Activity list with status tiles and filters.
2. Per-student drill-down with course breakdown and activity timeline.

== Changelog ==

= 0.1.0 =
* First public release.
