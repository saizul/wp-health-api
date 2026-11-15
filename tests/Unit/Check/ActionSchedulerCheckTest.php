<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\ActionSchedulerCheck;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes\FakeActionScheduler;
use Saizul\HealthApi\Threshold;

final class ActionSchedulerCheckTest extends TestCase {

	const NOW = 1800000000;

	private function check( FakeActionScheduler $probe ): ActionSchedulerCheck {
		return new ActionSchedulerCheck( $probe, new FrozenClock( self::NOW ), new Threshold( 25, 250 ), new Threshold( 10, null ), 600 );
	}

	public function test_missing_action_scheduler_is_skipped(): void {
		$probe            = new FakeActionScheduler();
		$probe->available = false;
		$result           = $this->check( $probe )->run();
		$this->assertTrue( $result->skipped );
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( array(), $probe->queries );
	}

	public function test_queries_use_grace_and_24_hours(): void {
		$probe = new FakeActionScheduler();
		$this->check( $probe )->run();
		$this->assertSame(
			array( array( 'past_due', self::NOW - 600 ), array( 'failed', self::NOW - 86400 ) ),
			$probe->queries
		);
	}

	public function test_thresholds(): void {
		$probe           = new FakeActionScheduler();
		$probe->past_due = 30;
		$probe->oldest   = self::NOW - 5000;
		$result          = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( '30 past-due actions (limit 25)', $result->message );
		$this->assertSame( 5000, $result->details['oldest_past_due_age'] );

		$probe->past_due = 300;
		$this->assertSame( Status::CRITICAL, $this->check( $probe )->run()->status );

		$probe->past_due = 0;
		$probe->failed   = 9999;
		$failed          = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $failed->status, 'Critical level is disabled for failures' );
		$this->assertSame( 9999.0, $failed->samples[1]->value );
		$this->assertNull( $failed->details['oldest_past_due'] );
	}

	public function test_ok_message(): void {
		$this->assertSame( '0 past-due, 0 failed in the last 24 hours.', $this->check( new FakeActionScheduler() )->run()->message );
	}
}
