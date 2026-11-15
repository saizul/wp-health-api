# Contributing

Thanks for helping improve Health API. Bug reports, new checks, better
defaults, documentation and translations are all welcome.

## Reporting a bug

Open an issue with:

- WordPress and PHP versions, the web server, and whether a persistent object
  cache, Action Scheduler or WooCommerce is active
- the output of `wp health check --format=json` (or the `/details` response)
- what you expected instead

Please never post real tokens, `wp-config.php` contents or customer data.
Replace tokens with `YOUR_TOKEN`.

## Development setup

```bash
git clone https://github.com/saizul/wp-health-api.git
cd wp-health-api
composer install
composer test       # PHPUnit
composer phpcs      # WordPress Coding Standards + PHPCompatibilityWP
composer lint       # php -l
composer make-pot   # regenerate the translations template
```

PHP 7.4 or newer is required. Dependencies are resolved for PHP 7.4
(`config.platform`) so the same lock file works across the CI matrix.

## Where things go

- `src/` is a WordPress-free library: statuses, thresholds, checks, the
  report, the runner and cache, the Prometheus formatter, token verification,
  the rate limiter and settings validation. It must not call WordPress
  functions. Cover every change here with tests.
- `src/Probe/` holds the interfaces checks read their data through.
  WordPress implementations live in `includes/Probe/`; tests use the fakes in
  `tests/Fakes/`.
- `includes/` is the WordPress layer (REST controller, settings page, Site
  Health, WP-CLI, bootstrap). It is tested against the stubs in
  `tests/Stubs/`.

## Adding a check

1. Add a probe interface in `src/Probe/` if the check needs new data, a
   WordPress implementation in `includes/Probe/` and a fake in
   `tests/Fakes/FakeProbes.php`.
2. Add the check in `src/Check/`, implementing `Check`. Use `Verdict` for
   thresholds so messages and the `breaches` details stay consistent, and emit
   metric samples with `Sample::gauge()` (names without the `wp_health_`
   prefix, units in the name: `_seconds`, `_bytes`, `_ratio`).
3. Register it in `Settings\Defaults` (id, default on/off, thresholds),
   `CheckFactory`, `WP\Services::probes()` and `WP\Labels`.
4. Write tests for every status, the message and the samples, and document the
   check, its thresholds and its metrics in the README.

## Guidelines

- Code must run on PHP 7.4: no union types, `match`, named arguments,
  constructor promotion, enums, nullsafe operator, or argument unpacking with
  string keys (also not in test stubs).
- Follow the WordPress Coding Standards (`composer phpcbf` fixes most issues).
- Escape all output and check capabilities and nonces in every admin handler.
- Wrap user-facing strings with the `wp-health-api` text domain, add a
  `/* translators: */` comment for placeholders and run `composer make-pot`
  (CI checks the `.pot` file). API messages and ids stay English and stable.
- Never make remote requests from a check except to the site itself.
- New hooks start with `wp_health_api_` and are documented in the README.
- Add an entry under "Unreleased" in `CHANGELOG.md`.

## Releasing

1. Update the version in `wp-health-api.php` (header and
   `WP_HEALTH_API_VERSION`), `includes/Plugin.php`, `readme.txt` (Stable tag
   and changelog), `bin/make-pot.php` and `CHANGELOG.md`, then run
   `composer make-pot`.
2. Tag `vX.Y.Z` and publish a GitHub release. The `Release zip` workflow checks
   the versions and attaches `wp-health-api.zip`.

By contributing you agree that your contribution is licensed under the
GPL-2.0-or-later license of this project.
