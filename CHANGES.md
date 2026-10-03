# Upgrade Guard — change log

All notable changes to `tool_upgradeguard`, newest first. The dates are the
days the work landed in the repository.

The plugin is pre-1.0. `0.9.0` is the first version prepared for release; the
numbers before it mark the development milestones that led there. Those
milestones were never published on their own, so their entries are
reconstructed from the repository history and describe what arrived when.

## 0.9.3 — 3 October 2026 (development)

### Added

- Target rules can be refreshed from the maintainer's public GitHub feed by
  Moodle cron. New installations use the configured maintainer feed by default;
  an administrator can clear the URL to disable refresh. The updater validates
  the HTTPS host, feed size, required
  environment data, dataset version, Moodle branch/version relationship,
  release statuses and duplicate entries before saving; an unavailable or
  invalid feed never replaces the bundled or last-known-good rules.
- The no-target notice now says that no *verified target is present in the
  active rules* instead of incorrectly declaring that the site is on the
  newest released Moodle. It reports the active rules version and last
  successful rules check, and surfaces refresh failures while preserving the
  offline rules.

### Pending release configuration

- The maintainer confirmed `waqashabib554-cyber` as the GitHub owner. The
  default feed URL is prepared, but the public repository has not been created
  or published. Automatic refresh will continue to use bundled rules until the
  public feed is available and a successful refresh completes.

## 0.9.2 — 2 October 2026 (stable)

Prepared stable release. Includes the first-run dashboard improvements,
fail-closed inventory and environment checks, refreshed screenshots, and
clarified settings help from the development cycle since 0.9.1.

### Changed

- **The six Marketplace screenshots are from the current build again.** They were
  captured from 0.9.0, before the 0.9.1 design pass, and no longer matched the
  interface; `docs/screenshots/README.md` carried a warning saying so. All six
  were re-captured on 1 October 2026 at 1440px, one full page per tab, with the
  report taken under emulated print media, and the captions were rewritten to
  match what the new files show. No behaviour changed.

### Added

- **The first-run screen now says what the tool does.** A site with no scans used
  to get one alert and a form, and nothing else. It was the only screen an
  administrator saw before deciding whether the tool was worth running, and it
  told them nothing about what a scan reads or where the result ends up. The empty
  overview now carries a short welcome card, and the sentence about data follows
  the remote update check setting: with the check on, a scan does ask
  moodle.org, so the page does not claim that nothing leaves the site.
- **The string key test could not see the templates.** Moodle renders an unknown
  language string as `[[key]]` on the page and reports it only as a developer
  debugging message, so a mistyped key reaches an administrator as a broken
  looking notice rather than as a failure. The existing test read PHP files
  only, and the dashboard, the results page, the report, the history and the
  shared partials all ask for their strings from inside mustache, where the
  scan could not see them. A test now reads every template and checks each
  `{{#str}}` key against the language file, naming the file and the key when
  one is missing.
- **A test now reads the rendered dashboard for raw keys.** It renders the two
  shapes the overview actually has — a site with no scan, and a site with a
  finished scan — and fails if `[[key]]` appears anywhere in the result. Unlike
  a list of expected keys, this also catches a string that a partial asks for
  and the context does not supply at all.

### Fixed

- **A site with no scans was told to start one, with no way to start it.** The
  notice "No scan has been run on this site yet. Start one to see what an upgrade
  would do to your plugins" rendered on the empty dashboard, but the start form
  sat inside the section that only appears once a scan is stored, so a new install
  reached a dead end and could not produce the report the page was asking for. The
  form and the line that points at it moved into a shared partial rendered in both
  states. Both stay behind the same `canrunscan` and `hastargets` conditions
  `index.php` uses, so the page still never shows a form it has not been given and
  never registers a form change checker for a form it does not print.
- **The empty dashboard no longer tells sites without an upgrade target to start
  a scan.** The 5.2 target is already the newest released branch in the shipped
  dataset, so a site already on 5.2 cannot run the suggested scan. The empty
  overview now explains that there is no newer target, and the other tabs say
  only that scan results are not available yet. Users without scan permission
  are not shown scan instructions either.
