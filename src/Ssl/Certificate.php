<?php
/**
 * TLS certificate value object.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Ssl;

use InvalidArgumentException;

/**
 * The parts of an X.509 certificate the SSL check uses.
 */
final class Certificate {

	/**
	 * Not-after time.
	 *
	 * @var int
	 */
	public $valid_to;

	/**
	 * Not-before time.
	 *
	 * @var int
	 */
	public $valid_from;

	/**
	 * Subject common name.
	 *
	 * @var string
	 */
	public $subject;

	/**
	 * Issuer organisation or common name.
	 *
	 * @var string
	 */
	public $issuer;

	/**
	 * DNS names from subjectAltName.
	 *
	 * @var string[]
	 */
	public $names;

	/**
	 * Constructor.
	 *
	 * @param int      $valid_from Not-before.
	 * @param int      $valid_to Not-after.
	 * @param string   $subject Subject CN.
	 * @param string   $issuer Issuer.
	 * @param string[] $names DNS names.
	 */
	public function __construct( int $valid_from, int $valid_to, string $subject, string $issuer, array $names ) {
		$this->valid_from = $valid_from;
		$this->valid_to   = $valid_to;
		$this->subject    = $subject;
		$this->issuer     = $issuer;
		$this->names      = array_values( array_map( 'strval', $names ) );
	}

	/**
	 * Build from openssl_x509_parse() output.
	 *
	 * @param array<string,mixed> $parsed Parsed certificate.
	 * @throws InvalidArgumentException When the validity period is missing.
	 */
	public static function from_openssl( array $parsed ): self {
		if ( ! isset( $parsed['validTo_time_t'], $parsed['validFrom_time_t'] ) ) {
			throw new InvalidArgumentException( 'The certificate has no validity period.' );
		}
		$names = array();
		$san   = (string) ( $parsed['extensions']['subjectAltName'] ?? '' );
		foreach ( explode( ',', $san ) as $entry ) {
			$entry = trim( $entry );
			if ( 0 === stripos( $entry, 'DNS:' ) ) {
				$names[] = strtolower( trim( substr( $entry, 4 ) ) );
			}
		}
		$subject = $parsed['subject']['CN'] ?? '';
		$issuer  = $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? '';

		return new self(
			(int) $parsed['validFrom_time_t'],
			(int) $parsed['validTo_time_t'],
			is_array( $subject ) ? (string) reset( $subject ) : (string) $subject,
			is_array( $issuer ) ? (string) reset( $issuer ) : (string) $issuer,
			$names
		);
	}

	/**
	 * Seconds until expiry (negative when expired).
	 *
	 * @param int $now Current time.
	 */
	public function seconds_left( int $now ): int {
		return $this->valid_to - $now;
	}

	/**
	 * Array form, for the cache.
	 *
	 * @return array{valid_from:int,valid_to:int,subject:string,issuer:string,names:string[]}
	 */
	public function to_array(): array {
		return array(
			'valid_from' => $this->valid_from,
			'valid_to'   => $this->valid_to,
			'subject'    => $this->subject,
			'issuer'     => $this->issuer,
			'names'      => $this->names,
		);
	}

	/**
	 * Rebuild from to_array() output.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['valid_from'] ?? 0 ),
			(int) ( $data['valid_to'] ?? 0 ),
			(string) ( $data['subject'] ?? '' ),
			(string) ( $data['issuer'] ?? '' ),
			is_array( $data['names'] ?? null ) ? $data['names'] : array()
		);
	}
}
