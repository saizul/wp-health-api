<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Auth\HeaderToken;

final class HeaderTokenTest extends TestCase {

	public function test_bearer(): void {
		$this->assertSame( 'YOUR_TOKEN', HeaderToken::extract( array( 'Authorization' => 'Bearer YOUR_TOKEN' ) ) );
		$this->assertSame( 'YOUR_TOKEN', HeaderToken::extract( array( 'authorization' => 'bearer   YOUR_TOKEN ' ) ) );
		$this->assertSame( 'YOUR_TOKEN', HeaderToken::extract( array( 'authorization' => array( 'Bearer YOUR_TOKEN' ) ) ) );
	}

	public function test_custom_header_in_any_spelling(): void {
		$this->assertSame( 'YOUR_TOKEN', HeaderToken::extract( array( 'X-Health-Token' => 'YOUR_TOKEN' ) ) );
		$this->assertSame( 'YOUR_TOKEN', HeaderToken::extract( array( 'x_health_token' => ' YOUR_TOKEN ' ) ) );
	}

	public function test_bearer_wins(): void {
		$this->assertSame(
			'FROM_BEARER',
			HeaderToken::extract(
				array(
					'x-health-token' => 'FROM_HEADER',
					'authorization'  => 'Bearer FROM_BEARER',
				)
			)
		);
	}

	public function test_other_schemes_fall_back_to_custom_header(): void {
		$this->assertNull( HeaderToken::extract( array( 'authorization' => 'Basic dXNlcjpwYXNz' ) ) );
		$this->assertSame(
			'YOUR_TOKEN',
			HeaderToken::extract(
				array(
					'authorization'  => 'Basic dXNlcjpwYXNz',
					'x-health-token' => 'YOUR_TOKEN',
				)
			)
		);
	}

	public function test_malformed_tokens(): void {
		$this->assertNull( HeaderToken::extract( array() ) );
		$this->assertNull( HeaderToken::extract( array( 'authorization' => 'Bearer' ) ) );
		$this->assertNull( HeaderToken::extract( array( 'authorization' => 'Bearer two words' ) ) );
		$this->assertNull( HeaderToken::extract( array( 'x-health-token' => '' ) ) );
		$this->assertNull( HeaderToken::extract( array( 'x-health-token' => "tok\x00en" ) ) );
		$this->assertNull( HeaderToken::extract( array( 'x-health-token' => 'tökén' ) ) );
		$this->assertNull( HeaderToken::extract( array( 'x-health-token' => str_repeat( 'a', 513 ) ) ) );
		$this->assertSame( str_repeat( 'a', 512 ), HeaderToken::extract( array( 'x-health-token' => str_repeat( 'a', 512 ) ) ) );
	}
}