- **The verdict card on the dashboard could clip its own text.** The card packs
  the verdict, the version pair, the score and the summary badges into two flex
  rows. A flex item will not shrink below the width of its longest unbreakable
  text, and the score block was additionally pushed to the right with a
  `ms-auto` margin, so on a narrower window the row ran past the right edge of
  the card and the tail of the sentence was cut off — the score lost its
  `/ 100 Readiness score` label and the "Score after removing unused plugins"
  line lost its number. The margin is gone (the row already justifies itself),
  every direct child of a row can now shrink, and the sentences are allowed to
  break instead of overflowing. The score block keeps its number and its label
  together. Only the overflow changed: the card's design, colours and spacing
  are as before.
- **The overview sections skipped a heading level.** "What to do next" and
  "Site wide findings" were `<h4>` under the page's single `<h1>`, so the
  document outline announced a level nothing had introduced. They are now
  `<h3>`, the level of a section in that pane. No wording or styling changed.

### Changed

- **Every page of the plugin is now audited, not only the dashboard.** The
  dashboard had a screen review and the four other pages had none, which is how
  a page can pass its own unit tests and still show a reader a raw `[[key]]`,
  a link to a file that does not exist, or a table of the wrong numbers. A new
  test reaches `index.php`, `results.php`, `report.php` and `history.php` the
  way a browser does — by running the page controller itself — and checks the
  HTML that comes back for a missing language string, a raised warning or
  exception, a link that resolves to nothing on disk, and the data each table
  is supposed to show. The settings page is built the way the admin tree builds
  it and rendered the same way. Every page came through clean, so nothing in
  the interface changed; what changed is that this class of defect can no
  longer reach a release unnoticed.

## 0.9.1 — 25 September 2026 (beta)

Audit fixes. The scoring pipeline, the target list and the documentation were
corrected; nothing about the workflow of a scan changed.

### Fixed

- **Site-wide findings now count.** An environment blocker or caution (PHP too
  old, database too old, a missing extension, a plugin outside the public
  directory) previously reached the findings table but not the score, the
  stored summary or the verdict, so a site with a PHP blocker could still be
  reported as "Ready to upgrade". Site findings now carry their own penalty
  (blocker 25, caution 5, info 0), are counted in `blockercount` /
  `cautioncount`, are stored in the new `environmentblockercount` and
  `environmentcautioncount` fields, and appear in the JSON export, the CSV
  export and the score breakdown.
- **An environment blocker forces the verdict "Not yet"** on its own, whatever
  the plugin score is. A server that cannot run the target Moodle is not a
  "Careful" outcome.
- **The target selector offered the running branch and every older one.**
  `scan_form::definition()` read `$CFG->branch` in a method scope without
  importing the global, so the branch silently evaluated to `0` and nothing was
  filtered out. The form now reads the real branch, and a regression test pins
  both directions.
- **Moodle 5.0 was recorded as a supported "stable" branch.** Moodle ended
  general support for 5.0 on 20 April 2026, and its security support ends on
  5 October 2026. 4.5 is in the same position as the current LTS. Both are now
  recorded as `security` (security fixes only) and are labelled as such in the
  target selector, so nobody reads them as fully supported destinations. They
  stay selectable, because a site may legitimately plan an upgrade to them.
- **The unreleased Moodle 5.3 branch claimed verified data it cannot have.**
  5.3 ships on 5 October 2026, so it has no `admin/environment.xml` yet; its
  extension list is a copy of 5.2's and is now marked *not* verified. The PHP
  and database minimums, which moodledev does publish, stay verified.
- **Every release status in the dataset now records its source** in a new
  `statussource` field, and a test asserts the status of all six branches
  against the published support table.
- **Sites upgrading from 0.9.0 could not install 0.9.1.** The upgrade step
  that adds the two new environment count columns used `XMLDB_TYPE_INT`, a
  constant Moodle does not define — `install.xml` accepts `TYPE="int"` as an
  alias for an integer column, but the PHP constant is `XMLDB_TYPE_INTEGER`. The
  step died before it added a single column, leaving the plugin uninstalled and
  the site on a half-applied upgrade. Fresh installs were never affected, which
  is why the whole test suite passed. The constant is corrected, the upgrade has
  been re-run against a real 0.9.0 database, and a new test fails on any
  `XMLDB_*` name in `db/upgrade.php` that is not a defined constant.
- Deleting a scan asks for confirmation, the history headings say what the page
  actually shows, and the checklist strings read correctly for one item and for
  many.
