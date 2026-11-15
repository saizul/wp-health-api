<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Status;

final class StatusTest extends TestCase {

	public function test_severity_order(): void {
		$this->assertSame( 0, Status::severity( Status::OK ) );
		$this->assertSame( 1, Status::severity( Status::WARN ) );
		$this->assertSame( 2, Status::severity( Status::CRITICAL ) );
		$this->assertSame( array( 'ok', 'warn', 'critical' ), Status::all() );
	}

	public function test_worst(): void {
		$this->assertSame( Status::OK, Status::worst( array() ) );
		$this->assertSame( Status::OK, Status::worst( array( 'ok', 'ok' ) ) );
		$this->assertSame( Status::WARN, Status::worst( array( 'ok', 'warn', 'ok' ) ) );
		$this->assertSame( Status::CRITICAL, Status::worst( array( 'warn', 'critical', 'ok' ) ) );
	}

	public function test_unknown_status_is_rejected(): void {
		$this->assertFalse( Status::is_valid( 'unknown' ) );
		$this->expectException( InvalidArgumentException::class );
		Status::worst( array( 'ok', 'down' ) );
	}
}
