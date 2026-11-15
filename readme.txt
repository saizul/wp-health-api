=== Health API ===
Contributors: saizul
Tags: health check, monitoring, prometheus, uptime, metrics
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Health check and Prometheus metrics endpoints for uptime monitors: database, cron, Action Scheduler, disk, PHP, updates, TLS expiry and more.

== Description ==

Health API gives your site a monitoring endpoint. Point an uptime monitor at a public "ok" URL, let Prometheus scrape the metrics, or read a detailed JSON report that tells you why a site is unhealthy.

**Endpoints**

* /wp-json/health/v1/status - public, no details. "ok" whenever WordPress and its database answer, or (optional) the overall result of all checks.
* /wp-json/health/v1/details - JSON report with every check. Needs a token.
* /wp-json/health/v1/metrics - Prometheus text exposition format. Needs a token.

**Checks**

* Database connectivity and query latency
* Object cache backend and a write/read round trip
* WP-Cron: overdue events and time since cron last ran
* Action Scheduler: past-due and failed actions
* Free disk space for uploads, uploads writable
* PHP version, memory limit and OPcache (hit rate, free memory, cache full)
* Pending core, plugin and theme updates, read from the stored update data (no remote requests)
* TLS certificate expiry of the home URL (optional, cached)
* Maintenance mode, including stale .maintenance files
* WooCommerce orders in the last hour (optional)

**Monitoring friendly**

* Warn and critical thresholds for every measured value.
* Overall ok / warn / critical map to HTTP 200 / 200 / 503, configurable, so plain HTTP monitors work.
* Token in the Authorization: Bearer or X-Health-Token header, compared in constant time. Define it in wp-config.php or let the plugin generate one; only its hash is stored.
* Reports are cached for a few seconds and every endpoint is rate limited. Repeated wrong tokens lock the client out.
* WP-CLI: wp health check (Nagios-style exit codes), wp health metrics, wp health token rotate.
* Every check also appears under Tools > Site Health.

The plugin never sends data anywhere. The only outgoing connection is the optional TLS check, which connects to your own site.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings > Health API and click "Generate new token", or run `wp health token rotate`, or add `define( 'WP_HEALTH_API_TOKEN', getenv( 'WP_HEALTH_API_TOKEN' ) );` to wp-config.php.
3. Give your uptime monitor https://example.com/wp-json/health/v1/status, or call /details and /metrics with the header `Authorization: Bearer YOUR_TOKEN`.
4. Adjust thresholds and status codes under Settings > Health API.

== Frequently Asked Questions ==

= My monitor gets 401 although the token is right. =

Some servers strip the Authorization header before PHP sees it. Send the token in the X-Health-Token header instead.

= How do I use it with Prometheus? =

Use metrics_path /wp-json/health/v1/metrics with bearer authorization. The README on GitHub has a scrape configuration and example alerting rules.

= Does it slow down my site? =

No. Reports are cached, requests are rate limited and nothing heavy runs on normal page views.

= Can I add my own checks? =

Yes, with the wp_health_api_checks filter. See the README on GitHub.

= Why does WP-CLI show OPcache as off? =

WP-CLI runs PHP's command line version, where OPcache is usually disabled. The HTTP endpoints report the web server's PHP.

== Changelog ==

= 0.1.0 =
* First release: public status, detailed JSON and Prometheus metrics endpoints; database, object cache, WP-Cron, Action Scheduler, disk, PHP/OPcache, updates, TLS, maintenance and WooCommerce checks; thresholds and status code mapping; token authentication; caching and rate limits; WP-CLI commands; Site Health tests; settings page.
