# Upgrade Guard screenshots

Captured on 3 October 2026 from the 0.9.3 build on an isolated Moodle 5.1.3
staging site, after a real scan against Moodle 5.2. The scan completed with a
Careful verdict, a readiness score of 78/100, 9 findings, and 12 plugins in the
inventory. The environment check reported PHP 8.3.30, MySQL 8.4.3 and all 20
required PHP extensions as OK.

| File | Shows |
|---|---|
| `01-dashboard-overview.png` | Completed 5.1.3-to-5.2 scan, verdict, score and recommended next actions. |
| `02-plugins.png` | All 12 scanned plugins, including their status, version, usage and findings. |
| `03-environment.png` | PHP, database and required-extension checks against Moodle 5.2. |
| `04-files-to-move.png` | The public-directory move list; none of the 12 plugins need moving. |
| `05-checklist.png` | The pre-upgrade checklist generated from the scan. |
| `06-history.png` | The completed 5.2 scan in scan history. |

The captures were made in a browser at a 1440px viewport and reviewed after
capture. They show the isolated staging site's name and local plugin inventory;
they contain no customer data or personal account name. The plugin settings
were each tested individually and restored to their documented defaults before
the captures.

These are genuine product-evidence captures, not final promotional artwork.
Review the visible staging label and plugin names before using them in a
Marketplace listing. The bundled rules identify Moodle 5.2 as stable and 5.3
as future in dataset version 5, so the scan deliberately targets 5.2 only.
