<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\MaintenanceCheck;
use Saizul\HealthApi\Maintenance\MaintenanceFile;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes\FakeMaintenance;

final class MaintenanceCheckTest extends TestCase {

	const NOW = 1800000000;

	public function test_parser(): void {
		$this->assertSame( 1799999900, MaintenanceFile::upgrading( '<?php $upgrading = 1799999900; ?>' ) );
		$this->assertSame( 1799999900, MaintenanceFile::upgrading( "<?php\n\$upgrading=1799999900;\n" ) );
		$this->assertNull( MaintenanceFile::upgrading( '<?php $upgrading = time(); ?>' ) );
		$this->assertNull( MaintenanceFile::upgrading( '' ) );
	}

	public function test_state(): void {
		$this->assertFalse( MaintenanceFile::state( null, self::NOW )['active'] );

		$fresh = MaintenanceFile::state( '<?php $upgrading = ' . ( self::NOW - 30 ) . '; ?>', self::NOW );
		$this->assertTrue( $fresh['active'] );
		$this->assertSame( 30, $fresh['age'] );

		$stale = MaintenanceFile::state( '<?php $upgrading = ' . ( self::NOW - 600 ) . '; ?>', self::NOW );
		$this->assertFalse( $stale['active'] );
		$this->assertTrue( $stale['stale'] );

		$dynamic = MaintenanceFile::state( '<?php $upgrading = time(); ?>', self::NOW );
		$this->assertTrue( $dynamic['active'] );
		$this->assertNull( $dynamic['age'] );
	}

	private function run_check( FakeMaintenance $probe, string $status = Status::WARN ): \Saizul\HealthApi\CheckResult {
		return ( new MaintenanceCheck( $probe, new FrozenClock( self::NOW ), $status ) )->run();
	}

	public function test_no_maintenance(): void {
		$result = $this->run_check( new FakeMaintenance() );
		$this->assertSame( Status::OK, $result->status );
		$this->assertFalse( $result->details['file'] );
		$this->assertSame( 0.0, $result->samples[0]->value );
	}

	public function test_active_maintenance_uses_the_configured_status(): void {
		$probe           = new FakeMaintenance();
		$probe->contents = '<?php $upgrading = ' . ( self::NOW - 125 ) . '; ?>';
		$result          = $this->run_check( $probe );
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'Maintenance mode is active (started 2m ago).', $result->message );
		$this->assertSame( 1.0, $result->samples[0]->value );

		$this->assertSame( Status::CRITICAL, $this->run_check( $probe, Status::CRITICAL )->status );
	}

	public function test_stale_file_warns(): void {
		$probe           = new FakeMaintenance();
		$probe->contents = '<?php $upgrading = ' . ( self::NOW - 7200 ) . '; ?>';
		$result          = $this->run_check( $probe, Status::CRITICAL );
		$this->assertSame( Status::WARN, $result->status );
		$this->assertStringContainsString( 'stale .maintenance file from 2h ago', $result->message );
		$this->assertFalse( $result->details['active'] );
	}

	public function test_forced_by_a_plugin(): void {
		$probe         = new FakeMaintenance();
		$probe->forced = true;
		$result        = $this->run_check( $probe );
		$this->assertTrue( $result->details['active'] );
		$this->assertSame( 'Maintenance mode is active.', $result->message );

		$probe->contents = '<?php $upgrading = ' . self::NOW . '; ?>';
		$probe->forced   = false;
		$this->assertSame( Status::OK, $this->run_check( $probe )->status, 'A filter can switch maintenance off' );
	}
}
