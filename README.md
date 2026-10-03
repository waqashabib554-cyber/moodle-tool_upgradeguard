# Upgrade Guard (tool_upgradeguard)

Find out what can break before you upgrade: scan your plugins, get a readiness
score and a clear list of what to do next.

Upgrade Guard is an admin tool for Moodle. It looks at the plugins that are
installed **today** and estimates what happens to them if the site is upgraded
to a newer Moodle branch.

## What it tells you

- A **readiness score** from 0 to 100 and a **verdict**: Ready to upgrade,
  Careful or Not yet.
- A **status per plugin**: Ready, Update available, Caution, Blocker or Unknown.
- The **reason** for every status and the **next step** to take.
- Whether the plugin is actually **used** anywhere on the site.
- For Moodle 5.1 and later: whether a plugin still sits **outside the public
  directory** and therefore has to be moved by hand.

## Screenshots

Six screenshots of a completed scan are in `docs/screenshots/`, captured on an
isolated Moodle 5.1.3 staging site against Moodle 5.2:

| Screenshot | Shows |
|---|---|
| `01-dashboard-overview.png` | The verdict, score and recommended next actions |
| `02-plugins.png` | The scanned plugin inventory and compatibility findings |
| `03-environment.png` | PHP, database and required-extension checks against Moodle 5.2 |
| `04-files-to-move.png` | The public-directory move list |
| `05-checklist.png` | The pre-upgrade checklist |
| `06-history.png` | The completed scan in scan history |

The scan completed with a Careful verdict (78/100) and 9 findings across 12
plugins. PHP, MySQL and all required extensions passed. These are genuine
product-evidence captures from an isolated staging site; review the staging
label and plugin names before using them in the Marketplace listing.
`docs/screenshots/README.md` records their provenance.

## How a scan works

1. You pick the target Moodle version on the dashboard and start a scan.
2. The scan is queued as an **ad hoc task**, so it runs in cron and never blocks
   a web request.
3. The task collects the plugin inventory, measures usage, optionally asks
   moodle.org for available updates, runs the checks and stores the report.
4. The dashboard shows the report; the same data can be exported as CSV.

A scan is claimed with a lock and an atomic status change, so two cron runners
can never work on the same scan, and the whole report is written in one
transaction.

## Requirements

- Moodle **4.4 to 5.2** (`$plugin->supported = [404, 502]`), including the
  public directory layout that Moodle 5.1 introduced.
  Moodle itself has ended support for 4.4, so that end of the range is a
  lower bound on what the plugin *runs* on, not a claim that Moodle still
  supports it. See *Limitations*.
- **PHP 8.1** or later.
- Working **cron**. A scan is an ad hoc task and the cleanup job is a scheduled
  task, so without cron a scan stays queued and nothing else happens. The
  scheduled rules task checks a configured rules feed at most every six hours.
- No extra PHP extension. The optional plugin update lookup against moodle.org
  is controlled by `checkremote`; refreshed target rules use the optional,
  maintainer-published public GitHub feed.

## Installation

1. Put the plugin folder at `admin/tool/upgradeguard` of your Moodle
   installation, so that `admin/tool/upgradeguard/version.php` exists.
2. Open *Site administration → Notifications* (or *Plugins → Plugins
   overview*) and complete the installation. Moodle creates the three tables,
   registers the capabilities, the scheduled cleanup task and the settings.
3. Open *Site administration → Plugins → Admin tools → Upgrade Guard*.
4. Pick an available newer Moodle version and click **Start scan**. If there is
   no verified newer target yet, the dashboard says why and shows the active
   rules version and update status. A new Moodle release is made selectable
   only after its rules have been reviewed and published by the maintainer.
5. Wait for cron to run the scan (or run
   `php admin/cli/adhoc_task.php --execute`), then open the dashboard again.

### Automatic target rules

Target rules are bundled with the plugin so scans do not depend on a live
service. When the maintainer's public repository is configured under *Site
administration → Plugins → Admin tools → Upgrade Guard settings*, Moodle cron
checks the HTTPS raw GitHub JSON feed hourly, and the updater makes an outbound
request no more than once every six hours. The request downloads only the public
rules file; no site URL, plugin inventory or scan data is sent. GitHub still
receives ordinary connection metadata such as the Moodle server's IP address.
Rules are accepted only when the complete dataset passes schema, version,
branch and status validation and is not older than the installed dataset. If
the feed is unavailable or invalid, the bundled or last-known-good rules remain
active. The maintainer must still verify and publish rules for a new Moodle
release; sites receive them automatically after publication and their next
cron run. Every feed change must increment `datasetversion`.

