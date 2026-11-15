<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Prometheus;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Prometheus\MetricFamily;

final class MetricFamilyTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function names(): array {
		return array(
			'simple'          => array( 'wp_health_up', true ),
			'colon'           => array( 'job:wp_health:rate5m', true ),
			'leading _'       => array( '_private', true ),
			'leading digit'   => array( '1metric', false ),
			'dash'            => array( 'wp-health', false ),
			'dot'             => array( 'wp.health', false ),
			'empty'           => array( '', false ),
			'space'           => array( 'wp health', false ),
			'unicode'         => array( 'gesundheit_ü', false ),
		);
	}

	/**
	 * @dataProvider names
	 */
	public function test_metric_names( string $name, bool $valid ): void {
		$this->assertSame( $valid, MetricFamily::is_valid_name( $name ) );
	}

	public function test_label_names(): void {
		$this->assertTrue( MetricFamily::is_valid_label( 'check' ) );
		$this->assertTrue( MetricFamily::is_valid_label( '_x1' ) );
		$this->assertFalse( MetricFamily::is_valid_label( '__name__' ), 'Reserved prefix' );
		$this->assertFalse( MetricFamily::is_valid_label( 'a:b' ), 'Colons are not allowed in label names' );
		$this->assertFalse( MetricFamily::is_valid_label( '9lives' ) );
	}

	public function test_invalid_name_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new MetricFamily( 'wp-health', 'gauge' );
	}

	public function test_invalid_type_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new MetricFamily( 'm', 'meter' );
	}

	public function test_reserved_label_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		( new MetricFamily( 'm', 'gauge' ) )->add( 1, array( '__name__' => 'x' ) );
	}

	public function test_duplicate_label_set_is_rejected_in_any_order(): void {
		$family = ( new MetricFamily( 'm', 'gauge' ) )->add( 1, array( 'a' => '1', 'b' => '2' ) );
		$this->expectException( InvalidArgumentException::class );
		$family->add( 2, array( 'b' => '2', 'a' => '1' ) );
	}

	public function test_empty_label_value_equals_no_label(): void {
		$family = ( new MetricFamily( 'm', 'gauge' ) )->add( 1 );
		$this->expectException( InvalidArgumentException::class );
		$family->add( 2, array( 'a' => '' ) );
	}
}
