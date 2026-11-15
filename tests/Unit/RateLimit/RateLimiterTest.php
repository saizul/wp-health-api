<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\RateLimit\ClientIp;
use Saizul\HealthApi\RateLimit\RateLimiter;
use Saizul\HealthApi\Support\ArrayStore;
use Saizul\HealthApi\Support\FrozenClock;

final class RateLimiterTest extends TestCase {

	/** @var FrozenClock */
	private $clock;

	/** @var RateLimiter */
	private $limiter;

	protected function setUp(): void {
		$this->clock   = new FrozenClock( 1800000000 ); // Start of a 60 s window.
		$this->limiter = new RateLimiter( new ArrayStore( $this->clock ), $this->clock );
	}

	public function test_allows_up_to_the_limit(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$result = $this->limiter->hit( 'b', 3, 60 );
			$this->assertTrue( $result->allowed );
			$this->assertSame( 3 - $i, $result->remaining );
		}
		$blocked = $this->limiter->hit( 'b', 3, 60 );
		$this->assertFalse( $blocked->allowed );
		$this->assertSame( 0, $blocked->remaining );
		$this->assertSame( 60, $blocked->retry_after );
		$this->assertSame( '60', $blocked->headers()['Retry-After'] );
		$this->assertSame( '3', $blocked->headers()['X-RateLimit-Limit'] );
	}

	public function test_window_resets(): void {
		$this->limiter->hit( 'b', 1, 60 );
		$this->clock->advance( 30 );
		$blocked = $this->limiter->hit( 'b', 1, 60 );
		$this->assertFalse( $blocked->allowed );
		$this->assertSame( 30, $blocked->retry_after );
		$this->clock->advance( 30 );
		$this->assertTrue( $this->limiter->hit( 'b', 1, 60 )->allowed );
	}

	public function test_buckets_are_independent(): void {
		$this->limiter->hit( 'a', 1, 60 );
		$this->assertFalse( $this->limiter->hit( 'a', 1, 60 )->allowed );
		$this->assertTrue( $this->limiter->hit( 'b', 1, 60 )->allowed );
	}

	public function test_zero_means_unlimited(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertTrue( $this->limiter->hit( 'b', 0, 60 )->allowed );
		}
		$this->assertSame( array(), $this->limiter->hit( 'b', 0, 60 )->headers() );
		$this->assertFalse( $this->limiter->is_blocked( 'b', 0, 60 ) );
	}

	public function test_is_blocked_does_not_count(): void {
		$this->assertFalse( $this->limiter->is_blocked( 'f', 2, 300 ) );
		$this->limiter->hit( 'f', 2, 300 );
		$this->assertFalse( $this->limiter->is_blocked( 'f', 2, 300 ) );
		$this->limiter->hit( 'f', 2, 300 );
		$this->assertTrue( $this->limiter->is_blocked( 'f', 2, 300 ) );
		$this->assertTrue( $this->limiter->is_blocked( 'f', 2, 300 ) );
	}

	public function test_client_ip(): void {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.2',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.2',
		);
		$this->assertSame( '10.0.0.2', ClientIp::resolve( $server ), 'Proxy headers are ignored unless trusted' );
		$this->assertSame( '203.0.113.9', ClientIp::resolve( $server, 'HTTP_X_FORWARDED_FOR' ) );
		$this->assertSame( '10.0.0.2', ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'junk' ), 'HTTP_X_FORWARDED_FOR' ) );
		$this->assertSame( '2001:db8::1', ClientIp::resolve( array( 'REMOTE_ADDR' => '2001:DB8::1' ) ) );
		$this->assertSame( '2001:db8::1', ClientIp::resolve( array( 'HTTP_X_REAL_IP' => '[2001:db8::1]:443' ), 'HTTP_X_REAL_IP' ) );
		$this->assertSame( '198.51.100.4', ClientIp::resolve( array( 'HTTP_X_REAL_IP' => '198.51.100.4:8080' ), 'HTTP_X_REAL_IP' ) );
		$this->assertSame( '', ClientIp::resolve( array() ) );
		$this->assertSame( '', ClientIp::resolve( array( 'REMOTE_ADDR' => 'not-an-ip' ) ) );
	}

	public function test_bucket_hides_the_ip(): void {
		$bucket = ClientIp::bucket( '203.0.113.9', 'secret' );
		$this->assertSame( 24, strlen( $bucket ) );
		$this->assertStringNotContainsString( '203', $bucket );
		$this->assertNotSame( $bucket, ClientIp::bucket( '203.0.113.9', 'other-secret' ) );
		$this->assertSame( $bucket, ClientIp::bucket( '203.0.113.9', 'secret' ) );
	}
}
