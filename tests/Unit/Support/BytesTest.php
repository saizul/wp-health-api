<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Support\Bytes;
use Saizul\HealthApi\Support\Duration;

final class BytesTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:int|null}>
	 */
	public function ini_values(): array {
		return array(
			'megabytes'      => array( '256M', 268435456 ),
			'lower case'     => array( '128m', 134217728 ),
			'gigabytes'      => array( '1G', 1073741824 ),
			'kilobytes'      => array( '512K', 524288 ),
			'plain bytes'    => array( '1048576', 1048576 ),
			'spaces'         => array( ' 64M ', 67108864 ),
			'unlimited'      => array( '-1', null ),
			'empty'          => array( '', null ),
			'garbage'        => array( 'lots', null ),
			'two letters'    => array( '128MB', null ),
		);
	}

	/**
	 * @dataProvider ini_values
	 */
	public function test_parse_ini( string $raw, ?int $expected ): void {
		$this->assertSame( $expected, Bytes::parse_ini( $raw ) );
	}

	public function test_format(): void {
		$this->assertSame( '512 B', Bytes::format( 512 ) );
		$this->assertSame( '1.0 KiB', Bytes::format( 1024 ) );
		$this->assertSame( '1.5 MiB', Bytes::format( 1572864 ) );
		$this->assertSame( '20.0 GiB', Bytes::format( 20 * 1073741824 ) );
		$this->assertSame( '2.00 TiB', Bytes::format( 2 * 1099511627776, 2 ) );
	}

	public function test_duration(): void {
		$this->assertSame( '0s', Duration::format( -5 ) );
		$this->assertSame( '45s', Duration::format( 45 ) );
		$this->assertSame( '12m', Duration::format( 750 ) );
		$this->assertSame( '1h', Duration::format( 3600 ) );
		$this->assertSame( '3h 5m', Duration::format( 3 * 3600 + 300 ) );
		$this->assertSame( '2d 4h', Duration::format( 2 * 86400 + 4 * 3600 + 59 ) );
		$this->assertSame( '1d', Duration::format( 86400 ) );
	}
}