The feed is the repository's `data/targets.json`, exposed through its public
`raw.githubusercontent.com` URL. New installations default to the maintainer's
feed at
`https://raw.githubusercontent.com/waqashabib554-cyber/moodle-tool_upgradeguard/main/data/targets.json`.
Automatic refresh becomes available once that repository is public. The setting
can be cleared to disable refresh. The repository draft includes a GitHub
Actions check that validates rule changes before they are merged.

### Requirements of the scan

- Scans only **read**. Nothing is copied, moved or deleted, and no third-party
  plugin code is executed.
- A scan writes three tables: `tool_upgradeguard_scan`,
  `tool_upgradeguard_plugin` and `tool_upgradeguard_finding`. Older scans are
  removed by the daily cleanup task, after the number of days set in
  `retentiondays` (default 180).

### Updating the plugin code

If you add or replace plugin code on disk (FTP/SSH) instead of using the site
admin installer, finish the installation in *Site administration*. That page
rebuilds Moodle's component class map, which is what makes new plugin classes
visible. *Purge all caches* clears it as well (`purge_other_caches()` removes
the whole `$CFG->cachedir`, including `core_component.php`). Without that step
a freshly copied plugin can stay invisible to the site — this is standard
Moodle behaviour, not specific to Upgrade Guard.

### Uninstalling

Uninstall the plugin the usual way, from *Plugins → Plugins overview*. Moodle
removes the three tables, the settings, the capabilities and the scheduled
task. What stays in the database is only the site's own log history of the
events the plugin wrote; nothing of the plugin runs after that.


## Dashboard

`index.php` is one page in six tabs, all of them Bootstrap tabs, so the plugin
needs only one small AMD module:

| Tab | What it shows |
|---|---|
| Overview | The state of the last scan (not started, queued or running with the cron warning, failed with the stored error, finished), the verdict and the score, at most the three most important actions, the safe-to-remove hint, the site wide findings, the disclaimer and the export links |
| Plugins | A pointer to the searchable results table |
| Environment | The PHP, database and extension facts of the server, with the source of every requirement |
| Files to move | The plugins that still sit outside the public directory (Moodle 5.1+) |
| Checklist | The pre-upgrade checklist |
| History | The most recent scans, with a link to the full history page |

The tabs are marked up with `role="tablist"`, `role="tab"` and `role="tabpanel"`
plus `aria-controls`, `aria-selected` and `aria-labelledby`, and are keyboard
navigable. Content reflows down to 375px wide screens and stays readable at 200%
zoom; no fixed widths and no horizontal scrolling. The page adds exactly one
primary button, the scan form's submit; every other action is a secondary
button.

Every page carries the same `.tool-upgradeguard` container class, with the page
specific `.tool-upgradeguard-results` and `.tool-upgradeguard-history` on top of
it, so one stylesheet reaches all of them. `styles.css` holds the handful of
rules Boost does not provide (tab wrapping, the spacing of the shared page
header, button spacing, the print colours of the badges and releasing the
responsive table wrapper when printing). Moodle adds that file to the theme's
CSS bundle automatically for every installed plugin
(`theme_config::get_css_files()`), so nothing calls `$PAGE->requires->css()`.

The dashboard, the results table and the history page start with the same header
partial, `templates/pagehead.mustache`: a title, one muted line of context and
the button back to the dashboard. Sharing it is what keeps headings, badges,
buttons and spacing the same on those pages.

Wide tables sit in Bootstrap's `.table-responsive` wrapper, so they scroll under
the finger on a 375px screen instead of stretching the page sideways. When the
page is printed the wrapper releases the table again, because a printed page has
nothing to scroll.

## Results table

`results.php` shows every plugin of a finished scan in one searchable table:
search by name or component, filter by status, plugin type or "in use only",
sort by clicking the column headers (status sorts worst first) and page
through the results, 25 per page. The findings of a row fold open with a
Bootstrap collapse. The query count stays constant however large the site:
one count, one page of rows, one query for the findings of that page.

## The checks that run

