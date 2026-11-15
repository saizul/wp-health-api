<?php
/**
 * Warn/critical threshold.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

use InvalidArgumentException;

/**
 * A pair of limits applied to one measured value.
 *
 * Direction "above" means larger values are worse (latency, overdue
 * events); "below" means smaller values are worse (free disk space, days
 * until a certificate expires). Either limit may be null to disable that
 * level. Values on the limit count as breaching it.
 */
final class Threshold {

	const ABOVE = 'above';
	const BELOW = 'below';

	/**
	 * Warning limit.
	 *
	 * @var float|null
	 */
	private $warn;

	/**
	 * Critical limit.
	 *
	 * @var float|null
	 */
	private $critical;

	/**
	 * Direction.
	 *
	 * @var string
	 */
	private $direction;

	/**
	 * Constructor.
	 *
	 * @param float|null $warn Warning limit or null.
	 * @param float|null $critical Critical limit or null.
	 * @param string     $direction self::ABOVE or self::BELOW.
	 * @throws InvalidArgumentException On an unknown direction, non-finite limits or limits in the wrong order.
	 */
	public function __construct( ?float $warn, ?float $critical, string $direction = self::ABOVE ) {
		if ( self::ABOVE !== $direction && self::BELOW !== $direction ) {
			throw new InvalidArgumentException( sprintf( 'Unknown threshold direction "%s".', $direction ) );
		}
		foreach ( array( $warn, $critical ) as $limit ) {
			if ( null !== $limit && ! is_finite( $limit ) ) {
				throw new InvalidArgumentException( 'Threshold limits must be finite numbers.' );
			}
		}
		if ( null !== $warn && null !== $critical ) {
			$wrong_order = self::ABOVE === $direction ? $warn > $critical : $warn < $critical;
			if ( $wrong_order ) {
				throw new InvalidArgumentException(
					self::ABOVE === $direction
						? 'The warning limit must not be greater than the critical limit.'
						: 'The warning limit must not be smaller than the critical limit.'
				);
			}
		}
		$this->warn      = $warn;
		$this->critical  = $critical;
		$this->direction = $direction;
	}

	/**
	 * Threshold that never fires.
	 *
	 * @param string $direction Direction.
	 */
	public static function disabled( string $direction = self::ABOVE ): self {
		return new self( null, null, $direction );
	}

	/**
	 * Build from an array with "warn", "critical" and optional "direction".
	 *
	 * Empty strings mean "disabled". The default direction is used when
	 * the array does not name one.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param string              $direction Default direction.
	 */
	public static function from_array( array $data, string $direction = self::ABOVE ): self {
		$read = static function ( $value ): ?float {
			if ( null === $value || '' === $value || false === $value ) {
				return null;
			}
			if ( ! is_numeric( $value ) ) {
				throw new InvalidArgumentException( 'Threshold limits must be numeric.' );
			}

			return (float) $value;
		};

		return new self(
			$read( $data['warn'] ?? null ),
			$read( $data['critical'] ?? null ),
			isset( $data['direction'] ) ? (string) $data['direction'] : $direction
		);
	}

	/**
	 * Status for a measured value. Null (not measured) is always OK.
	 *
	 * @param float|null $value Value.
	 */
	public function evaluate( ?float $value ): string {
		if ( null === $value || is_nan( $value ) ) {
			return Status::OK;
		}
		if ( null !== $this->critical && $this->breaches( $value, $this->critical ) ) {
			return Status::CRITICAL;
		}
		if ( null !== $this->warn && $this->breaches( $value, $this->warn ) ) {
			return Status::WARN;
		}

		return Status::OK;
	}

	/**
	 * Whether the value breaches a limit.
	 *
	 * @param float $value Value.
	 * @param float $limit Limit.
	 */
	private function breaches( float $value, float $limit ): bool {
		return self::ABOVE === $this->direction ? $value >= $limit : $value <= $limit;
	}

	/**
	 * Limit for a status, or null.
	 *
	 * @param string $status Status::WARN or Status::CRITICAL.
	 */
	public function limit_for( string $status ): ?float {
		if ( Status::CRITICAL === $status ) {
			return $this->critical;
		}

		return Status::WARN === $status ? $this->warn : null;
	}

	/**
	 * Warning limit.
	 */
	public function warn(): ?float {
		return $this->warn;
	}

	/**
	 * Critical limit.
	 */
	public function critical(): ?float {
		return $this->critical;
	}

	/**
	 * Direction.
	 */
	public function direction(): string {
		return $this->direction;
	}

	/**
	 * Whether both levels are disabled.
	 */
	public function is_disabled(): bool {
		return null === $this->warn && null === $this->critical;
	}

	/**
	 * Array form, as stored in the settings.
	 *
	 * @return array{warn:float|null,critical:float|null,direction:string}
	 */
	public function to_array(): array {
		return array(
			'warn'      => $this->warn,
			'critical'  => $this->critical,
			'direction' => $this->direction,
		);
	}
}
