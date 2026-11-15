<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Auth\TokenGenerator;
use Saizul\HealthApi\Auth\TokenHasher;
use Saizul\HealthApi\Auth\TokenVerifier;

final class TokenVerifierTest extends TestCase {

	public function test_nothing_configured_rejects_everything(): void {
		$verifier = new TokenVerifier( null, null );
		$this->assertSame( TokenVerifier::SOURCE_NONE, $verifier->source() );
		$this->assertFalse( $verifier->is_configured() );
		$this->assertFalse( $verifier->verify( 'anything' ) );
		$this->assertFalse( $verifier->verify( '' ) );
		$this->assertFalse( $verifier->verify( null ) );
	}

	public function test_constant_token(): void {
		$verifier = new TokenVerifier( 'example-constant-token-value', null );
		$this->assertSame( TokenVerifier::SOURCE_CONSTANT, $verifier->source() );
		$this->assertTrue( $verifier->is_configured() );
		$this->assertTrue( $verifier->verify( 'example-constant-token-value' ) );
		$this->assertFalse( $verifier->verify( 'example-constant-token-valuE' ) );
		$this->assertFalse( $verifier->verify( 'example-constant-token-value ' ) );
		$this->assertFalse( $verifier->verify( 'example' ) );
		$this->assertFalse( $verifier->verify( '' ) );
	}

	public function test_constant_is_trimmed(): void {
		$verifier = new TokenVerifier( "  example-constant-token-value\n", null );
		$this->assertTrue( $verifier->verify( 'example-constant-token-value' ) );
	}

	public function test_short_constant_is_weak_and_never_accepted(): void {
		$verifier = new TokenVerifier( 'short', null );
		$this->assertTrue( $verifier->is_weak() );
		$this->assertFalse( $verifier->is_configured() );
		$this->assertFalse( $verifier->verify( 'short' ) );
	}

	public function test_blank_constant_is_ignored(): void {
		$verifier = new TokenVerifier( '   ', TokenHasher::hash( 'stored-token-for-tests-0001' ) );
		$this->assertSame( TokenVerifier::SOURCE_OPTION, $verifier->source() );
	}

	public function test_stored_hash(): void {
		$token    = TokenGenerator::generate();
		$verifier = new TokenVerifier( null, TokenHasher::hash( $token ) );
		$this->assertSame( TokenVerifier::SOURCE_OPTION, $verifier->source() );
		$this->assertTrue( $verifier->verify( $token ) );
		$this->assertFalse( $verifier->verify( strtoupper( $token ) ) );
		$this->assertFalse( $verifier->verify( substr( $token, 0, -1 ) ) );
	}

	public function test_the_hash_itself_is_not_a_valid_token(): void {
		$hash     = TokenHasher::hash( 'stored-token-for-tests-0001' );
		$verifier = new TokenVerifier( null, $hash );
		$this->assertFalse( $verifier->verify( $hash ) );
		$this->assertFalse( $verifier->verify( substr( $hash, 7 ) ) );
	}

	public function test_malformed_hash_is_ignored(): void {
		$verifier = new TokenVerifier( null, 'plain-text-token' );
		$this->assertSame( TokenVerifier::SOURCE_NONE, $verifier->source() );
		$this->assertFalse( $verifier->verify( 'plain-text-token' ) );
	}

	public function test_constant_wins_over_stored_hash(): void {
		$verifier = new TokenVerifier( 'example-constant-token-value', TokenHasher::hash( 'stored-token-for-tests-0001' ) );
		$this->assertTrue( $verifier->verify( 'example-constant-token-value' ) );
		$this->assertFalse( $verifier->verify( 'stored-token-for-tests-0001' ) );
	}

	public function test_hasher_format(): void {
		$hash = TokenHasher::hash( 'abc' );
		$this->assertSame( 'sha256:ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $hash );
		$this->assertTrue( TokenHasher::is_hash( $hash ) );
		$this->assertFalse( TokenHasher::is_hash( 'sha256:XYZ' ) );
		$this->assertFalse( TokenHasher::is_hash( 'md5:900150983cd24fb0d6963f7d28e17f72' ) );
	}

	public function test_generator(): void {
		$a = TokenGenerator::generate();
		$b = TokenGenerator::generate();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 32, strlen( TokenGenerator::generate( 4 ) ), 'At least 16 bytes' );
	}
}