| Check | What it looks at | Typical severity |
|---|---|---|
| `site_php` | PHP version of the server against the target branch | blocker |
| `public_location` | Plugins outside the public directory (Moodle 5.1+) | blocker / caution |
| `declared_compat` | What the plugin declares (`$plugin->requires`, `supported`, `incompatible`), using the same core methods as Moodle's own "Plugins check" | blocker / caution / info |
| `dependency` | Plugins that the plugin requires | blocker / caution |
| `update_available` | Newer versions reported by the plugins directory | info |
| `usage` | Plugins that nothing on the site uses | caution |

Adding a check means adding one class in `classes/local/check/` and one line in
`classes/local/check/registry.php`.

## The rule dataset

`data/targets.json` lists the Moodle branches a scan can be run against, with the
PHP version each branch needs, its core version number, the version it can be
upgraded from, whether it reads plugins from the public directory, and the
release status Moodle publishes for that branch.

| Branch | PHP min | Upgrade from | Public layout | Support status |
|---|---|---|---|---|
| 4.4 | 8.1.0 | 4.1.2 | no | unsupported |
| 4.5 | 8.1.0 | 4.1.2 | no | security fixes only (LTS) |
| 5.0 | 8.2.0 | 4.2.3 | no | security fixes only |
| 5.1 | 8.2.0 | 4.2.3 | yes | stable |
| 5.2 | 8.3.0 | 4.4 | yes | stable |
| 5.3 | 8.3.0 | 4.5 | yes | not released yet |

