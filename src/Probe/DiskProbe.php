<?php
/**
 * Disk probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Reads free and total space of the uploads directory's file system.
 */
interface DiskProbe {

	/**
	 * Path that was measured.
	 */
	public function path(): string;

	/**
	 * Free bytes, or null when the host does not allow reading it.
	 */
	public function free_bytes(): ?float;

	/**
	 * Total bytes, or null when the host does not allow reading it.
	 */
	public function total_bytes(): ?float;

	/**
	 * Whether the path is writable by PHP.
	 */
	public function is_writable(): bool;
}
