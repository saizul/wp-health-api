<?php
/**
 * TLS certificate probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use InvalidArgumentException;
use RuntimeException;
use Saizul\HealthApi\Probe\SslProbe;
use Saizul\HealthApi\Ssl\Certificate;

defined( 'ABSPATH' ) || exit;

/**
 * Opens a TLS connection and reads the peer certificate.
 *
 * Peer verification is turned off on purpose: the check must be able to
 * read an expired or mismatched certificate to report on it. Host name
 * and validity are evaluated by the check itself.
 */
final class StreamSslProbe implements SslProbe {

	/**
	 * Fetch the certificate.
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @throws RuntimeException When the connection or parsing fails.
	 */
	public function certificate( string $host, int $port ): Certificate {
		if ( ! function_exists( 'openssl_x509_parse' ) || ! function_exists( 'stream_socket_client' ) ) {
			throw new RuntimeException( 'The OpenSSL extension or stream sockets are not available.' );
		}

		/**
		 * Filters the timeout of the TLS handshake in seconds.
		 *
		 * @param int $timeout Seconds.
		 */
		$timeout = max( 1, (int) apply_filters( 'wp_health_api_ssl_timeout', 5 ) );
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);
		$target  = ( false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . $port;
		$errno   = 0;
		$errstr  = '';
		$client  = @stream_socket_client( 'ssl://' . $target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failures are reported through $errstr.
		if ( false === $client ) {
			throw new RuntimeException( esc_html( '' !== $errstr ? $errstr : 'TLS connection failed.' ) );
		}
		$params = stream_context_get_params( $client );
		fclose( $client ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A socket, not a file.

		$peer = $params['options']['ssl']['peer_certificate'] ?? null;
		if ( null === $peer ) {
			throw new RuntimeException( 'The server did not present a certificate.' );
		}
		$parsed = openssl_x509_parse( $peer );
		if ( ! is_array( $parsed ) ) {
			throw new RuntimeException( 'The certificate could not be parsed.' );
		}
		try {
			return Certificate::from_openssl( $parsed );
		} catch ( InvalidArgumentException $e ) {
			throw new RuntimeException( esc_html( $e->getMessage() ) );
		}
	}
}
