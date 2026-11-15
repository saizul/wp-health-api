<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Saizul\HealthApi\CachedRunner;
use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Report;
use Saizul\HealthApi\Runner;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\ArrayStore;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes\CallbackCheck;

final class RunnerTest extends TestCase {

	/** @var FrozenClock */
	private $clock;

	protected function setUp(): void {
		$this->clock = new FrozenClock( 1800000000 );
	}

	private function fixed( string $status, float $seconds = 0.0 ): CallbackCheck {
		$clock = $this->clock;

		return new CallbackCheck(
			'check_' . $status,
			static function ( string $id ) use ( $status, $seconds, $clock ): CheckResult {
				$clock->advance( $seconds );
				return new CheckResult( $id, $status, ucfirst( $status ), array( 'k' => 'v' ), array( Sample::gauge( 'x', 1, 'X.', array( 'id' => $id ) ) ) );
			}
		);
	}

	public function test_runs_and_times_checks(): void {
		$report = ( new Runner( $this->clock ) )->run( array( $this->fixed( Status::OK, 0.25 ), $this->fixed( Status::WARN, 0.5 ) ) );
		$this->assertSame( Status::WARN, $report->status() );
		$this->assertEqualsWithDelta( 0.25, $report->result( 'check_ok' )->duration, 1e-9 );
		$this->assertEqualsWithDelta( 0.5, $report->result( 'check_warn' )->duration, 1e-9 );
		$this->assertEqualsWithDelta( 0.75, $report->duration, 1e-9 );
		$this->assertSame( array( 'ok' => 1, 'warn' => 1, 'critical' => 0, 'skipped' => 0 ), $report->counts() );
	}

	public function test_exceptions_become_critical_results(): void {
		$broken = new CallbackCheck(
			'broken',
			static function (): CheckResult {
				throw new RuntimeException( 'boom' );
			}
		);
		$report = ( new Runner( $this->clock ) )->run( array( $this->fixed( Status::OK ), $broken ) );
		$this->assertSame( Status::CRITICAL, $report->status() );
		$this->assertSame( 'The check failed: boom', $report->result( 'broken' )->message );
		$this->assertSame( RuntimeException::class, $report->result( 'broken' )->details['exception'] );
	}

	public function test_type_errors_are_caught_too(): void {
		$broken = new CallbackCheck(
			'typed',
			static function (): CheckResult {
				return strlen( array() ); // @phpstan-ignore-line
			}
		);
		$this->assertSame( Status::CRITICAL, ( new Runner( $this->clock ) )->run_one( $broken )->status );
	}

	public function test_skipped_checks_do_not_affect_the_status(): void {
		$skipped = new CallbackCheck(
			'woo',
			static function ( string $id ): CheckResult {
				return CheckResult::skipped( $id, 'Not active.' );
			}
		);
		$report = ( new Runner( $this->clock ) )->run( array( $skipped ) );
		$this->assertSame( Status::OK, $report->status() );
		$this->assertSame( 1, $report->counts()['skipped'] );
	}

	public function test_result_id_is_forced_to_the_check_id(): void {
		$check  = new CallbackCheck(
			'real_id',
			static function (): CheckResult {
				return new CheckResult( 'other', Status::OK, 'x' );
			}
		);
		$report = ( new Runner( $this->clock ) )->run( array( $check ) );
		$this->assertNotNull( $report->result( 'real_id' ) );
	}

	public function test_report_round_trips_through_arrays(): void {
		$report = ( new Runner( $this->clock ) )->run( array( $this->fixed( Status::OK, 0.1 ), $this->fixed( Status::CRITICAL ) ) );
		$copy   = Report::from_array( $report->to_array( true ) );

		$this->assertSame( $report->to_array(), $copy->to_array() );
		$this->assertSame( 'x', $copy->result( 'check_ok' )->samples[0]->name );
		$this->assertSame( array( 'id' => 'check_ok' ), $copy->result( 'check_ok' )->samples[0]->labels );
		$this->assertArrayNotHasKey( 'samples', $report->to_array()['checks']['check_ok'] );
		$this->assertSame( '2027-01-15T08:00:00+00:00', $report->to_array()['generated_at'] );
	}

	public function test_empty_maps_are_json_objects(): void {
		$result = new CheckResult( 'x', Status::OK, 'fine', array( 'breaches' => array(), 'list' => array() ) );
		$this->assertSame( '{"breaches":{},"list":[]}', json_encode( $result->to_array()['details'] ) );
		$this->assertSame( array(), $result->to_array( true )['details']['breaches'] );
	}

	public function test_from_array_is_defensive(): void {
		$report = Report::from_array( array( 'checks' => array( 'a' => array( 'status' => 'exploded' ), 'b' => 'junk' ) ) );
		$this->assertSame( Status::CRITICAL, $report->result( 'a' )->status );
		$this->assertSame( Status::CRITICAL, $report->result( 'b' )->status );
	}

	public function test_cache_serves_recent_reports(): void {
		$store  = new ArrayStore( $this->clock );
		$check  = $this->fixed( Status::OK );
		$cached = new CachedRunner( new Runner( $this->clock ), $store, 10 );

		$first = $cached->report( array( $check ) );
		$this->assertFalse( $first->cached );
		$second = $cached->report( array( $check ) );
		$this->assertTrue( $second->cached );
		$this->assertSame( 1, $check->runs );
		$this->assertSame( $first->generated_at, $second->generated_at );

		$this->clock->advance( 10 );
		$cached->report( array( $check ) );
		$this->assertSame( 2, $check->runs );

		$cached->report( array( $check ), '', true );
		$this->assertSame( 3, $check->runs, 'Fresh bypasses the cache' );
	}

	public function test_cache_variants_are_separate(): void {
		$store  = new ArrayStore( $this->clock );
		$check  = $this->fixed( Status::OK );
		$cached = new CachedRunner( new Runner( $this->clock ), $store, 10 );
		$cached->report( array( $check ), 'a' );
		$cached->report( array( $check ), 'b' );
		$this->assertSame( 2, $check->runs );
		$cached->flush( 'a' );
		$cached->report( array( $check ), 'a' );
		$this->assertSame( 3, $check->runs );
	}

	public function test_zero_ttl_disables_the_cache(): void {
		$store  = new ArrayStore( $this->clock );
		$check  = $this->fixed( Status::OK );
		$cached = new CachedRunner( new Runner( $this->clock ), $store, 0 );
		$cached->report( array( $check ) );
		$cached->report( array( $check ) );
		$this->assertSame( 2, $check->runs );
		$this->assertSame( 0, $store->count() );
	}

	public function test_stale_report_is_served_while_another_request_runs_the_checks(): void {
		$store  = new ArrayStore( $this->clock );
		$check  = $this->fixed( Status::OK );
		$cached = new CachedRunner( new Runner( $this->clock ), $store, 10 );
		$cached->report( array( $check ) );
		$this->clock->advance( 15 );
		$store->set( CachedRunner::LOCK, 1, CachedRunner::LOCK_TTL );

		$report = $cached->report( array( $check ) );
		$this->assertTrue( $report->cached );
		$this->assertSame( 1, $check->runs );
	}
}
