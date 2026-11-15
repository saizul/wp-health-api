# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-15

### Added

- `GET /wp-json/health/v1/status`: public, minimal answer for uptime monitors,
  in "alive" mode (default) or "overall" mode (overall status, no details).
- `GET /wp-json/health/v1/details`: JSON report with every check, optional
  `checks` and `fresh` parameters.
- `GET /wp-json/health/v1/metrics`: Prometheus text exposition format 0.0.4
  with HELP/TYPE lines, escaping of help text and label values, merged metric
  groups and validated metric and label names.
- Checks: database connectivity and latency; object cache backend and round
  trip; WP-Cron overdue events and last run (recorded by the plugin, with a
  five-minute heartbeat); Action Scheduler past-due and failed actions; free
  disk space of the uploads file system; PHP version, memory limit and
  OPcache; pending core, plugin, theme and translation updates from the update
  transients; TLS certificate expiry and host name match (optional, cached);
  maintenance mode and stale `.maintenance` files; WooCommerce orders in the
  last hour (optional).
- Warn and critical thresholds for every measured value, in both directions,
  with validation; overall status mapped to configurable HTTP status codes
  (200 / 200 / 503 by default).
- Token authentication through `Authorization: Bearer` or `X-Health-Token`,
  constant-time comparison, token from the `WP_HEALTH_API_TOKEN` constant or a
  generated token stored as a SHA-256 hash; access for administrators through
  REST cookie authentication.
- Report cache with stampede protection, per-client fixed-window rate limits,
  lock-out after repeated wrong tokens, optional trusted proxy header.
- WP-CLI: `wp health check` (Nagios-style exit codes), `wp health metrics`,
  `wp health token rotate|status|revoke`.
- Site Health tests for every enabled check and a "Health API" section in
  Site Health > Info.
- Settings page under Settings > Health API, translations template,
  `uninstall.php`.
- Filters and actions for settings, custom checks, probes, metric labels,
  capability and the TLS target.
- PHPUnit suite running without WordPress (fake probes, WordPress and WP-CLI
  stubs), CI on PHP 7.4 to 8.4, release zip workflow.

[Unreleased]: https://github.com/saizul/wp-health-api/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/saizul/wp-health-api/releases/tag/v0.1.0
