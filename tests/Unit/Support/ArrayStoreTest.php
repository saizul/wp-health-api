<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Support\ArrayStore;
use Saizul\HealthApi\Support\FrozenClock;

final class ArrayStoreTest extends TestCase {

	public function test_values_expire(): void {
		$clock = new FrozenClock( 1000 );
		$store = new ArrayStore( $clock );
		$store->set( 'a', array( 'x' => 1 ), 10 );
		$this->assertSame( array( 'x' => 1 ), $store->get( 'a' ) );
		$clock->advance( 9 );
		$this->assertSame( 1, $store->count() );
		$clock->advance( 1 );
		$this->assertNull( $store->get( 'a' ) );
		$this->assertSame( 0, $store->count() );
	}

	public function test_delete_and_minimum_ttl(): void {
		$clock = new FrozenClock( 1000 );
		$store = new ArrayStore( $clock );
		$store->set( 'a', 1, 0 );
		$this->assertSame( 1, $store->get( 'a' ) );
		$store->delete( 'a' );
		$this->assertNull( $store->get( 'a' ) );
	}

	public function test_frozen_clock_fractions(): void {
		$clock = new FrozenClock( 1000 );
		$clock->advance( 0.25 );
		$this->assertSame( 1000, $clock->now() );
		$this->assertEqualsWithDelta( 1000.25, $clock->microtime(), 1e-9 );
		$clock->advance( 0.75 );
		$this->assertSame( 1001, $clock->now() );
	}
}
