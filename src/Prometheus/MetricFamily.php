<?php
/**
 * Prometheus metric family.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Prometheus;

use InvalidArgumentException;

/**
 * One metric name with its type, help text and samples.
 *
 * Names follow the Prometheus data model: metric names match
 * `[a-zA-Z_:][a-zA-Z0-9_:]*`, label names match `[a-zA-Z_][a-zA-Z0-9_]*`
 * and label names starting with `__` are reserved. Every sample needs a
 * unique label set.
 */
final class MetricFamily {

	const METRIC_NAME = '/^[a-zA-Z_:][a-zA-Z0-9_:]*$/';
	const LABEL_NAME  = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';
	const TYPES       = array( 'counter', 'gauge', 'histogram', 'summary', 'untyped' );

	/**
	 * Metric name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Help text.
	 *
	 * @var string
	 */
	private $help;

	/**
	 * Samples: [labels, value].
	 *
	 * @var array<string,array{0:array<string,string>,1:float}>
	 */
	private $samples = array();

	/**
	 * Constructor.
	 *
	 * @param string $name Metric name.
	 * @param string $type Metric type.
	 * @param string $help Help text.
	 * @throws InvalidArgumentException On an invalid name or type.
	 */
	public function __construct( string $name, string $type, string $help = '' ) {
		if ( ! self::is_valid_name( $name ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid metric name "%s".', $name ) );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid metric type "%s".', $type ) );
		}
		$this->name = $name;
		$this->type = $type;
		$this->help = $help;
	}

	/**
	 * Whether a string is a valid metric name.
	 *
	 * @param string $name Name.
	 */
	public static function is_valid_name( string $name ): bool {
		return 1 === preg_match( self::METRIC_NAME, $name );
	}

	/**
	 * Whether a string is a valid, non-reserved label name.
	 *
	 * @param string $name Name.
	 */
	public static function is_valid_label( string $name ): bool {
		return 1 === preg_match( self::LABEL_NAME, $name ) && 0 !== strpos( $name, '__' );
	}

	/**
	 * Add a sample.
	 *
	 * Labels with empty values are dropped: Prometheus treats them as
	 * absent. Labels are sorted by name so the output is reproducible.
	 *
	 * @param float                $value Value.
	 * @param array<string,string> $labels Labels.
	 * @throws InvalidArgumentException On invalid label names or a duplicate label set.
	 */
	public function add( float $value, array $labels = array() ): self {
		$clean = array();
		foreach ( $labels as $label => $label_value ) {
			$label = (string) $label;
			if ( ! self::is_valid_label( $label ) ) {
				throw new InvalidArgumentException( sprintf( 'Invalid label name "%s" on metric "%s".', $label, $this->name ) );
			}
			$label_value = (string) $label_value;
			if ( '' !== $label_value ) {
				$clean[ $label ] = $label_value;
			}
		}
		ksort( $clean, SORT_STRING );
		$key = serialize( $clean ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Internal identity key only.
		if ( isset( $this->samples[ $key ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Duplicate label set on metric "%s".', $this->name ) );
		}
		$this->samples[ $key ] = array( $clean, $value );

		return $this;
	}

	/**
	 * Name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Help text.
	 */
	public function help(): string {
		return $this->help;
	}

	/**
	 * Samples in insertion order.
	 *
	 * @return array<int,array{0:array<string,string>,1:float}>
	 */
	public function samples(): array {
		return array_values( $this->samples );
	}
}
