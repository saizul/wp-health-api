<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\DatabaseCheck;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Tests\Fakes\FakeDatabase;
use Saizul\HealthApi\Threshold;

final class DatabaseCheckTest extends TestCase {

	public function test_fast_database_is_ok(): void {
		$probe  = new FakeDatabase();
		$result = ( new DatabaseCheck( $probe, new Threshold( 250, 1000 ) ) )->run();

		$this->assertSame( 'database', $result->id );
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( 'Database is reachable (1.5 ms).', $result->message );
		$this->assertSame( 1.5, $result->details['latency_ms'] );
		$this->assertSame( '10.11.6-MariaDB', $result->details['server'] );
		$this->assertSame( array( 'database_up', 'database_latency_seconds' ), array_column( array_map( 'get_object_vars', $result->samples ), 'name' ) );
		$this->assertSame( 1.0, $result->samples[0]->value );
		$this->assertSame( 0.0015, $result->samples[1]->value );
	}

	public function test_slow_database_warns_then_goes_critical(): void {
		$probe                = new FakeDatabase();
		$probe->ping['latency'] = 0.3;
		$check                = new DatabaseCheck( $probe, new Threshold( 250, 1000 ) );
		$result               = $check->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'Query took 300 ms (limit 250 ms)', $result->message );
		$this->assertSame( 'warn', $result->details['breaches']['latency_ms']['status'] );

		$probe->ping['latency'] = 1.2;
		$this->assertSame( Status::CRITICAL, $check->run()->status );
	}

	public function test_failed_query_is_critical(): void {
		$probe       = new FakeDatabase();
		$probe->ping = array( 'connected' => false, 'latency' => null, 'error' => 'Lost connection', 'server' => '' );
		$result      = ( new DatabaseCheck( $probe, new Threshold( 250, 1000 ) ) )->run();

		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( 'Database query failed: Lost connection', $result->message );
		$this->assertCount( 1, $result->samples );
		$this->assertSame( 0.0, $result->samples[0]->value );
	}

	public function test_failed_query_without_error_text(): void {
		$probe       = new FakeDatabase();
		$probe->ping = array( 'connected' => false );
		$result      = ( new DatabaseCheck( $probe, Threshold::disabled() ) )->run();
		$this->assertSame( 'Database query failed.', $result->message );
	}
}
