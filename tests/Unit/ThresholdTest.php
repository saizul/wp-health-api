<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

final class ThresholdTest extends TestCase {

	/**
	 * @return array<string,array{0:float|null,1:string}>
	 */
	public function above_cases(): array {
		return array(
			'below warn'    => array( 99.9, Status::OK ),
			'on warn'       => array( 100.0, Status::WARN ),
			'between'       => array( 250.0, Status::WARN ),
			'on critical'   => array( 500.0, Status::CRITICAL ),
			'over critical' => array( 9000.0, Status::CRITICAL ),
			'not measured'  => array( null, Status::OK ),
			'zero'          => array( 0.0, Status::OK ),
		);
	}

	/**
	 * @dataProvider above_cases
	 */
	public function test_above( ?float $value, string $expected ): void {
		$threshold = new Threshold( 100, 500, Threshold::ABOVE );
		$this->assertSame( $expected, $threshold->evaluate( $value ) );
	}

	/**
	 * @return array<string,array{0:float,1:string}>
	 */
	public function below_cases(): array {
		return array(
			'plenty'      => array( 50.0, Status::OK ),
			'on warn'     => array( 10.0, Status::WARN ),
			'between'     => array( 7.5, Status::WARN ),
			'on critical' => array( 5.0, Status::CRITICAL ),
			'empty'       => array( 0.0, Status::CRITICAL ),
			'negative'    => array( -3.0, Status::CRITICAL ),
		);
	}

	/**
	 * @dataProvider below_cases
	 */
	public function test_below( float $value, string $expected ): void {
		$threshold = new Threshold( 10, 5, Threshold::BELOW );
		$this->assertSame( $expected, $threshold->evaluate( $value ) );
	}

	public function test_nan_is_ok(): void {
		$this->assertSame( Status::OK, ( new Threshold( 1, 2 ) )->evaluate( NAN ) );
	}

	public function test_levels_can_be_disabled(): void {
		$warn_only = new Threshold( 10, null );
		$this->assertSame( Status::WARN, $warn_only->evaluate( 1e9 ) );

		$critical_only = new Threshold( null, 10 );
		$this->assertSame( Status::OK, $critical_only->evaluate( 9 ) );
		$this->assertSame( Status::CRITICAL, $critical_only->evaluate( 10 ) );

		$this->assertTrue( Threshold::disabled()->is_disabled() );
		$this->assertSame( Status::OK, Threshold::disabled()->evaluate( PHP_FLOAT_MAX ) );
	}

	public function test_equal_limits_go_straight_to_critical(): void {
		$threshold = new Threshold( 5, 5 );
		$this->assertSame( Status::OK, $threshold->evaluate( 4.99 ) );
		$this->assertSame( Status::CRITICAL, $threshold->evaluate( 5 ) );
	}

	public function test_wrong_order_above_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Threshold( 500, 100, Threshold::ABOVE );
	}

	public function test_wrong_order_below_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Threshold( 5, 10, Threshold::BELOW );
	}

	public function test_unknown_direction_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Threshold( 1, 2, 'sideways' );
	}

	public function test_infinite_limits_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Threshold( INF, null );
	}

	public function test_from_array(): void {
		$threshold = Threshold::from_array( array( 'warn' => '15', 'critical' => '' ), Threshold::BELOW );
		$this->assertSame( 15.0, $threshold->warn() );
		$this->assertNull( $threshold->critical() );
		$this->assertSame( Threshold::BELOW, $threshold->direction() );
		$this->assertSame(
			array( 'warn' => 15.0, 'critical' => null, 'direction' => 'below' ),
			$threshold->to_array()
		);
	}

	public function test_from_array_rejects_text(): void {
		$this->expectException( InvalidArgumentException::class );
		Threshold::from_array( array( 'warn' => 'ten' ) );
	}

	public function test_limit_for(): void {
		$threshold = new Threshold( 1, 2 );
		$this->assertSame( 1.0, $threshold->limit_for( Status::WARN ) );
		$this->assertSame( 2.0, $threshold->limit_for( Status::CRITICAL ) );
		$this->assertNull( $threshold->limit_for( Status::OK ) );
	}
}
