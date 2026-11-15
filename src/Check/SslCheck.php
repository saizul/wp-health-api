<?php
/**
 * TLS certificate expiry check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use RuntimeException;
use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\SslProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Ssl\Certificate;
use Saizul\HealthApi\Ssl\HostnameMatcher;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Support\Store;
use Saizul\HealthApi\Threshold;

/**
 * Days until the certificate of the home URL expires.
 *
 * Fetching a certificate needs a TLS handshake, so results are cached:
 * successful reads for the configured number of hours, failures for
 * 15 minutes.
 */
final class SslCheck implements Check {

	const ERROR_TTL = 900;

	/**
	 * Probe.
	 *
	 * @var SslProbe
	 */
	private $probe;

	/**
	 * Cache.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Days left threshold.
	 *
	 * @var Threshold
	 */
	private $days;

	/**
	 * URL to check.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Cache lifetime in seconds.
	 *
	 * @var int
	 */
	private $ttl;

	/**
	 * Constructor.
	 *
	 * @param SslProbe  $probe Probe.
	 * @param Store     $store Cache.
	 * @param Clock     $clock Clock.
	 * @param Threshold $days Days left threshold.
	 * @param string    $url Home URL.
	 * @param int       $ttl Cache lifetime for successful reads in seconds.
	 */
	public function __construct( SslProbe $probe, Store $store, Clock $clock, Threshold $days, string $url, int $ttl = 43200 ) {
		$this->probe = $probe;
		$this->store = $store;
		$this->clock = $clock;
		$this->days  = $days;
		$this->url   = $url;
		$this->ttl   = max( 60, $ttl );
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'ssl';
	}

	/**
	 * Host and port of an https URL.
	 *
	 * @param string $url URL.
	 * @return array{0:string,1:int}|null
	 */
	public static function endpoint( string $url ): ?array {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return null;
		}

		return array( strtolower( trim( (string) $parts['host'], '[]' ) ), (int) ( $parts['port'] ?? 443 ) );
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$endpoint = self::endpoint( $this->url );
		if ( null === $endpoint ) {
			return CheckResult::skipped( $this->id(), 'The home URL does not use https.' );
		}
		list( $host, $port ) = $endpoint;
		$key                 = 'ssl_' . md5( $host . ':' . $port );
		$cached              = $this->store->get( $key );
		$from_cache          = is_array( $cached );
		if ( ! $from_cache ) {
			try {
				$cached = array( 'certificate' => $this->probe->certificate( $host, $port )->to_array() );
				$this->store->set( $key, $cached, $this->ttl );
			} catch ( RuntimeException $e ) {
				$cached = array( 'error' => $e->getMessage() );
				$this->store->set( $key, $cached, self::ERROR_TTL );
			}
		}

		if ( isset( $cached['error'] ) ) {
			return new CheckResult(
				$this->id(),
				Status::WARN,
				'Could not read the certificate: ' . $cached['error'],
				array(
					'host'   => $host,
					'port'   => $port,
					'cached' => $from_cache,
				)
			);
		}

		$certificate = Certificate::from_array( (array) ( $cached['certificate'] ?? array() ) );
		$now         = $this->clock->now();
		$seconds     = $certificate->seconds_left( $now );
		$days        = $seconds / 86400;
		$covers      = HostnameMatcher::covers( $certificate, $host );
		$verdict     = new Verdict();

		if ( $seconds <= 0 ) {
			$verdict->add( Status::CRITICAL, 'The certificate has expired' );
		} else {
			$verdict->threshold( 'days_left', $this->days, $days, 'Certificate expires in %s days (limit %s days)' );
		}
		if ( $certificate->valid_from > $now ) {
			$verdict->add( Status::CRITICAL, 'The certificate is not valid yet' );
		}
		if ( ! $covers ) {
			$verdict->add( Status::CRITICAL, sprintf( 'The certificate does not cover %s', $host ) );
		}

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message( sprintf( 'Certificate for %s expires in %d days.', $host, (int) floor( $days ) ) ),
			array(
				'host'        => $host,
				'port'        => $port,
				'subject'     => $certificate->subject,
				'issuer'      => $certificate->issuer,
				'valid_from'  => $certificate->valid_from,
				'valid_to'    => $certificate->valid_to,
				'days_left'   => round( $days, 2 ),
				'covers_host' => $covers,
				'cached'      => $from_cache,
				'breaches'    => $verdict->breaches(),
			),
			array(
				Sample::gauge( 'ssl_certificate_expiry_timestamp_seconds', $certificate->valid_to, 'Unix time the TLS certificate of the home URL expires.', array( 'host' => $host ) ),
				Sample::gauge( 'ssl_certificate_days_left', round( $days, 3 ), 'Days until the TLS certificate of the home URL expires.', array( 'host' => $host ) ),
			)
		);
	}
}
