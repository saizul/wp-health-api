<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\PhpCheck;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Tests\Fakes\FakePhp;
use Saizul\HealthApi\Threshold;

final class PhpCheckTest extends TestCase {

	/**
	 * @param array<string,mixed> $options Options.
	 */
	private function check( FakePhp $probe, array $options = array() ): PhpCheck {
		return new PhpCheck(
			$probe,
			new Threshold( 128, 64, Threshold::BELOW ),
			new Threshold( 90, null, Threshold::BELOW ),
			new Threshold( 5, null, Threshold::BELOW ),
			$options + array(
				'warn_below'      => '8.1',
				'critical_below'  => '7.4',
				'require_opcache' => true,
			)
		);
	}

	private function sample( PhpCheck $check, string $name ): ?float {
		foreach ( $check->run()->samples as $sample ) {
			if ( $sample->name === $name ) {
				return $sample->value;
			}
		}
		return null;
	}

	public function test_healthy_runtime(): void {
		$result = $this->check( new FakePhp() )->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( 'PHP 8.3.12, memory_limit 256M, OPcache on.', $result->message );
		$this->assertSame( 268435456, $result->details['memory_limit_bytes'] );
		$this->assertSame( 99.0, $result->details['opcache']['hit_percent'] );
		$this->assertSame( array( 'version' => '8.3.12' ), $result->samples[0]->labels );
		$this->assertSame( 0.99, $this->sample( $this->check( new FakePhp() ), 'opcache_hit_ratio' ) );
	}

	public function test_old_php_versions(): void {
		$probe          = new FakePhp();
		$probe->version = '8.0.30';
		$result         = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'PHP 8.0.30 is older than 8.1', $result->message );

		$probe->version = '7.3.33';
		$this->assertSame( 'PHP 7.3.33 is older than 7.4', $this->check( $probe )->run()->message );
		$this->assertSame( Status::CRITICAL, $this->check( $probe )->run()->status );

		$this->assertSame( Status::OK, $this->check( $probe, array( 'warn_below' => '', 'critical_below' => '' ) )->run()->status );
	}

	public function test_memory_limit(): void {
		$probe               = new FakePhp();
		$probe->memory_limit = '96M';
		$this->assertSame( Status::WARN, $this->check( $probe )->run()->status );
		$probe->memory_limit = '64M';
		$this->assertSame( 'memory_limit is 64 MiB (limit 64 MiB)', $this->check( $probe )->run()->message );

		$probe->memory_limit = '-1';
		$result              = $this->check( $probe )->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( -1, $result->details['memory_limit_bytes'] );
		$this->assertSame( -1.0, $this->sample( $this->check( $probe ), 'php_memory_limit_bytes' ) );
	}

	public function test_opcache_disabled(): void {
		$probe          = new FakePhp();
		$probe->opcache = null;
		$result         = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'OPcache is not enabled', $result->message );
		$this->assertSame( 0.0, $this->sample( $this->check( $probe ), 'opcache_enabled' ) );
		$this->assertNull( $this->sample( $this->check( $probe ), 'opcache_hit_ratio' ) );

		$this->assertSame( Status::OK, $this->check( $probe, array( 'require_opcache' => false ) )->run()->status );

		$probe->opcache = array_merge( ( new FakePhp() )->opcache, array( 'enabled' => false ) );
		$this->assertSame( Status::WARN, $this->check( $probe )->run()->status );
	}

	public function test_low_hit_rate_is_ignored_right_after_a_restart(): void {
		$probe                    = new FakePhp();
		$probe->opcache['hits']   = 100;
		$probe->opcache['misses'] = 400;
		$this->assertSame( Status::OK, $this->check( $probe )->run()->status );

		$probe->opcache['hits']   = 1000;
		$probe->opcache['misses'] = 4000;
		$result                   = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'OPcache hit rate is 20% (limit 90%)', $result->message );
	}

	public function test_full_opcache(): void {
		$probe                         = new FakePhp();
		$probe->opcache['free_memory'] = 1000000;
		$probe->opcache['cache_full']  = true;
		$result                        = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertStringContainsString( 'OPcache has 1.64% memory free (limit 5%)', $result->message );
		$this->assertStringContainsString( 'OPcache is full', $result->message );
	}
}
