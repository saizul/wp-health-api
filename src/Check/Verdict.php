<?php
/**
 * Collects findings while a check evaluates its data.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

/**
 * Keeps the worst status seen and the reasons for it.
 */
final class Verdict {

	/**
	 * Findings: [status, reason].
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private $findings = array();

	/**
	 * Breached thresholds, for the details block.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $breaches = array();

	/**
	 * Record a finding. OK findings are ignored.
	 *
	 * @param string $status Status.
	 * @param string $reason Reason.
	 */
	public function add( string $status, string $reason ): void {
		Status::severity( $status );
		if ( Status::OK !== $status ) {
			$this->findings[] = array( $status, $reason );
		}
	}

	/**
	 * Evaluate a value against a threshold and record a finding when it is
	 * breached. The reason is built from a sprintf() format that receives
	 * the formatted value and the limit.
	 *
	 * @param string    $key Threshold key (for the details block).
	 * @param Threshold $threshold Threshold.
	 * @param float     $value Value.
	 * @param string    $format Reason format with two %s: value and limit.
	 * @param callable  $formatter Formats value and limit for the reason.
	 * @return string The status of this value.
	 */
	public function threshold( string $key, Threshold $threshold, ?float $value, string $format, ?callable $formatter = null ): string {
		$status = $threshold->evaluate( $value );
		if ( Status::OK === $status || null === $value ) {
			return $status;
		}
		$formatter = $formatter ?? static function ( float $number ): string {
			return self::number( $number );
		};
		$limit     = (float) $threshold->limit_for( $status );
		$this->add( $status, sprintf( $format, $formatter( $value ), $formatter( $limit ) ) );
		$this->breaches[ $key ] = array(
			'status' => $status,
			'value'  => $value,
			'limit'  => $limit,
		);

		return $status;
	}

	/**
	 * Worst status recorded.
	 */
	public function status(): string {
		return Status::worst( array_column( $this->findings, 0 ) );
	}

	/**
	 * Message: the reasons for the worst status, or the fallback.
	 *
	 * @param string $ok_message Message when everything is fine.
	 */
	public function message( string $ok_message ): string {
		$status = $this->status();
		if ( Status::OK === $status ) {
			return $ok_message;
		}
		$reasons = array();
		foreach ( $this->findings as $finding ) {
			if ( $finding[0] === $status ) {
				$reasons[] = $finding[1];
			}
		}
		foreach ( $this->findings as $finding ) {
			if ( $finding[0] !== $status ) {
				$reasons[] = $finding[1];
			}
		}

		return implode( '; ', $reasons );
	}

	/**
	 * Breached thresholds.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function breaches(): array {
		return $this->breaches;
	}

	/**
	 * Format a number without trailing zeros.
	 *
	 * @param float $number Number.
	 */
	public static function number( float $number ): string {
		if ( floor( $number ) === $number && abs( $number ) < 1e15 ) {
			return (string) (int) $number;
		}

		return rtrim( rtrim( sprintf( '%.2f', $number ), '0' ), '.' );
	}
}