- **The buttons of the history rows had no gap between them.** `styles.css` gives
  `.upgradeguard-actions .btn` its spacing, but the class sat on the header cell
  while the buttons sat in the body cell, so the descendant selector never
  matched and the three buttons of every row were rendered flush against each
  other. The class is now on the cell that holds the buttons.
- **Buttons came in two sizes depending on the page.** The dashboard exports, the
  plugins tab, the history tab and the results filters were full height while the
  row buttons, the page header and the pagination were small, so the same kind of
  action looked different depending on where a reviewer started. Every button is
  now `btn-sm`.
- **Links that open the printable report in a new tab now carry
  `rel="noopener"`.** Without it the new tab could navigate this one through
  `window.opener`.
- **The current page of a paginated screen is announced.** The number buttons
  marked the current page only with a colour, so a screen reader user could not
  tell where they were; the current link now carries `aria-current="page"`.
- `styles.css` gained the rule for the dashboard summary card, whose
  `upgradeguard-summary` class was a hook no stylesheet implemented.
- **The printable report was a page of its own.** `report.php` set the `embedded`
  layout, never sent `$OUTPUT->header()` or `$OUTPUT->footer()`, and the template
  carried a 59-line inline stylesheet that reimplemented the palette, the badges
  and the print rules. The result was a document with no site header, no footer,
  no navigation, no plugin stylesheet and no theme, and it diverged from the rest
  of the tool on every screen. It is an ordinary admin page now: the page
  renderer, the theme and `styles.css` do the work, and the print rules live in
  `styles.css` with the rest.
- **The results and history pages had no heading of their own.** Neither called
  `$PAGE->set_heading()`, so the theme printed the name of the tool instead of
  the name of the screen. All four pages now name themselves, and no template
  opens an `<h1>` of its own, so the document has exactly one.
- **The scan form rendered an empty target selector.** On a site whose newest
  released branch in the dataset is the running one — which is every site between
  two releases — the `<select>` came up with no options, next to a static
  explanation and with no submit button. The form now explains the situation on
  its own and names the branch the site runs, and no dead control is rendered.
- **A CSV download was saved as `name.csv.csv`.** `csvfilename` already carried
  the extension and `dataformat::download_data()` appends it, so every export
  arrived with a doubled extension. The name is now extensionless.
- **An unknown export format answered with a bare error page.** `export.php`
  threw a `moodle_exception`, which is the wrong shape for a request that is
  reached by following a link. It now redirects back to the dashboard with a
  notification, and the message it shows is asserted to exist.
- **A checklist box was not a form control.** The boxes had no `name`, and the
  detail line sat outside the `<label>`, so clicking the explanation did not tick
  the box. Both are fixed, and the detail is now part of the label.
- **A requirement source was printed twice.** The environment card printed the
  whole source note and then linked the same text again, so every address
  appeared twice and the long 5.3 note was used as link text. The note is split
  into a readable label and one link per address.
- **Sentences did not always agree with their number.** "1 plugin is not used"
  could be shown for two plugins, and "Show all 5 actions" counted the three
  actions that were already visible. Every counted word is now a string of its
  own, chosen by `local\plural`, and the collapse counts what it reveals.
- **The dashboard history printed an empty score** for a scan that had not
  finished, which reads as a score of nothing. It prints a dash now.
- **A queued scan was described as "Scanning your plugins…"** and a failed scan
  with no recorded reason rendered an empty red box. Queued and running are now
  different messages, and a failure always says what to do next.
- **The report showed a number where the dashboard showed a sentence.** The usage
  cell was empty when the count could not be measured, which reads as a missing
  value rather than as a limit of the measurement. One helper builds that
  sentence for all three screens.
- **The history page was a fatal error on a site running in developer mode.**
  `get_scans_page()` selected only `firstname` and `lastname` and passed the row
  to `fullname()`, which reads six name columns and warns about the missing four.
  That warning becomes an exception in developer mode, so the whole page died
  with "The following name fields are missing from the user object".
- **A site on the newest released Moodle was told about it at the bottom of the
  page.** With no newer released branch there is nothing to scan, so the target
  selector is empty by design; that explanation sat under an empty form at the
  end of a long page and read as a control that had failed to load. A green
  notice now sits directly under the verdict and names the version the site is
  running, and the empty form is not rendered a second time further down.
- **The "Show all N actions" link could not be undone.** It is the same control
  for both directions, but Bootstrap only shows and hides the region and keeps
  `aria-expanded` correct; it never rewrites the label, so after the actions
  appeared the button still read "Show all 4 actions" and gave no hint that the
  same press closed the list again. The control now carries both wordings and
  swaps them as the region opens and closes.
