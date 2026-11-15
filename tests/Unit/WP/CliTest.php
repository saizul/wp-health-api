<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\WP;

use Saizul\HealthApi\Tests\WpTestCase;
use Saizul\HealthApi\WP\Cli\HealthCommand;
use Saizul\HealthApi\WP\Cli\TokenCommand;
use Saizul\HealthApi\WP\Options;
use WP_CLI;
use WpCliHalt;
use WpStubs;

final class CliTest extends WpTestCase {

	public function test_check_table_exits_zero_when_ok(): void {
		( new HealthCommand( $this->services( array() ) ) )->check( array(), array() );
		$this->assertSame( 'table', WP_CLI::$items['format'] );
		$this->assertSame( array( 'check', 'status', 'message', 'duration_ms' ), WP_CLI::$items['fields'] );
		$this->assertSame( 'database', WP_CLI::$items['items'][0]['check'] );
		$this->assertContains( array( 'line', 'Overall: ok' ), WP_CLI::$log );
	}

	public function test_check_exit_codes(): void {
		$this->probes['php']->version = '8.0.30';
		try {
			( new HealthCommand( $this->services( array() ) ) )->check( array(), array( 'fresh' => true ) );
			$this->fail( 'Expected a halt' );
		} catch ( WpCliHalt $e ) {
			$this->assertSame( 1, $e->getCode() );
		}

		$this->probes['database']->ping = array( 'connected' => false );
		try {
			( new HealthCommand( $this->services( array() ) ) )->check( array(), array( 'fresh' => true ) );
			$this->fail( 'Expected a halt' );
		} catch ( WpCliHalt $e ) {
			$this->assertSame( 2, $e->getCode() );
		}
	}

	public function test_check_json_and_subset(): void {
		( new HealthCommand( $this->services( array() ) ) )->check( array(), array( 'format' => 'json', 'checks' => 'php,database' ) );
		$json = json_decode( WP_CLI::$log[0][1], true );
		$this->assertSame( array( 'database', 'php' ), array_keys( $json['checks'] ) );
		$this->assertSame( 'ok', $json['status'] );
	}

	public function test_skipped_checks_are_labelled(): void {
		$this->probes['woocommerce']->available = false;
		( new HealthCommand( $this->services( array( 'checks' => array( 'woocommerce' => true ) ) ) ) )->check( array(), array( 'checks' => 'woocommerce', 'format' => 'csv' ) );
		$this->assertSame( 'skipped', WP_CLI::$items['items'][0]['status'] );
	}

	public function test_metrics(): void {
		( new HealthCommand( $this->services( array() ) ) )->metrics( array(), array() );
		$this->assertStringStartsWith( "# HELP wp_health_up ", WP_CLI::$out );
		$this->assertStringEndsWith( "\n", WP_CLI::$out );
	}

	public function test_token_rotate_status_revoke(): void {
		$command = new TokenCommand( $this->tokens() );
		$command->status( array(), array() );
		$this->assertSame( 'warning', WP_CLI::$log[0][0] );

		$command->rotate( array(), array() );
		$this->assertSame( 'success', WP_CLI::$log[1][0] );
		$token = WP_CLI::$log[2][1];
		$this->assertTrue( $this->tokens()->verifier()->verify( $token ) );

		WP_CLI::reset();
		$command->rotate( array(), array( 'yes' => true, 'porcelain' => true ) );
		$this->assertCount( 1, WP_CLI::$log );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', WP_CLI::$log[0][1] );

		WP_CLI::reset();
		$command->status( array(), array() );
		$this->assertStringContainsString( 'generated token', WP_CLI::$log[0][1] );

		$command->revoke( array(), array( 'yes' => true ) );
		$this->assertArrayNotHasKey( Options::TOKEN_HASH, WpStubs::$options );
	}

	public function test_rotate_asks_before_replacing(): void {
		$this->token();
		WP_CLI::$confirm = false;
		try {
			( new TokenCommand( $this->tokens() ) )->rotate( array(), array() );
			$this->fail( 'Expected abort' );
		} catch ( WpCliHalt $e ) {
			$this->assertSame( 'confirm', WP_CLI::$log[0][0] );
		}
		$this->assertTrue( $this->tokens()->verifier()->verify( 'test-token-0123456789abcdef' ), 'Token unchanged' );
	}

	public function test_rotate_warns_about_the_constant(): void {
		$this->constant = 'constant-token-for-tests-42';
		( new TokenCommand( $this->tokens() ) )->rotate( array(), array() );
		$this->assertSame( 'warning', WP_CLI::$log[ count( WP_CLI::$log ) - 1 ][0] );

		WP_CLI::reset();
		$this->constant = 'short';
		( new TokenCommand( $this->tokens() ) )->status( array(), array() );
		$this->assertStringContainsString( 'shorter than 16', WP_CLI::$log[0][1] );
	}
}