The **support status** column is read from the release support table on
<https://moodledev.io/general/releases> and each entry stores that page in its
`statussource` field, so a claim in the dataset can be traced back to the page
it came from. `unsupported` branches (Moodle receives no fixes at all, and
`future` branches (Moodle has not shipped yet) are never offered as targets, and
neither is a branch older than or equal to the running site. A branch that only
receives security fixes stays selectable — a site may legitimately plan an
upgrade to it — but the selector says so, so it is never mistaken for a fully
supported destination.

The PHP requirements and upgrade sources come from the `admin/environment.xml`
of each branch, and the core version numbers from the `version.php` of each
branch. One value is derived rather than read: the branch number of 5.1, because
its version file was not available when the dataset was built. It is marked as
`"requiresintsource": "derived"` and reported with a lower confidence.

**Moodle 5.3 is deliberately unselectable.** It is scheduled for 5 October 2026
and has no `admin/environment.xml` yet, so its PHP and database minimums are
taken from the published release notes and marked verified, while its extension
list is a copy of 5.2's and is marked **not** verified. The dataset says so in
the `source` field of that block, and a test fails if the flag is ever flipped
without the data being re-read.

## Scoring

The score starts at 100 and subtracts one penalty per plugin. A plugin's
penalty is its status penalty **times** a type weight **times** a usage
multiplier, so the same blocker costs more in a theme that every page loads
than in a block that nothing uses:

| Status of the plugin | Setting | Default |
|---|---|---|
| Blocker | `weightblocker` | 12 |
| Caution | `weightcaution` | 4 |
| Unknown | `weightunknown` | 3 |
| Update available | `weightupdate` | 1 |

| Plugin type | Setting | Default |
|---|---|---|
| Theme, authentication | `weighttheme`, `weightauth` | 3 |
| Enrolment, activity module, local plugin, editor | `weightenrol`, `weightmod`, `weightlocal`, `weighteditor` | 2 |
| Every other type | `weightother` | 1 |

| Usage of the plugin | Setting | Default |
|---|---|---|
| Used somewhere on the site | `usagemultiplierused` | 1.5 |
| Measured as not used | `usagemultiplierunused` | 0.3 |
| Usage could not be measured | `usagemultiplierunknown` | 1.0 |

Site-wide findings carry a flat penalty of their own, because a server that
cannot run the target Moodle is not a weighted-average problem:

| Site-wide finding | Penalty |
|---|---|
| Blocker (PHP too old, database too old, required extension missing, plugin outside the public directory) | 25 |
| Caution | 5 |
| Info | 0 |

Findings themselves are never scored: only the resolved status of a plugin and
the severity of a site-wide finding are, so an informational finding cannot add
a penalty of its own. The score never goes below 0 or above 100. The JSON
export lists the breakdown per plugin and per site-wide finding, and the scan
row stores the environment counts in `environmentblockercount` and
`environmentcautioncount` next to the plugin counts.

The verdict then follows from the score and the statuses:

- **Not yet** (Stop) — a site-wide environment blocker, or a blocker **in use**
  on the site, or a score below `stopthreshold` (60).
- **Careful** — any plugin or site-wide finding has the caution status, or the
  score is below `gothreshold` (85).
- **Ready to upgrade** (Go) — everything else.

An environment blocker is a hard stop on its own: no plugin score can talk a
site into an upgrade its own server will not survive.

## Settings

All settings live under *Site administration → Plugins → Admin tools →
Upgrade Guard*, in five groups:

| Group | Settings |
|---|---|
| Readiness score | `weightblocker`, `weightcaution`, `weightunknown`, `weightupdate`, and the type weights `weighttheme`, `weightauth`, `weightenrol`, `weightmod`, `weightlocal`, `weighteditor`, `weightother` |
| Usage multipliers | `usagemultiplierused`, `usagemultiplierunused`, `usagemultiplierunknown` |
| Verdict thresholds | `stopthreshold`, `gothreshold` |
| Plugin updates | `checkremote` (allow the moodle.org lookup), `remotecachettl` (how long those answers are cached; zero uses a one-hour fallback), `rulesfeedurl` (public GitHub target rules JSON URL) |
| Data management | `retentiondays` (how long scans are kept; the daily task removes older ones), `supportemail` (address behind the "report a wrong result" link) |

Every setting has a sensible default, so a first scan works without touching
any of them.

## Exports

The dashboard offers every finished scan in four shapes:

- **CSV** (`export.php?format=csv`) — one row per finding, with the confidence
  of every finding and a "This site" scope for site-wide findings, so they are
  not silently dropped; every cell is neutralised against spreadsheet formulas.
- **JSON** (`export.php?format=json`) — the whole scan machine readable: target
  facts (including how every requirement number was sourced), the stored
  environment snapshot, the site-wide findings as their own `sitefindings`
  list, the score breakdown per plugin *and* per site-wide finding, the target's
  release status together with the page it was read from, every plugin with its
  findings and their confidence, and ISO dates. Both downloads require the
  export capability and a valid session key.
- **Printable HTML report** (`report.php`) — Screen 5 of the specification on
  one page: verdict and score, the blockers, the environment, the plugins
  grouped by status, the move list and the checklist. The print stylesheet
  hides navigation and buttons; the page is reachable with the view
  capability and opens from the dashboard.
- Every export shows the **dataset version** of `data/targets.json` and, when
  the `supportemail` setting is filled, a **"Report a wrong result"** link
  (a `mailto:` to that address). Leave the setting empty to hide the link.

## Privacy

The only personal data stored is the id of the administrator who started a scan
and the times the scan ran. The plugin implements the Moodle Privacy API:
`classes/privacy/provider.php` describes, exports and deletes that data.

## Developer notes

- `classes/local/` holds the domain: targets, snapshots, findings, checks,
  collectors, calculators and the scanner.
- `styles.css` is compiled into the theme's CSS bundle, which is cached and keyed
  by the theme revision. After changing it, *Purge all caches* (`purge_caches.php`)
  so the served bundle picks the new rules up.
- `classes/local/repository/scan_repository.php` is the only place that talks to
  the database.
- `classes/local/collector/` holds everything that reads data: the core plugin
  manager, the filesystem, usage counts and the update checker.
- Checks and calculators are pure: they get a `scan_context` and return findings,
  which makes them easy to test.
- Code style is verified with `moodle-extra` (`local_codechecker`).

## Limitations

Read this before you trust a green result. Every point below is a known,
deliberate limit of the current version, not a bug report.

- **A scan is an estimate, not a promise.** It reads what the plugins declare,
  what core knows about them and what the dataset says about the target branch.
  A plugin can still break during the upgrade (a runtime bug, a core change that
  no declaration mentions), so a **false "ready"** is possible and is the more
  dangerous kind of error.
- **Incomplete measurements are not treated as a green result.** If the database
  version or loaded PHP extensions cannot be read, the report adds a caution.
  If a plugin cannot be inspected, located, or enumerated on disk, the scan
  fails rather than silently omitting it from a potentially misleading report.
- **The other direction happens too.** A finding is a reason to look, not proof:
  a plugin can be reported as a blocker although it works on the target branch,
  for example when its author never updated the declared support window. Treat
  blockers as the first three things to check by hand.
- **Declarations are taken at face value.** If a plugin's `version.php` claims a
  support range it does not honour, the scan repeats the claim with the same
  confidence it gives every other declaration.
- **Only the MySQL family was exercised.** The database minimums in
  `data/targets.json` come from Moodle's own `environment.xml` and the release
  notes and are marked as verified, but the comparison has only ever been run
  against MySQL/MariaDB here. On PostgreSQL, SQL Server or Aurora the row is
  reported with medium confidence for that reason.
- **Runtime coverage is limited.** Moodle 5.1.3 was exercised against target
  5.2; other supported source branches have not been installed in this project.
  Treat the lower end of the declared range as untested.
- **Moodle has ended support for 4.4** ("no longer supported and will not
  receive fixes for security risks"). The plugin still declares 4.4 as its
  `requires` value so that sites still running it can install and use Upgrade
  Guard, and 4.4 is listed in `$plugin->supported`. Read that as "the plugin
  loads on 4.4", not as a statement about 4.4's own support status — the target
  selector never proposes 4.4 to anybody.
- **4.5 and 5.0 are past general support** (security only: 4.5 until
  4 October 2027, 5.0 until 5 October 2026). They stay selectable because an
  upgrade to them is a legitimate plan, but the selector marks them, and a scan
  against one will not tell you that the destination is nearly out of security
  support. Plan the *next* upgrade from there.
- **Moodle 5.3 cannot be scanned against yet.** It is scheduled for
  5 October 2026. The dataset knows its PHP and database minimums, but its
  extension list is inherited from 5.2 and is explicitly not marked verified, so
  the tool refuses to offer the branch instead of guessing.
- **Use counts are best effort.** Some plugin types have no usage enricher at
  all, and those plugins report "unknown", which counts as low risk in the
  score. A blocker in an unmeasurable plugin therefore does not stop the
  upgrade by itself.
- **Update data is optional and depends on Moodle's core update checker.** If
  `checkremote` is on but Moodle has never stored a valid plugin-update
  response, the report recommends checking for updates from *Site
  administration → Notifications*. Once Moodle has a valid response, an empty
  update list is treated as a successful check rather than a missing-data
  warning.
- **Cron is required.** A scan stays queued until cron runs, and the daily
  cleanup task is what removes old scans.
- Live progress on the dashboard uses a page refresh
  (`$PAGE->set_periodic_refresh_delay`) rather than a progress bar: the scan is
  short enough for that. The only JavaScript the plugin ships is
  `amd/src/dashboard.js`, which keeps the selected tab in the URL so a
  dashboard link can point at one tab.
- Behat tests are not written yet; the automated coverage is PHPUnit only.
- The printable report is HTML only: `report.php` is meant for the browser's
  print dialog, not for a generated PDF.

## Disclaimer

Upgrade Guard helps you plan an upgrade. It does not perform one, and it cannot
guarantee the outcome. Always take a backup, test the upgrade on a staging copy
of the site first, and read the release notes of the target Moodle version. The
tool only reads; nothing on your site is changed by a scan.

## License

GNU General Public License v3 or later. The full text is in the `LICENSE` file
and every source file carries the license in its header. This is the same
license Moodle itself uses, which means you may use, study, share and improve
the plugin, and that any distributed version has to stay under the same terms.

## Support

Upgrade Guard is free. The publisher also offers optional, separately scoped
paid services for Moodle/PHP development, Moodle or plugin installation and
configuration, and administrator training. Contact Waqas Habib at
[waqashabib554@gmail.com](mailto:waqashabib554@gmail.com) to discuss a service.

- Publisher and support contact: **Waqas Habib** — [waqashabib554@gmail.com](mailto:waqashabib554@gmail.com).
- Public issue tracker: <https://github.com/waqashabib554-cyber/moodle-tool_upgradeguard/issues>.
- **A result looks wrong?** Fill in the `supportemail` setting under *Site
  administration → Plugins → Admin tools → Upgrade Guard*. The dashboard and
  the printable report then show a "Report a wrong result" link that opens a
  pre-filled mail to that address.
- When you report something, include the Moodle version, the target version,
  the plugin version (the release string on the plugins overview page), the id
  of the scan and a short description. Do not include passwords, access tokens
  or private site URLs; redact site or user details from exports before sharing.
- Security issues: use GitHub's private vulnerability reporting at
  <https://github.com/waqashabib554-cyber/moodle-tool_upgradeguard/security/advisories/new>.
  Do not report vulnerabilities in a public issue.
