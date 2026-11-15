<?php
/**
 * TLS certificate probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

use Saizul\HealthApi\Ssl\Certificate;

/**
 * Fetches the certificate served for a host.
 */
interface SslProbe {

	/**
	 * Certificate presented by host:port.
	 *
	 * @param string $host Host name.
	 * @param int    $port Port.
	 * @return Certificate
	 * @throws \RuntimeException When no certificate can be read.
	 */
	public function certificate( string $host, int $port ): Certificate;
}
