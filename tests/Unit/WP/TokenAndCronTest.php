<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\WP;

use Saizul\HealthApi\Auth\TokenHasher;
use Saizul\HealthApi\Tests\WpTestCase;
use Saizul\HealthApi\WP\CronRecorder;
use Saizul\HealthApi\WP\Options;
use Saizul\HealthApi\WP\Probe\WpCronProbe;
use Saizul\HealthApi\WP\TransientStore;
use WpStubs;

final class TokenAndCronTest extends WpTestCase {

	public function test_rotate_stores_only_the_hash(): void {
		$tokens = $this->tokens();
		$this->assertSame( 'none', $tokens->verifier()->source() );

		$token = $tokens->rotate();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
		$this->assertSame( TokenHasher::hash( $token ), WpStubs::$options[ Options::TOKEN_HASH ] );
		$this->assertStringNotContainsString( $token, serialize( WpStubs::$options ) );
		$this->assertTrue( $tokens->verifier()->verify( $token ) );
		$this->assertSame( 'wp_health_api_token_rotated', WpStubs::$actions[ count( WpStubs::$actions ) - 1 ][0] );

		$next = $tokens->rotate();
		$this->assertFalse( $tokens->verifier()->verify( $token ), 'The old token stops working' );
		$this->assertTrue( $tokens->verifier()->verify( $next ) );

		$tokens->revoke();
		$this->assertArrayNotHasKey( Options::TOKEN_HASH, WpStubs::$options );
		$this->assertFalse( $tokens->verifier()->verify( $next ) );
	}

	public function test_constant_reader(): void {
		$this->constant = 'constant-token-for-tests-42';
		$this->assertSame( 'constant', $this->tokens()->verifier()->source() );
		$this->assertSame( 'constant-token-for-tests-42', $this->tokens()->constant() );
	}

	public function test_default_constant_reader_without_constant(): void {
		$this->assertNull( ( new \Saizul\HealthApi\WP\TokenManager() )->constant() );
	}

	public function test_cron_recorder(): void {
		$recorder = new CronRecorder();
		$recorder->register();

		$recorder->on_init();
		$this->assertArrayNotHasKey( Options::LAST_CRON, WpStubs::$options, 'Normal page loads do not record' );
		$this->assertSame( Options::SCHEDULE, WpStubs::$scheduled[ Options::HEARTBEAT ][1] );

		WpStubs::$doing_cron = true;
		$recorder->on_init();
		$first = WpStubs::$options[ Options::LAST_CRON ];
		$this->assertEqualsWithDelta( time(), $first, 2 );

		WpStubs::$options[ Options::LAST_CRON ] = time() - 30;
		$recorder->record();
		$this->assertSame( time() - 30, WpStubs::$options[ Options::LAST_CRON ], 'At most one write per minute' );

		WpStubs::$options[ Options::LAST_CRON ] = time() - 120;
		do_action( Options::HEARTBEAT );
		$this->assertEqualsWithDelta( time(), WpStubs::$options[ Options::LAST_CRON ], 2 );

		$schedules = apply_filters( 'cron_schedules', array() );
		$this->assertSame( 300, $schedules[ Options::SCHEDULE ]['interval'] );

		CronRecorder::unschedule();
		$this->assertArrayNotHasKey( Options::HEARTBEAT, WpStubs::$scheduled );
	}

	public function test_cron_probe(): void {
		WpStubs::$options['cron'] = array( 1800000000 => array( 'hook' => array( 'k' => array() ) ), 'version' => 2 );
		$probe                    = new WpCronProbe();
		$this->assertSame( WpStubs::$options['cron'], $probe->events() );
		$this->assertNull( $probe->last_run() );
		WpStubs::$options[ Options::LAST_CRON ] = 1799999000;
		$this->assertSame( 1799999000, $probe->last_run() );
		$this->assertFalse( $probe->is_disabled() );

		WpStubs::$options['cron'] = false;
		$this->assertSame( array(), $probe->events() );
	}

	public function test_transient_store(): void {
		$store = new TransientStore();
		$this->assertNull( $store->get( 'x' ) );
		$store->set( 'x', array( 1 ), 10 );
		$this->assertSame( array( 1 ), WpStubs::$transients['wp_health_api_x'] );
		$this->assertSame( array( 1 ), $store->get( 'x' ) );
		$store->delete( 'x' );
		$this->assertNull( $store->get( 'x' ) );
	}
}
