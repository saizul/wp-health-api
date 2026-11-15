<?php
/**
 * One metric sample produced by a check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

/**
 * A named numeric value with labels, plus the metadata needed to expose
 * it in the Prometheus format (type and help text).
 */
final class Sample {

	const GAUGE   = 'gauge';
	const COUNTER = 'counter';

	/**
	 * Metric name without the global prefix.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Value.
	 *
	 * @var float
	 */
	public $value;

	/**
	 * Labels.
	 *
	 * @var array<string,string>
	 */
	public $labels;

	/**
	 * Help text.
	 *
	 * @var string
	 */
	public $help;

	/**
	 * Metric type.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Constructor.
	 *
	 * @param string               $name Metric name without prefix.
	 * @param float                $value Value.
	 * @param string               $help Help text.
	 * @param array<string,string> $labels Labels.
	 * @param string               $type Metric type.
	 */
	public function __construct( string $name, float $value, string $help, array $labels = array(), string $type = self::GAUGE ) {
		$this->name   = $name;
		$this->value  = $value;
		$this->help   = $help;
		$this->labels = array_map( 'strval', $labels );
		$this->type   = $type;
	}

	/**
	 * Gauge sample.
	 *
	 * @param string               $name Name.
	 * @param float|int|bool       $value Value; booleans become 1/0.
	 * @param string               $help Help.
	 * @param array<string,string> $labels Labels.
	 */
	public static function gauge( string $name, $value, string $help, array $labels = array() ): self {
		return new self( $name, is_bool( $value ) ? ( $value ? 1.0 : 0.0 ) : (float) $value, $help, $labels );
	}

	/**
	 * Array form (for the JSON report and the cache).
	 *
	 * @return array{name:string,value:float,labels:array<string,string>,help:string,type:string}
	 */
	public function to_array(): array {
		return array(
			'name'   => $this->name,
			'value'  => $this->value,
			'labels' => $this->labels,
			'help'   => $this->help,
			'type'   => $this->type,
		);
	}

	/**
	 * Rebuild from to_array() output.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		$value = $data['value'] ?? 0.0;

		return new self(
			(string) ( $data['name'] ?? '' ),
			is_numeric( $value ) ? (float) $value : NAN,
			(string) ( $data['help'] ?? '' ),
			is_array( $data['labels'] ?? null ) ? $data['labels'] : array(),
			(string) ( $data['type'] ?? self::GAUGE )
		);
	}
}
