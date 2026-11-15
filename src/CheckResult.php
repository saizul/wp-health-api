<?php
/**
 * Result of one check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

/**
 * Status, message, details and metric samples of a check run.
 */
final class CheckResult {

	/**
	 * Detail keys holding keyed maps.
	 *
	 * @var string[]
	 */
	const MAP_KEYS = array( 'breaches', 'overdue_hooks', 'by_status', 'plugins', 'themes' );

	/**
	 * Check id, e.g. "database".
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Status.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Short human readable summary.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Details for the JSON report.
	 *
	 * @var array<string,mixed>
	 */
	public $details;

	/**
	 * Metric samples.
	 *
	 * @var Sample[]
	 */
	public $samples;

	/**
	 * Run time in seconds.
	 *
	 * @var float
	 */
	public $duration = 0.0;

	/**
	 * Whether the check did not apply (feature missing, disabled) and must
	 * not influence the overall status.
	 *
	 * @var bool
	 */
	public $skipped = false;

	/**
	 * Constructor.
	 *
	 * @param string              $id Check id.
	 * @param string              $status Status.
	 * @param string              $message Message.
	 * @param array<string,mixed> $details Details.
	 * @param Sample[]            $samples Samples.
	 */
	public function __construct( string $id, string $status, string $message, array $details = array(), array $samples = array() ) {
		Status::severity( $status );
		$this->id      = $id;
		$this->status  = $status;
		$this->message = $message;
		$this->details = $details;
		$this->samples = array_values( $samples );
	}

	/**
	 * Result for a check that does not apply.
	 *
	 * @param string $id Check id.
	 * @param string $message Reason.
	 */
	public static function skipped( string $id, string $message ): self {
		$result          = new self( $id, Status::OK, $message );
		$result->skipped = true;

		return $result;
	}

	/**
	 * Array form for the JSON report.
	 *
	 * Without samples (the JSON endpoint), empty keyed maps such as
	 * "breaches" become empty objects so their JSON type never changes.
	 * With samples (the cache), details are kept as they are.
	 *
	 * @param bool $with_samples Include metric samples.
	 * @return array<string,mixed>
	 */
	public function to_array( bool $with_samples = false ): array {
		$details = $this->details;
		if ( ! $with_samples ) {
			// Keyed maps stay JSON objects when they are empty.
			foreach ( self::MAP_KEYS as $key ) {
				if ( isset( $details[ $key ] ) && array() === $details[ $key ] ) {
					$details[ $key ] = new \stdClass();
				}
			}
		}
		$data = array(
			'status'      => $this->status,
			'message'     => $this->message,
			'skipped'     => $this->skipped,
			'duration_ms' => round( $this->duration * 1000, 2 ),
			'details'     => $details,
		);
		if ( $with_samples ) {
			$data['samples'] = array_map(
				static function ( Sample $sample ): array {
					return $sample->to_array();
				},
				$this->samples
			);
		}

		return $data;
	}

	/**
	 * Rebuild from to_array( true ) output.
	 *
	 * @param string              $id Check id.
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( string $id, array $data ): self {
		$status           = (string) ( $data['status'] ?? Status::CRITICAL );
		$result           = new self(
			$id,
			Status::is_valid( $status ) ? $status : Status::CRITICAL,
			(string) ( $data['message'] ?? '' ),
			is_array( $data['details'] ?? null ) ? $data['details'] : array(),
			array_map(
				static function ( $sample ): Sample {
					return Sample::from_array( is_array( $sample ) ? $sample : array() );
				},
				is_array( $data['samples'] ?? null ) ? $data['samples'] : array()
			)
		);
		$result->skipped  = ! empty( $data['skipped'] );
		$result->duration = (float) ( $data['duration_ms'] ?? 0 ) / 1000;

		return $result;
	}
}