- **Short action lists were hidden for no gain.** The overview always sliced the
  list to three rows, so a scan with five actions hid two of them behind a
  button — making the page longer than showing all five while still keeping work
  out of sight. The list is now shown whole until at least three rows are
  actually hidden.

### Added

- `tests/ui_consistency_test.php` — 19 tests that render the dashboard, the
  results table, the history page and the printable report through the real
  mustache engine and audit the HTML a browser receives: every button is
  `btn-sm` and uses one of three allowed styles, every new-tab link severs the
  opener, every `upgradeguard-*` class a template uses is defined by a
  stylesheet, the shared page header renders exactly once per screen, every
  dashboard tab points at a pane that exists, every disclosure control names the
  region it opens, the verdict badge and the score always carry the matching
  colour class, and the destructive delete button is a confirmed POST with a
  sesskey that only appears for a viewer with the capability. This is what
  caught the two button bugs above, which were invisible to the per-class unit
  tests.
- `tests/db_schema_test.php` — three tests over the database definition: every
  `XMLDB_*` constant named in `db/upgrade.php` has to be a constant this Moodle
  defines, `install.xml` has to parse as a valid XMLDB file, and the columns an
  upgrade step adds have to be declared in `install.xml` with the same type, null
  constraint and default. The first of those is what caught the `XMLDB_TYPE_INT`
  bug above, which every other test in the plugin passed straight through.
- `tests/audit_regression_test.php` — 15 tests that pin down every defect the
  second live screen audit found: the download names, the export refusal, the
  agreeing words, the usage sentence on all three screens, the checklist form
  controls, the source links, and the queued, running and failed states of the
  dashboard.
- `local\plural` and `local\usage_text` — the two places that decide how a number
  is worded and how a usage count reads, so the dashboard, the results table and
  the report cannot drift apart again.

- `data/targets.json` gained a `statussource` per target and a dataset-level
  note; the dataset version moved to `5`.
- `version.php`: release `0.9.1`, version stamp `2026092500`.
- Tests for the environment score, the environment verdict, the scanner
  aggregation, the CSV and JSON exports, the target policy, the scan form, the
  templates and the standard log store.
- `scanner::calculate_result()` is now the single place where score, verdict and
  summary counts are decided, so an environment finding cannot be dropped
  between the checks and the stored report. CSV generation moved to
  `local\csv_report`, which emits site-wide findings as first-class rows.

## 0.9.0 — 24 September 2026 (beta, first release)

Release preparation. Nothing about scanning changed in this version.

### Added

- `LICENSE` — the full text of the GNU General Public License v3. The plugin is
  licensed "GPLv3 or later", which every source file already states in its
  header.
- `CHANGES.md` — this file.
- README: installation steps for both the admin installer and a manual copy,
  the limitations found while testing (listed below), the disclaimer, the
  license and how to get support.

### Changed

- `version.php`: release set to `0.9.0`, maturity stays **beta**, version stamp
  bumped so sites pick the new metadata up.
- README describes the tool as it is: the exports that really exist, the
  dependency on cron, and how confident each finding is.

### Verified for this release

- **Fresh install** on a clean schema (separate table prefix, own data
  directory): Moodle core and every plugin, including Upgrade Guard, install
  without an error that stops the installer.
- **Uninstall** through Moodle's own plugin manager: the three tables, the
  settings, the capabilities and the scheduled task are removed, and a later
  re-install brings all of them back cleanly.
- The privacy provider tests, the whole PHPUnit suite of the plugin and the
  coding-standard run (`phpcs`) pass.

## 0.5.0 — 23 September 2026

The defects found during early end-to-end browser checks.

### Fixed

- **The printable report lost its colours.** The status column and every badge
  printed as plain black text because the report stylesheet was scoped to a
  class the page no longer used, so not a single rule applied. The scope now
  matches the container the page really renders, and a test keeps both sides in
  step.
- **The "Files to move" tab was thousands of pixels tall when nothing had to
  move.** A site that already used the target layout still got the full table
  and the CSV dump of identical paths under a summary that said nothing had to
  move. Both are now only rendered while at least one plugin still has to
  move; the tab keeps the heading, the summary and the success line.
- The **site-wide findings table** was missing under its heading on the
  dashboard, because the shared partial read a key the dashboard never
  exported.
