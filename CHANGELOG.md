# Changelog

All notable changes to the XOOPS DebugBar module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses semantic versioning.

## [1.3.1] - 2026-07-21

### Security

- Hardened read-only SQL classification by correctly terminating backtick-quoted identifiers and rejecting executable MySQL and MariaDB comments.

### Fixed

- Corrected the empty-Monolog-directory regression test so it exercises an existing empty directory while preserving access to the legacy log.
- Applied the project coding style required by the PHP 8.2, 8.3, and 8.4 CI quality jobs.

### Changed

- Reduced SQL-tokenizer complexity and allocation overhead by extracting parsing helpers, consuming identifiers in one anchored match, and using the native line-comment scan.
- Corrected the Scrutinizer analysis-node configuration and kept Rector modernization available separately from the merge-blocking QA gate.
- Extended the GitHub Actions compatibility matrix and Scrutinizer test coverage to PHP 8.5, while keeping automated formatting checks on the minimum supported PHP version.

## [1.3.0] - 2026-07-20

### Security

- Added one bounded recursive sanitizer for request metadata, URLs and cURL output, cookies, headers, HTTP/mail records, xWhoops snapshots, Profiler data, and Smarty variables.
- Replaced the EXPLAIN HMAC fallback with a dedicated random signing key under protected XOOPS variable data; EXPLAIN now fails closed when that key is unavailable.
- Changed EXPLAIN failures to return a generic client response while recording details through the server-side XOOPS logging path.
- Hardened dumped-value and email-preview rendering against markup injection and external tracking-resource loads.
- Restricted on-demand EXPLAIN to one syntactically complete, read-only `SELECT`, including CTEs whose top-level statement is `SELECT`; writable CTEs, stacked statements, and file-output clauses are rejected.

### Fixed

- Made `nplus1_threshold = 0` disable repeated-query findings and normalized `1` to the minimum meaningful threshold of `2`.
- Connected the bootstrap budget to the measured `XOOPS Boot` lifecycle duration in milliseconds and persisted that value in request profiles.
- Kept malformed optional Ray channel metadata from escaping the integration boundary.
- Corrected Diagnostics to prefer the canonical `php-debugbar/php-debugbar` Composer package name.
- Removed the unavailable web-vitals placeholder from the compatibility Analytics page.
- Fixed AJAX editor links, failed OpenHandler requests, blocked mail-preview popups, falsy toolbar values, and late `Server-Timing` headers.
- Corrected dark-mode syntax-highlighting and VarDumper selector/line-height behavior.
- Prevented scalar HTTP details, template names, and template parameters from being interpreted as HTML in browser widgets.
- Made profile-table detection escape SQL `LIKE` wildcards and made vendor-asset corrections detect source drift instead of silently succeeding.
- Prevented an unavailable Monolog directory from degrading into a filesystem-root glob and added the standard XOOPS data-directory fallback.

### Changed

- Bumped module metadata to 1.3.0; existing installations must run the XOOPS module update.
- Added the bootstrap-time preference with a conservative default of `0` (disabled).
- Changed Smarty collection to off by default for new installations; existing saved preferences remain unchanged during update.
- Added EXPLAIN-key creation to install/update as a fail-soft module step and exposed capability status in Diagnostics.
- Prepared the module for standalone distribution with release documentation.
- Added user/webmaster and extension-development tutorials.
- Updated the optional Ray guide to match current module defaults, capability checks, and installation guidance.
- Made the optional Tracy administration control conditional on an explicit host-bootstrap capability.
- Clarified effective toolbar status when XOOPS Debug is disabled.
- Added persistent post-copy corrections for affected vendor assets and completed the standalone XOOPS/XMF PHPStan stubs.
- Focused Sonar analysis on authored sources by excluding generated browser-asset mirrors and declarative manifest duplication.

### Documentation

- Documented administrator gating, site-wide Monolog scope, query-mode visibility, sanitizer limits, Smarty defaults, bootstrap/N+1 semantics, and protected-data web-server rules.
- Replaced the generic built-in help page with a concise installation, configuration, security, troubleshooting, and documentation guide.
- Regenerated the standalone module file inventory.

### Compatibility

- Confirmed there are no hard imports, includes, inheritance relationships, or instantiations of xWhoops or Tracy.
- xWhoops and Tracy integrations remain optional and capability-detected.

## [1.2.0] - 2026-07-18

### Added

- XMF 2-aligned performance Analytics with slow-URL, N+1, module comparison, budget violation, and OPcache views.
- Compact request profile storage with configurable retention and row limits.
- Flight-recorder snapshots for budget violations.
- Xdebug status, one-shot “Profile this request” support, cachegrind catalog, viewing, deletion, and 30-day purge control.
- Protected XOOPS and Monolog log catalog with bounded tail reads, structured-context formatting, and secret redaction.
- Administrator-only system Diagnostics page for runtime, theme, package, and writable-directory checks.
- CSRF-protected controls for global XOOPS Debug and the DebugBar toolbar.
- Optional Monolog file adapter with configurable minimum level.
- Optional Ray forwarding.
- Optional redacted DebugBar context callback for xWhoops.

### Changed

- Ported the request lifecycle integration to XOOPS 2.7 preload events.
- Restricted toolbar output to authenticated administrators with global XOOPS Debug enabled.
- Added query modes, slow-query highlighting, repeated-query detection, and performance budgets.
- Aligned administration navigation and analytics terminology with the XMF 2 implementation.
- Added PHP 8.2 through PHP 8.5 compatibility coverage.

### Security

- Added CSRF validation to state-changing admin actions.
- Added allowlisted file selection for logs, flight records, and Xdebug profiles.
- Added output escaping and bounded/redacted diagnostic contexts.
- Prevented early anonymous Ray forwarding and non-admin toolbar rendering.

## [1.0.0-beta1]

### Added

- Initial XOOPS DebugBar module based on the XOOPS 2.6 work by Richard Griffith and trabis.
- Browser toolbar collectors for queries, timers, blocks, errors, Smarty data, and included files.
