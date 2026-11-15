<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\ObjectCacheCheck;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Tests\Fakes\FakeObjectCache;
use Saizul\HealthApi\Threshold;

final class ObjectCacheCheckTest extends TestCase {

	public function test_working_persistent_cache(): void {
		$result = ( new ObjectCacheCheck( new FakeObjectCache(), new Threshold( 50, 250 ) ) )->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( 'Persistent object cache (redis) is working.', $result->message );
		$this->assertSame( array( 'backend' => 'redis' ), $result->samples[0]->labels );
		$this->assertSame( 1.0, $result->samples[1]->value );
		$this->assertSame( 0.4, $result->details['latency_ms'] );
	}

	public function test_failed_round_trip_on_persistent_cache_is_critical(): void {
		$probe       = new FakeObjectCache();
		$probe->trip = array( 'ok' => false, 'latency' => 0.001, 'error' => 'READONLY' );
		$result      = ( new ObjectCacheCheck( $probe, new Threshold( 50, 250 ) ) )->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( 'Object cache round trip failed: READONLY', $result->message );
		$this->assertSame( 0.0, $result->samples[1]->value );
	}

	public function test_slow_persistent_cache_warns(): void {
		$probe       = new FakeObjectCache();
		$probe->trip = array( 'ok' => true, 'latency' => 0.08, 'error' => '' );
		$result      = ( new ObjectCacheCheck( $probe, new Threshold( 50, 250 ) ) )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'Object cache round trip took 80 ms (limit 50 ms)', $result->message );
	}

	public function test_no_persistent_cache_is_ok_unless_required(): void {
		$probe             = new FakeObjectCache();
		$probe->backend    = 'none';
		$probe->persistent = false;
		$probe->trip       = array( 'ok' => true, 'latency' => 9.0, 'error' => '' );

		$result = ( new ObjectCacheCheck( $probe, new Threshold( 50, 250 ) ) )->run();
		$this->assertSame( Status::OK, $result->status, 'Latency of the in-request cache is not judged' );
		$this->assertSame( 'No persistent object cache; using the in-request cache.', $result->message );

		$required = ( new ObjectCacheCheck( $probe, new Threshold( 50, 250 ), true ) )->run();
		$this->assertSame( Status::WARN, $required->status );
		$this->assertSame( 'No persistent object cache is installed', $required->message );
	}

	public function test_failed_in_request_cache_is_only_a_warning(): void {
		$probe             = new FakeObjectCache();
		$probe->persistent = false;
		$probe->trip       = array( 'ok' => false, 'latency' => null, 'error' => '' );
		$result            = ( new ObjectCacheCheck( $probe, Threshold::disabled() ) )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 2, $result->samples, 'No latency sample without a measurement' );
	}
}
