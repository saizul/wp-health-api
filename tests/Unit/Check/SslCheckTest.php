<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\SslCheck;
use Saizul\HealthApi\Ssl\Certificate;
use Saizul\HealthApi\Ssl\HostnameMatcher;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\ArrayStore;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes\FakeSsl;
use Saizul\HealthApi\Threshold;

final class SslCheckTest extends TestCase {

	const NOW = 1800000000;

	/** @var FrozenClock */
	private $clock;

	/** @var ArrayStore */
	private $store;

	/** @var FakeSsl */
	private $probe;

	protected function setUp(): void {
		$this->clock = new FrozenClock( self::NOW );
		$this->store = new ArrayStore( $this->clock );
		$this->probe = new FakeSsl();
	}

	private function certificate( int $days_left, array $names = array( 'example.com', '*.example.com' ) ): Certificate {
		return new Certificate( self::NOW - 60 * 86400, self::NOW + $days_left * 86400, 'example.com', 'Example CA', $names );
	}

	private function check( string $url = 'https://shop.example.com' ): SslCheck {
		return new SslCheck( $this->probe, $this->store, $this->clock, new Threshold( 14, 7, Threshold::BELOW ), $url, 43200 );
	}

	public function test_endpoint(): void {
		$this->assertSame( array( 'example.com', 443 ), SslCheck::endpoint( 'https://Example.com/shop/' ) );
		$this->assertSame( array( 'example.com', 8443 ), SslCheck::endpoint( 'https://example.com:8443' ) );
		$this->assertSame( array( '::1', 443 ), SslCheck::endpoint( 'https://[::1]/' ) );
		$this->assertNull( SslCheck::endpoint( 'http://example.com' ) );
		$this->assertNull( SslCheck::endpoint( 'not a url' ) );
	}

	public function test_http_site_is_skipped(): void {
		$result = $this->check( 'http://example.com' )->run();
		$this->assertTrue( $result->skipped );
		$this->assertSame( array(), $this->probe->calls );
	}

	public function test_valid_certificate(): void {
		$this->probe->certificate = $this->certificate( 60 );
		$result                   = $this->check()->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( 'Certificate for shop.example.com expires in 60 days.', $result->message );
		$this->assertSame( array( array( 'shop.example.com', 443 ) ), $this->probe->calls );
		$this->assertFalse( $result->details['cached'] );
		$this->assertSame( (float) ( self::NOW + 60 * 86400 ), $result->samples[0]->value );
		$this->assertSame( array( 'host' => 'shop.example.com' ), $result->samples[0]->labels );
	}

	public function test_results_are_cached(): void {
		$this->probe->certificate = $this->certificate( 60 );
		$this->check()->run();
		$this->clock->advance( 3600 );
		$second = $this->check()->run();
		$this->assertTrue( $second->details['cached'] );
		$this->assertCount( 1, $this->probe->calls );
		$this->assertSame( 59.96, $second->details['days_left'], 'Days left are computed from the current time' );

		$this->clock->advance( 43200 );
		$this->check()->run();
		$this->assertCount( 2, $this->probe->calls );
	}

	public function test_expiry_thresholds(): void {
		$this->probe->certificate = $this->certificate( 10 );
		$this->assertSame( Status::WARN, $this->check()->run()->status );

		$this->store->delete( 'ssl_' . md5( 'shop.example.com:443' ) );
		$this->probe->certificate = $this->certificate( 3 );
		$result                   = $this->check()->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( 'Certificate expires in 3 days (limit 7 days)', $result->message );
	}

	public function test_expired_certificate(): void {
		$this->probe->certificate = $this->certificate( -1 );
		$result                   = $this->check()->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( 'The certificate has expired', $result->message );
	}

	public function test_wrong_host(): void {
		$this->probe->certificate = $this->certificate( 60, array( 'other.test' ) );
		$result                   = $this->check()->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( 'The certificate does not cover shop.example.com', $result->message );
		$this->assertFalse( $result->details['covers_host'] );
	}

	public function test_errors_warn_and_are_cached_briefly(): void {
		$result = $this->check()->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'Could not read the certificate: Connection refused', $result->message );
		$this->check()->run();
		$this->assertCount( 1, $this->probe->calls );
		$this->clock->advance( SslCheck::ERROR_TTL );
		$this->check()->run();
		$this->assertCount( 2, $this->probe->calls );
	}

	public function test_certificate_from_openssl(): void {
		$certificate = Certificate::from_openssl(
			array(
				'subject'          => array( 'CN' => 'example.com' ),
				'issuer'           => array( 'C' => 'US', 'O' => "Let's Encrypt", 'CN' => 'R11' ),
				'validFrom_time_t' => 1790000000,
				'validTo_time_t'   => 1797776000,
				'extensions'       => array( 'subjectAltName' => 'DNS:example.com, DNS:*.Example.com, IP Address:192.0.2.1' ),
			)
		);
		$this->assertSame( "Let's Encrypt", $certificate->issuer );
		$this->assertSame( array( 'example.com', '*.example.com' ), $certificate->names );
		$this->assertSame( 1797776000 - 1790000000, $certificate->seconds_left( 1790000000 ) );
		$this->assertEquals( $certificate, Certificate::from_array( $certificate->to_array() ) );

		$this->expectException( InvalidArgumentException::class );
		Certificate::from_openssl( array( 'subject' => array() ) );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function host_cases(): array {
		return array(
			'exact'                   => array( 'example.com', 'example.com', true ),
			'case'                    => array( 'Example.COM', 'example.com', true ),
			'trailing dot'            => array( 'example.com.', 'example.com', true ),
			'wildcard'                => array( '*.example.com', 'shop.example.com', true ),
			'wildcard bare domain'    => array( '*.example.com', 'example.com', false ),
			'wildcard two labels'     => array( '*.example.com', 'a.b.example.com', false ),
			'wildcard top level'      => array( '*.com', 'example.com', false ),
			'partial wildcard'        => array( 'shop*.example.com', 'shop1.example.com', false ),
			'different'               => array( 'example.org', 'example.com', false ),
			'suffix trick'            => array( '*.example.com', 'evilexample.com', false ),
			'empty'                   => array( '', 'example.com', false ),
		);
	}

	/**
	 * @dataProvider host_cases
	 */
	public function test_hostname_matching( string $pattern, string $host, bool $expected ): void {
		$this->assertSame( $expected, HostnameMatcher::matches( $pattern, $host ) );
	}

	public function test_common_name_is_used_only_without_san(): void {
		$cn_only = new Certificate( 0, 1, 'legacy.example.com', '', array() );
		$this->assertTrue( HostnameMatcher::covers( $cn_only, 'legacy.example.com' ) );

		$with_san = new Certificate( 0, 1, 'legacy.example.com', '', array( 'www.example.com' ) );
		$this->assertFalse( HostnameMatcher::covers( $with_san, 'legacy.example.com' ) );
	}
}
