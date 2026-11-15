# Health API for WordPress

[![CI](https://github.com/saizul/wp-health-api/actions/workflows/ci.yml/badge.svg)](https://github.com/saizul/wp-health-api/actions/workflows/ci.yml)
[![Release zip](https://github.com/saizul/wp-health-api/actions/workflows/release-zip.yml/badge.svg)](https://github.com/saizul/wp-health-api/actions/workflows/release-zip.yml)
![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.4-777bb4)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

A monitoring endpoint for WordPress sites. Point an uptime monitor at a public
"ok" URL, point Prometheus at `/metrics`, or read a detailed JSON report that
tells you *why* a site is unhealthy: the database is slow, cron stopped
running, Action Scheduler is backing up, the disk is filling, OPcache is full,
updates are piling up, the TLS certificate is about to expire, the site is stuck
in maintenance mode, or orders have stopped coming in.

Plugin slug: `wp-health-api` - display name: *Health API*.

## Contents

- [Features](#features)
- [Requirements and installation](#requirements-and-installation)
- [Endpoints](#endpoints)
- [Authentication](#authentication)
- [Checks](#checks)
- [Thresholds and status codes](#thresholds-and-status-codes)
- [Prometheus](#prometheus)
- [Caching and rate limits](#caching-and-rate-limits)
- [WP-CLI](#wp-cli)
- [Site Health](#site-health)
- [Hooks for developers](#hooks-for-developers)
- [Design](#design)
- [Development](#development)
- [FAQ](#faq)

## Features

- **Three endpoints**: a public `/status` with no details, an authenticated
  `/details` JSON report and an authenticated `/metrics` endpoint in the
  Prometheus text exposition format.
- **Ten checks**: database, object cache, WP-Cron, Action Scheduler, disk
  space, PHP runtime and OPcache, pending updates, TLS certificate expiry,
  maintenance mode and WooCommerce order volume.
- **Warn and critical thresholds for every measured value**, editable in the
  settings screen or from code, in either direction ("above" for latency,
  "below" for free space).
- **Status code mapping**: overall `ok`, `warn` and `critical` map to HTTP
  200 / 200 / 503 by default, so a plain HTTP uptime monitor works without
  parsing JSON. All three codes are configurable.
- **Token authentication**: `Authorization: Bearer <token>` or
  `X-Health-Token`, compared in constant time. The token lives in a
  `wp-config.php` constant or is created by the plugin, which stores only its
  SHA-256 hash.
- **Cheap to call**: reports are cached for 10 seconds (with stampede
  protection), the TLS certificate for 12 hours, and every endpoint is rate
  limited per client. Repeated bad tokens lock a client out for up to 15 minutes.
- **No outgoing requests** for update information: counts come from the
  transients WordPress already stores.
- **WP-CLI**: `wp health check`, `wp health metrics`, `wp health token rotate|status|revoke`.
- **Site Health**: each check appears under Tools > Site Health, and a
  "Health API" section is added to the Info tab.
- Settings page, translations template (`.pot`), clean `uninstall.php`.
- The check, threshold and formatting logic is plain PHP without WordPress and
  is covered by more than 250 PHPUnit tests.

## Requirements and installation

- WordPress 6.0 or newer, PHP 7.4 or newer (tested on 7.4 to 8.4).
- Optional: a persistent object cache, Action Scheduler (bundled with
  WooCommerce and many other plugins), WooCommerce, the OpenSSL extension for
  the TLS check.

1. Download `wp-health-api.zip` from the
   [latest release](https://github.com/saizul/wp-health-api/releases) and
   upload it under Plugins > Add New > Upload Plugin, then activate it.
2. Create an access token: Settings > Health API > *Generate new token*, or
   `wp health token rotate`, or define a constant (see below).
3. Test it:

   ```bash
   curl -s https://example.com/wp-json/health/v1/status
   curl -s -H "Authorization: Bearer $HEALTH_TOKEN" https://example.com/wp-json/health/v1/details
   ```

Installing from source: `git clone`, then copy the folder to
`wp-content/plugins/`. The plugin has no runtime Composer dependencies and
ships its own autoloader.

## Endpoints

All endpoints live under the REST API namespace `health/v1` and answer `GET`
(and `HEAD`). Responses carry `Cache-Control: no-store` and
`X-Robots-Tag: noindex`.

| Endpoint | Auth | Purpose |
| --- | --- | --- |
| `/wp-json/health/v1/status` | none | Minimal answer for uptime monitors. |
| `/wp-json/health/v1/details` | token | Full JSON report. |
| `/wp-json/health/v1/metrics` | token | Prometheus text format. |

Sites without pretty permalinks use `/?rest_route=/health/v1/status`.

### `/status`

```json
{"status":"ok"}
```

Two modes (Settings > Health API > Responses):

- **Alive** (default): always `{"status":"ok"}` with the `ok` status code.
  It means PHP, WordPress and the database answered, since WordPress cannot
  boot without them. No checks run, so it costs almost nothing.
- **Overall**: returns the overall status of the (cached) report, `ok`, `warn`
  or `critical`, with the mapped status code, but no details.

### `/details`

Query parameters:

- `checks=database,cron` runs only these checks.
- `fresh=1` skips the short report cache (still rate limited).

```json
{
  "site": "https://example.com/",
  "version": "0.1.0",
  "status": "warn",
  "generated_at": "2026-11-15T08:00:00+00:00",
  "timestamp": 1794729600,
  "duration_ms": 14.2,
  "cached": false,
  "counts": { "ok": 7, "warn": 1, "critical": 0, "skipped": 1 },
  "checks": {
    "database": {
      "status": "ok",
      "message": "Database is reachable (1.5 ms).",
      "skipped": false,
      "duration_ms": 1.61,
      "details": { "connected": true, "latency_ms": 1.5, "server": "10.11.6-MariaDB", "breaches": {} }
    },
    "updates": {
      "status": "warn",
      "message": "1 plugin updates pending (limit 1)",
      "skipped": false,
      "duration_ms": 0.21,
      "details": {
        "core": { "available": false, "version": null, "last_checked": 1794726000 },
        "plugins": { "akismet/akismet.php": "5.3.5" },
        "themes": {},
        "translations": 0,
        "last_checked": { "core": 1794726000, "plugins": 1794726000, "themes": 1794726000 },
        "breaches": { "plugins": { "status": "warn", "value": 1, "limit": 1 } }
      }
    },
    "woocommerce": {
      "status": "ok",
      "message": "WooCommerce is not active.",
      "skipped": true,
      "duration_ms": 0.01,
      "details": {}
    }
  }
}
```

The HTTP status code follows the overall status (200 / 200 / 503 by default).
Skipped checks (a feature that is not installed) never affect the overall
status. Check messages and ids are English and stable, so they are safe to
match in alerting rules; the admin screens show translated labels.

## Authentication

`/details` and `/metrics` accept either of:

```
Authorization: Bearer YOUR_TOKEN
X-Health-Token: YOUR_TOKEN
```

Use `X-Health-Token` when your web server or host strips the `Authorization`
header (common with Apache + PHP-FPM/CGI unless it is passed through).
Tokens are never read from the query string, so they do not end up in access
logs.

**Where the token comes from** (first match wins):

1. The `WP_HEALTH_API_TOKEN` constant in `wp-config.php`. It must be at least
   16 characters; shorter values are rejected. Reading it from the environment
   keeps it out of files:

   ```php
   define( 'WP_HEALTH_API_TOKEN', getenv( 'WP_HEALTH_API_TOKEN' ) );
   ```

2. A generated token: Settings > Health API > *Generate new token* or
   `wp health token rotate`. It is 32 random bytes (64 hex characters), shown
   once, and only `sha256:<hash>` is stored in the `wp_health_api_token_hash`
   option. Rotating invalidates the previous token immediately.

Both are compared in constant time: the presented token and the secret are
hashed to fixed-length digests and compared with `hash_equals()`, so neither
the content nor the length of the secret leaks through timing.

Logged-in administrators (`manage_options`, filterable) can also read the
endpoints through the normal REST cookie + nonce authentication, which is
handy for checking the JSON in a browser.

Without any token configured, both endpoints answer `401` with the code
`wp_health_api_no_token`. A wrong token answers `401` with
`WWW-Authenticate: Bearer realm="wp-health-api"`.

## Checks

| Id | What it measures | Default thresholds | On by default |
| --- | --- | --- | --- |
| `database` | `SELECT 1` through `$wpdb`, latency, server version. Failure is critical. | latency warn 250 ms, critical 1000 ms | yes |
| `object_cache` | Backend (redis, memcached, apcu, relay, ...), whether it is persistent, a write / forced read / delete round trip. A failed round trip on a persistent cache is critical. | latency warn 50 ms, critical 250 ms | yes |
| `cron` | Scheduled and overdue WP-Cron events (late by more than the grace period, default 10 min), oldest overdue age, `DISABLE_WP_CRON`, time since cron last ran. | overdue warn 10, critical 50; last run warn 1 h, critical 6 h | yes |
| `action_scheduler` | Past-due pending actions and actions that failed in the last 24 hours. Skipped without Action Scheduler. | past-due warn 25, critical 250; failed warn 10 | yes |
| `disk` | Free and total space of the file system holding the uploads directory, and whether uploads is writable (not writable is critical). | free warn 10 % / 1 GiB, critical 5 % / 256 MiB | yes |
| `php` | PHP version, `memory_limit`, OPcache enabled, hit rate (judged after 1000 requests), free memory, cache full. | version warn < 8.1, critical < 7.4; memory_limit warn 64 MiB, critical 32 MiB; hit rate warn 90 %; free memory warn 5 % | yes |
| `updates` | Pending core, plugin, theme and translation updates from the `update_core`, `update_plugins` and `update_themes` site transients. No remote calls. | core warn 1; plugins warn 1, critical 10; themes warn 1 | yes |
| `ssl` | Certificate of the home URL: days left, validity period, whether it covers the host name (RFC 6125 wildcard rules). Cached 12 h, errors 15 min. Skipped for `http://` sites. | days left warn 14, critical 7; expired or wrong host is critical | no |
| `maintenance` | `.maintenance` file and `wp_is_maintenance_mode()`. Reports a stale file left by an interrupted update. | active: warn (configurable) | yes |
| `woocommerce` | Orders created in the last hour, by status (HPOS compatible). Skipped without WooCommerce. | disabled; set "orders below" and "failed orders above" for your shop | no |

Notes:

- **Cron "last run"**: WordPress does not record when cron last ran, so the
  plugin stores the time on every `wp-cron.php` request and from a
  five-minute heartbeat event (`wp_health_api_heartbeat`), which also catches a
  system cron or `wp cron event run`. Until the first run is recorded the
  value is unknown and not judged.
- **Maintenance mode**: while WordPress is really in maintenance mode it
  answers every request with its own 503 page before plugins load, so HTTP
  monitors see the outage anyway. The check is mostly useful for WP-CLI, for
  stale `.maintenance` files and for plugins that switch maintenance mode
  through the `enable_maintenance_mode` filter.
- **PHP under WP-CLI** reports the CLI SAPI, where OPcache is usually off. Run
  the HTTP endpoints to see the web server's PHP.
- **Disk space**: `disk_free_space()` is disabled on many shared hosts. The
  check then reports that the value cannot be read and stays `ok`.
- **TLS**: the check connects to the host of the home URL from the server
  itself. Behind a CDN or load balancer this may reach a different certificate
  than visitors see; change the target with the `wp_health_api_ssl_url` filter.

## Thresholds and status codes

Every measured value has a warn and a critical limit. A value **on** the limit
counts as breaching it. Leave a limit empty to disable that level. The overall
status is the worst status of all checks that ran.

Settings > Health API has a table with all limits, the status code for each
overall result, the public `/status` mode, the status used while maintenance
mode is active, cache and rate limit values, the cron grace period, PHP version
limits, and switches for "warn without OPcache" and "warn without a persistent
object cache".

To manage settings from code (for example per environment), use the
`wp_health_api_settings` filter. Values are validated after the filter:

```php
add_filter( 'wp_health_api_settings', function ( array $settings ) {
	$settings['checks']['ssl']                             = true;
	$settings['checks']['woocommerce']                     = true;
	$settings['thresholds']['woocommerce.orders_last_hour'] = array( 'warn' => 2, 'critical' => 0 );
	$settings['thresholds']['database.latency_ms']          = array( 'warn' => 100, 'critical' => 500 );
	$settings['status_codes']['warn']                       = 200;
	$settings['status_codes']['critical']                   = 503;
	return $settings;
} );
```

Threshold keys: `database.latency_ms`, `object_cache.latency_ms`,
`cron.overdue_events`, `cron.last_run_age` (seconds),
`action_scheduler.past_due`, `action_scheduler.failed_24h`,
`disk.free_percent`, `disk.free_mb`, `php.memory_limit_mb`,
`php.opcache_hit_percent`, `php.opcache_free_memory_percent`, `updates.core`,
`updates.plugins`, `updates.themes`, `ssl.days_left`,
`woocommerce.orders_last_hour`, `woocommerce.failed_orders_last_hour`.

## Prometheus

```yaml
scrape_configs:
  - job_name: wordpress
    metrics_path: /wp-json/health/v1/metrics
    scheme: https
    scrape_interval: 60s
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/secrets/wp-health-token
    static_configs:
      - targets: ['example.com']
```

The output follows the [text exposition format](https://prometheus.io/docs/instrumenting/exposition_formats/)
(`Content-Type: text/plain; version=0.0.4; charset=utf-8`): one `# HELP` and
one `# TYPE` line per metric before its samples, all samples of a metric in one
group, backslash and line feed escaped in help text, backslash, double quote
and line feed escaped in label values, `NaN` / `+Inf` / `-Inf` for special
values, names matching `[a-zA-Z_:][a-zA-Z0-9_:]*`, label names never starting
with `__`, and a final line feed. No timestamps are sent; Prometheus uses the
scrape time. `/metrics` always answers 200 so a critical site does not look
like a failed scrape.

| Metric | Labels | Meaning |
| --- | --- | --- |
| `wp_health_up` | | Always 1 when a report was produced. |
| `wp_health_status` | | Overall status: 0 ok, 1 warn, 2 critical. |
| `wp_health_info` | `plugin_version`, `wordpress_version` | Always 1. |
| `wp_health_report_timestamp_seconds` | | When the report was generated. |
| `wp_health_report_duration_seconds` | | Time taken by all checks. |
| `wp_health_report_cached` | | 1 when served from the cache. |
| `wp_health_check_status` | `check` | Per check: 0 ok, 1 warn, 2 critical (not for skipped checks). |
| `wp_health_check_skipped` | `check` | 1 when a check does not apply. |
| `wp_health_check_duration_seconds` | `check` | Time taken by each check. |
| `wp_health_database_up`, `wp_health_database_latency_seconds` | | Database. |
| `wp_health_object_cache_persistent` | `backend` | Persistent cache in use. |
| `wp_health_object_cache_hit_test`, `wp_health_object_cache_latency_seconds` | | Round trip result and time. |
| `wp_health_cron_events`, `wp_health_cron_overdue_events`, `wp_health_cron_oldest_overdue_seconds`, `wp_health_cron_disabled`, `wp_health_cron_last_run_timestamp_seconds` | | WP-Cron. |
| `wp_health_action_scheduler_past_due_actions`, `wp_health_action_scheduler_failed_actions_24h` | | Action Scheduler. |
| `wp_health_uploads_writable`, `wp_health_disk_free_bytes`, `wp_health_disk_total_bytes`, `wp_health_disk_free_ratio` | | Disk. |
| `wp_health_php_info` | `version` | Always 1. |
| `wp_health_php_memory_limit_bytes` (-1 = unlimited), `wp_health_php_memory_peak_bytes` | | PHP memory. |
| `wp_health_opcache_enabled`, `wp_health_opcache_hit_ratio`, `wp_health_opcache_used_memory_bytes`, `wp_health_opcache_free_memory_bytes`, `wp_health_opcache_wasted_memory_bytes`, `wp_health_opcache_cached_scripts`, `wp_health_opcache_cache_full` | | OPcache. |
| `wp_health_updates_pending` | `type` (core, plugin, theme, translation) | Pending updates. |
| `wp_health_updates_last_checked_timestamp_seconds` | `type` | Last WordPress update check. |
| `wp_health_ssl_certificate_expiry_timestamp_seconds`, `wp_health_ssl_certificate_days_left` | `host` | TLS certificate. |
| `wp_health_maintenance_mode` | | 1 while in maintenance mode. |
| `wp_health_woocommerce_orders_last_hour_total` | | Orders in the last hour. |
| `wp_health_woocommerce_orders_last_hour` | `status` | Orders in the last hour by status. |

Example alerting rules:

```yaml
groups:
  - name: wordpress
    rules:
      - alert: WordPressCritical
        expr: wp_health_status == 2
        for: 5m
      - alert: WordPressCheckWarning
        expr: wp_health_check_status == 1
        for: 30m
        labels: { severity: warning }
      - alert: WordPressCronStopped
        expr: time() - wp_health_cron_last_run_timestamp_seconds > 3600
        for: 10m
      - alert: WordPressCertificateExpiring
        expr: wp_health_ssl_certificate_days_left < 14
      - alert: WordPressDiskFilling
        expr: predict_linear(wp_health_disk_free_bytes[6h], 24 * 3600) < 0
```

Add labels to every sample (for example when several sites share one job):

```php
add_filter( 'wp_health_api_metric_labels', function () {
	return array( 'site' => 'shop' );
} );
```

Without network access to the site, write the metrics to a file for the
node_exporter textfile collector from a system cron:

```bash
wp health metrics --path=/var/www/html > /var/lib/node_exporter/textfile/wordpress.prom.tmp \
  && mv /var/lib/node_exporter/textfile/wordpress.prom.tmp /var/lib/node_exporter/textfile/wordpress.prom
```

## Caching and rate limits

- **Report cache**: 10 seconds by default (0 to 300, 0 disables it), stored
  in transients, so a persistent object cache is used when present. While one
  request runs the checks, concurrent requests get the previous report
  instead of running the checks again. `fresh=1` and `--fresh` skip it.
- **Rate limits**: per client IP and fixed window (default 60 seconds):
  60 requests to `/status`, 30 to `/details` and `/metrics`. 0 means
  unlimited. Answers include `X-RateLimit-Limit`, `X-RateLimit-Remaining` and
  `X-RateLimit-Reset`; a blocked request gets `429` with `Retry-After`.
- **Lock-out**: after 10 wrong tokens from one client (default) within a
  15-minute window, every request from it gets `429` until the window ends,
  even with the right token. Missing tokens are not counted.
- **Client IP**: `REMOTE_ADDR` by default. Behind a proxy or CDN, choose the
  header it sets (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP` or
  `True-Client-IP`). Only do this when the proxy always sets the header;
  otherwise clients could forge it. IPs are stored as keyed hashes, never in
  clear.

Counters live in transients. Without a persistent object cache that means the
options table; with very frequent checks from many monitors, a persistent
object cache is recommended. The limiter is meant to stop floods, not to meter
exactly: concurrent requests can occasionally both pass at the edge of a limit.

## WP-CLI

```bash
wp health check                          # table, exit code 0 ok, 1 warn, 2 critical
wp health check --checks=database,cron --fresh
wp health check --format=json            # the same report as /details
wp health metrics                        # Prometheus text
wp health token rotate --yes             # new token, printed once
wp health token rotate --yes --porcelain # only the token, for scripts
wp health token status                   # where the token comes from
wp health token revoke --yes             # delete the generated token
```

`wp health check` follows the Nagios plugin convention for exit codes, so it
can be used directly in shell scripts and cron jobs.

## Site Health

Every enabled check becomes a test in Tools > Site Health ("Health API:
Database", "Health API: WP-Cron", ...): `warn` shows as *recommended*,
`critical` as *critical*, skipped checks as passed. All tests share one cached
report. Tools > Site Health > Info gets a "Health API" section with the
endpoint URLs, the token source (never the token), the enabled checks and the
cache lifetime.

## Hooks for developers

| Hook | Type | Purpose |
| --- | --- | --- |
| `wp_health_api_settings` | filter | Change settings before validation. |
| `wp_health_api_checks` | filter | Add your own checks (objects implementing `Saizul\HealthApi\Check\Check`). |
| `wp_health_api_probes` | filter | Replace the data source of a built-in check. |
| `wp_health_api_metric_labels` | filter | Labels added to every metric sample. |
| `wp_health_api_capability` | filter | Capability that may read the endpoints without a token (default `manage_options`). |
| `wp_health_api_ssl_url` | filter | URL whose certificate is checked (default: home URL). |
| `wp_health_api_ssl_timeout` | filter | TLS handshake timeout in seconds (default 5). |
| `wp_health_api_object_cache_backend` | filter | Backend name shown in reports. |
| `wp_health_api_report` | action | Fires with every report (fresh or cached). |
| `wp_health_api_token_rotated`, `wp_health_api_token_revoked` | action | Token changes (the token itself is not passed). |

A custom check:

```php
use Saizul\HealthApi\Check\Check;
use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

final class Mail_Queue_Check implements Check {
	public function id(): string {
		return 'mail_queue';
	}

	public function run(): CheckResult {
		$queued = (int) get_option( 'my_mail_queue_length', 0 );
		$status = ( new Threshold( 100, 1000 ) )->evaluate( $queued );

		return new CheckResult(
			$this->id(),
			$status,
			sprintf( '%d e-mails queued.', $queued ),
			array( 'queued' => $queued ),
			array( Sample::gauge( 'mail_queue_length', $queued, 'E-mails waiting to be sent.' ) )
		);
	}
}

add_filter( 'wp_health_api_checks', function ( array $checks ) {
	$checks[] = new Mail_Queue_Check();
	return $checks;
} );
```

Check ids must match `[a-z][a-z0-9_]*`; the sample above is exported as
`wp_health_mail_queue_length`. A check that throws becomes a critical result
instead of breaking the report.

## Design

```
src/        WordPress-free library: statuses, thresholds, checks, report,
            runner and cache, Prometheus formatter, token verification,
            rate limiter, settings validation.
  Probe/    Interfaces through which checks read their data.
includes/   WordPress layer: probe implementations ($wpdb, transients, cron
            option, Action Scheduler store, wc_get_orders), REST controller,
            settings page, Site Health, WP-CLI, bootstrap.
tests/      PHPUnit suite with fake probes and WordPress / WP-CLI stubs.
```

Checks never call WordPress directly; they get a probe. That keeps every
decision (thresholds, grace periods, wildcard matching, maintenance file age,
update counting, escaping) testable without a WordPress install.

## Development

```bash
git clone https://github.com/saizul/wp-health-api.git
cd wp-health-api
composer install
composer test       # PHPUnit
composer phpcs      # WordPress Coding Standards + PHPCompatibilityWP
composer lint       # php -l on every file
composer make-pot   # regenerate languages/wp-health-api.pot
```

CI runs `php -l`, PHPCS and PHPUnit on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4 and
checks that the `.pot` file is up to date. Publishing a GitHub release builds
`wp-health-api.zip` (after checking that the tag matches the plugin version)
and attaches it to the release. See [CONTRIBUTING.md](CONTRIBUTING.md).

## FAQ

**Which URL do I give my uptime monitor?**
`/wp-json/health/v1/status`. Switch it to "overall" mode if the monitor should
alert on check results too, or use `/details` with a token when the monitor
can send headers.

**Does it slow my site down?**
No. On normal page views the plugin only makes sure its heartbeat event is
scheduled (a lookup in the already loaded cron option), and `wp-cron.php`
requests store a timestamp at most once a minute. Reports are cached and
requests are rate limited.

**Does it phone home?**
No. The only outgoing connection is the optional TLS check, which connects to
your own site.

**Multisite?**
The plugin works per site: each site has its own endpoints, settings and
token. Network-wide setups have not been tested in depth yet; reports are
welcome.

## License

GPL-2.0-or-later. Copyright (c) 2026 Saizul Amin. See [LICENSE](LICENSE).
