<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\WooCommerceCheck;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes\FakeWooCommerce;
use Saizul\HealthApi\Threshold;

final class WooCommerceCheckTest extends TestCase {

	const NOW = 1800000000;

	private function check( FakeWooCommerce $probe, ?Threshold $orders = null, ?Threshold $failed = null ): WooCommerceCheck {
		return new WooCommerceCheck(
			$probe,
			new FrozenClock( self::NOW ),
			$orders ?? Threshold::disabled( Threshold::BELOW ),
			$failed ?? Threshold::disabled()
		);
	}

	public function test_skipped_without_woocommerce(): void {
		$probe            = new FakeWooCommerce();
		$probe->available = false;
		$this->assertTrue( $this->check( $probe )->run()->skipped );
		$this->assertNull( $probe->since );
	}

	public function test_counts_orders_of_the_last_hour(): void {
		$probe         = new FakeWooCommerce();
		$probe->orders = array( 'wc-processing' => 4, 'completed' => 2, 'wc-failed' => 1, 'pending' => -3 );
		$result        = $this->check( $probe )->run();

		$this->assertSame( self::NOW - 3600, $probe->since );
		$this->assertSame( Status::OK, $result->status, 'Thresholds are disabled by default' );
		$this->assertSame( '7 orders in the last hour, 1 failed.', $result->message );
		$this->assertSame( array( 'completed' => 2, 'failed' => 1, 'pending' => 0, 'processing' => 4 ), $result->details['by_status'] );
		$this->assertSame( 'woocommerce_orders_last_hour_total', $result->samples[0]->name );
		$this->assertSame( 7.0, $result->samples[0]->value );
		$this->assertCount( 5, $result->samples );
	}

	public function test_too_few_orders(): void {
		$probe  = new FakeWooCommerce();
		$result = $this->check( $probe, new Threshold( 3, 0, Threshold::BELOW ) )->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( '0 orders in the last hour (limit 0)', $result->message );

		$probe->orders = array( 'processing' => 2 );
		$this->assertSame( Status::WARN, $this->check( $probe, new Threshold( 3, 0, Threshold::BELOW ) )->run()->status );
	}

	public function test_failed_orders(): void {
		$probe         = new FakeWooCommerce();
		$probe->orders = array( 'failed' => 6, 'processing' => 1 );
		$result        = $this->check( $probe, null, new Threshold( 3, 5 ) )->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( '6 failed orders in the last hour (limit 5)', $result->message );
	}
}
