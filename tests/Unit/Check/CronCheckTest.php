<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\CronCheck;
use Saizul\HealthApi\Cron\CronAnalyzer;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes\FakeCron;
use Saizul\HealthApi\Threshold;

final class CronCheckTest extends TestCase {

	const NOW = 1800000000;

	/**
	 * Cron array with events at the given offsets from NOW.
	 *
	 * @param array<int,array<string,int>> $events offset => [hook => instances].
	 * @return array<int|string,mixed>
	 */
	private function cron( array $events ): array {
		$cron = array();
		foreach ( $events as $offset => $hooks ) {
			foreach ( $hooks as $hook => $count ) {
				for ( $i = 0; $i < $count; $i++ ) {
					$cron[ self::NOW + $offset ][ $hook ][ md5( (string) $i ) ] = array(
						'schedule' => 'hourly',
						'args'     => array( $i ),
					);
				}
			}
		}
		$cron['version'] = 2;

		return $cron;
	}

	public function test_analyzer_counts_overdue_events_after_grace(): void {
		$cron = $this->cron(
			array(
				-7200 => array( 'wp_version_check' => 1, 'my_job' => 2 ),
				-300  => array( 'within_grace' => 1 ),
				-601  => array( 'just_late' => 1 ),
				600   => array( 'future' => 3 ),
			)
		);
		$analysis = CronAnalyzer::analyse( $cron, self::NOW, 600 );

		$this->assertSame( 8, $analysis['events'] );
		$this->assertSame( 4, $analysis['overdue'] );
		$this->assertSame( 7200, $analysis['oldest_overdue_age'] );
		$this->assertSame( array( 'my_job' => 2, 'wp_version_check' => 1, 'just_late' => 1 ), $analysis['overdue_hooks'] );
		$this->assertSame( self::NOW - 7200, $analysis['next_run'] );
	}

	public function test_analyzer_ignores_junk(): void {
		$analysis = CronAnalyzer::analyse(
			array(
				'version'      => 2,
				'garbage'      => array( 'x' => array( 1 ) ),
				self::NOW - 10 => 'not-an-array',
				self::NOW - 20 => array( 'empty_hook' => array() ),
			),
			self::NOW,
			0
		);
		$this->assertSame( 0, $analysis['events'] );
		$this->assertNull( $analysis['oldest_overdue_age'] );
		$this->assertNull( $analysis['next_run'] );
	}

	private function check( FakeCron $probe ): CronCheck {
		return new CronCheck( $probe, new FrozenClock( self::NOW ), new Threshold( 10, 50 ), new Threshold( 3600, 21600 ), 600 );
	}

	public function test_healthy_cron(): void {
		$probe           = new FakeCron();
		$probe->events   = $this->cron( array( 300 => array( 'a' => 2 ) ) );
		$probe->last_run = self::NOW - 120;
		$result          = $this->check( $probe )->run();

		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( '2 events scheduled, none overdue.', $result->message );
		$this->assertSame( 120, $result->details['last_run_age_seconds'] );
		$names = array_map(
			static function ( $sample ) {
				return $sample->name;
			},
			$result->samples
		);
		$this->assertContains( 'cron_last_run_timestamp_seconds', $names );
	}

	public function test_overdue_events_warn_and_go_critical(): void {
		$probe           = new FakeCron();
		$probe->last_run = self::NOW - 60;
		$probe->events   = $this->cron( array( -3600 => array( 'stuck' => 12 ) ) );
		$result          = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( '12 overdue cron events (limit 10)', $result->message );

		$probe->events = $this->cron( array( -3600 => array( 'stuck' => 60 ) ) );
		$this->assertSame( Status::CRITICAL, $this->check( $probe )->run()->status );
	}

	public function test_old_last_run(): void {
		$probe           = new FakeCron();
		$probe->last_run = self::NOW - 4 * 3600;
		$result          = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'Cron last ran 4h ago (limit 1h)', $result->message );

		$probe->last_run = self::NOW - 7 * 3600;
		$this->assertSame( Status::CRITICAL, $this->check( $probe )->run()->status );
	}

	public function test_unknown_last_run_is_not_judged(): void {
		$probe           = new FakeCron();
		$probe->disabled = true;
		$result          = $this->check( $probe )->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertNull( $result->details['last_run'] );
		$this->assertTrue( $result->details['disabled'] );
	}

	public function test_critical_reasons_come_first(): void {
		$probe           = new FakeCron();
		$probe->last_run = self::NOW - 7 * 3600;
		$probe->events   = $this->cron( array( -3600 => array( 'stuck' => 12 ) ) );
		$result          = $this->check( $probe )->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( 'Cron last ran 7h ago (limit 6h); 12 overdue cron events (limit 10)', $result->message );
	}
}