- Tabs without a scan showed an empty pane instead of explaining the state.
- The environment tab explained nothing when a scan had no environment data to
  show.

### Added

- The overview lists every planned action: the three most important ones stay
  visible, the rest wait behind a "show all" collapse.
- A test that renders the dashboard through Moodle's mustache engine, so a
  template that reads a key the context does not export fails the suite instead
  of reaching the browser.

## 0.4.0 — 22 September 2026

One dashboard page in six tabs, and the shared look of every page.

### Added

- The dashboard is now **six Bootstrap tabs** — Overview, Plugins,
  Environment, Files to move, Checklist, History — with keyboard support and
  accessible tab markup.
- Every page shares one container class and one page header, so a single
  stylesheet reaches all of them.

### Fixed

- Wide tables stay usable on a phone and when the page is printed.
- The printable report is rendered with an explicit renderer; before, it could
  be handed Moodle's bootstrap proxy and stop with a fatal error.
- The report page shows the same status colours as the dashboard.
- The plugin settings are grouped into five sections instead of one long list.

## 0.3.0 — 22 September 2026

The features that turn a scan into a work plan.

### Added

- **Environment check:** the server's PHP version, database server and PHP
  extensions are compared with the target branch, and every requirement states
  where its number comes from. The dashboard shows the snapshot of the machine
  the scan ran on.
- **Files to move:** for targets that use the `/public` layout (Moodle 5.1+)
  the tool lists which plugins still sit in the old place, with a CSV export and
  ready-to-paste shell commands to copy them.
- **Pre-upgrade checklist:** built from the findings of the scan, so it only
  asks for the steps that really apply.
- **JSON export and a printable HTML report**, next to the existing CSV. The
  report is one page with the verdict, the blockers, the environment, the
  plugins grouped by status, the move list and the checklist.
- **Searchable, paginated results table** for the plugin rows, plus a
  **history page** that lists earlier scans with links to their results.
- Audit events for starting, finishing and deleting a scan.

## 0.2.0 — 22 September 2026

The findings of the first independent audit of the plugin, fixed.

### Fixed

- A plugin whose blocker is fixed by an available update is now reported as
  "Update available" instead of blocking the upgrade.
- Compatibility declarations are reported as they are written: a plugin that
  only declares a minimum version is informational, while a declared support window that ends before
  the target stays a caution.
- When the remote update check is switched off, empty or stale, the affected
  plugins are reported as unknown with a clear next step instead of silently
  counting as "no update".
- CSV exports neutralise spreadsheet formulas, so a plugin name can never
  execute anything in Excel or LibreOffice.
- Deleting a scan is POST-only, shows a confirmation and writes an audit event.
- A scan that was left "running" by a killed cron run is recovered instead of
  blocking the dashboard forever, and the dashboard explains when cron has not
  run recently.
- Readiness scoring counts risk and usage together, so an unused blocker no
  longer reads the same as a blocker in daily use.
- Usage counting includes category and user theme overrides.
- The ad hoc task API of Moodle 5.2 is used, so a scan starts on every
  supported branch.
- Wording for plugins that declare no supported range is precise about what
  they did and did not say.

## 0.1.0 — 22 September 2026

The first working version, kept as the audited baseline.

### Added

- A target dataset for Moodle 4.4, 4.5, 5.0, 5.1 and 5.2 with the PHP, database
  and extension requirements of every branch, each with its source.
- The scan pipeline: a scan is queued as an ad hoc task, claimed with a lock,
  runs in cron and stores its report in one transaction.
- The plugin inventory with version, release, status and usage counts, plus an
  orphan scanner for code that is on disk but not installed.
- The checks: declared compatibility, dependencies, available updates,
  site-wide PHP, plugin location and usage.
- A readiness score, a verdict and simple action lists ("update this", "move
  that", "review or remove this").
- The dashboard with the verdict, the score, the findings, the planned actions
  and the scan form, plus CSV export behind the export capability.
- Capabilities for scanning, viewing and exporting, a privacy provider that
  describes, exports and deletes the stored data, audit events, a scheduled
  cleanup task that removes scans older than the retention setting, and admin
  settings for the remote check, the scoring weights and the retention period.
- Unit tests for the calculators, the target repository, the layout and the
  privacy provider, plus the specification this plugin was built from
  (`upgrade-guard-spec.md`).
