<?php
/**
 * Disk probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\DiskProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Measures the file system of the uploads directory.
 *
 * The disk_free_space() function is disabled on many shared hosts; the check then
 * reports that the value cannot be read instead of failing.
 */
final class WpDiskProbe implements DiskProbe {

	/**
	 * Measured path.
	 *
	 * @var string|null
	 */
	private $path = null;

	/**
	 * Uploads base directory, or wp-content when it does not exist yet.
	 */
	public function path(): string {
		if ( null === $this->path ) {
			$uploads    = wp_upload_dir( null, false );
			$path       = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
			$this->path = '' !== $path && is_dir( $path ) ? $path : WP_CONTENT_DIR;
		}

		return $this->path;
	}

	/**
	 * Free bytes.
	 */
	public function free_bytes(): ?float {
		return $this->measure( 'disk_free_space' );
	}

	/**
	 * Total bytes.
	 */
	public function total_bytes(): ?float {
		return $this->measure( 'disk_total_space' );
	}

	/**
	 * Whether the uploads directory is writable.
	 */
	public function is_writable(): bool {
		return wp_is_writable( $this->path() );
	}

	/**
	 * Call a disk function if the host allows it.
	 *
	 * @param string $function_name disk_free_space or disk_total_space.
	 */
	private function measure( string $function_name ): ?float {
		if ( ! function_exists( $function_name ) ) {
			return null;
		}
		$value = @call_user_func( $function_name, $this->path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir restrictions raise warnings; a null result is handled.

		return is_float( $value ) || is_int( $value ) ? (float) $value : null;
	}
}
