<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\DiskCheck;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Tests\Fakes\FakeDisk;
use Saizul\HealthApi\Threshold;

final class DiskCheckTest extends TestCase {

	private function check( FakeDisk $probe ): DiskCheck {
		return new DiskCheck( $probe, new Threshold( 10, 5, Threshold::BELOW ), new Threshold( 1024, 256, Threshold::BELOW ) );
	}

	public function test_plenty_of_space(): void {
		$result = $this->check( new FakeDisk() )->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( '50.0 GiB free of 100.0 GiB (50%).', $result->message );
		$this->assertSame( 50.0, $result->details['free_percent'] );
		$names = array_map(
			static function ( $sample ) {
				return $sample->name;
			},
			$result->samples
		);
		$this->assertSame( array( 'uploads_writable', 'disk_free_bytes', 'disk_total_bytes', 'disk_free_ratio' ), $names );
		$this->assertSame( 0.5, $result->samples[3]->value );
	}

	public function test_low_percentage(): void {
		$probe       = new FakeDisk();
		$probe->free = 8.0 * 1073741824;
		$result      = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( '8% disk space free (limit 10%)', $result->message );

		$probe->free = 4.0 * 1073741824;
		$this->assertSame( Status::CRITICAL, $this->check( $probe )->run()->status );
	}

	public function test_absolute_space_on_a_big_disk(): void {
		$probe        = new FakeDisk();
		$probe->total = 100.0 * 1099511627776; // 100 TiB, so 200 MiB is a tiny percentage.
		$probe->free  = 200.0 * 1048576;
		$result       = $this->check( $probe )->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertStringContainsString( '200.0 MiB free (limit 256.0 MiB)', $result->message );
	}

	public function test_unreadable_space(): void {
		$probe        = new FakeDisk();
		$probe->free  = null;
		$probe->total = null;
		$result       = $this->check( $probe )->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( 'Free disk space cannot be read on this host.', $result->message );
		$this->assertCount( 1, $result->samples );
	}

	public function test_unwritable_uploads_is_critical(): void {
		$probe           = new FakeDisk();
		$probe->writable = false;
		$result          = $this->check( $probe )->run();
		$this->assertSame( Status::CRITICAL, $result->status );
		$this->assertSame( 'The uploads directory is not writable', $result->message );

		$probe->free = null;
		$this->assertSame( Status::CRITICAL, $this->check( $probe )->run()->status );
	}

	public function test_unknown_total_uses_the_absolute_limit_only(): void {
		$probe        = new FakeDisk();
		$probe->total = null;
		$probe->free  = 600.0 * 1048576;
		$result       = $this->check( $probe )->run();
		$this->assertSame( Status::WARN, $result->status );
		$this->assertNull( $result->details['free_percent'] );
		$this->assertSame( '600.0 MiB free (limit 1.0 GiB)', $result->message );
	}
}
