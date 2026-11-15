# Security policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | yes       |

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability
reporting ("Security" tab, "Report a vulnerability") on
<https://github.com/saizul/wp-health-api>, or contact the maintainer through
<https://saizul.com>.

Include the affected version, a description and steps to reproduce. You can
expect an acknowledgement within 7 days. Please give us reasonable time to
release a fix before disclosing the issue.

## How the plugin protects your site

- **Public endpoint**: `/status` returns only `ok`, `warn` or `critical`. It
  never includes versions, paths, plugin names or error messages.
- **Authentication**: `/details` and `/metrics` need a token in the
  `Authorization: Bearer` or `X-Health-Token` header, or a logged-in user with
  `manage_options` (REST cookie authentication with nonce). Tokens are never
  accepted in the query string.
- **Token storage**: a token in the `WP_HEALTH_API_TOKEN` constant stays in
  `wp-config.php` (or the environment) and is never written to the database.
  Generated tokens are 32 random bytes, shown once, and stored only as a
  SHA-256 hash. Constants shorter than 16 characters are rejected.
- **Comparison**: both the presented token and the secret are hashed to
  fixed-length digests and compared with `hash_equals()`, so timing does not
  reveal the content or the length of the secret.
- **Brute force**: requests are rate limited per client before the token is
  checked, and a client that sends too many wrong tokens is locked out for the
  rest of a 15-minute window. Client IPs are kept only as keyed hashes. Proxy
  headers are ignored unless the site owner selects one.
- **Admin**: the settings page, token generation and deletion require
  `manage_options` and a nonce; every value is validated before it is stored
  and all output is escaped.
- **No data leaves the site**: update counts come from stored transients; the
  only outgoing connection is the optional TLS check against the site itself.
- **Uninstall** removes the settings, the token hash, the recorded cron time,
  the heartbeat event and all cached data.

The detailed report contains information useful to an attacker (PHP version,
plugin update list, file system path of uploads). Treat the token like a
password, use HTTPS, and rotate it with `wp health token rotate` if it leaks.
