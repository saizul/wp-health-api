<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Check\UpdatesCheck;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Tests\Fakes\FakeUpdates;
use Saizul\HealthApi\Threshold;
use Saizul\HealthApi\Updates\UpdateCounter;

final class UpdatesCheckTest extends TestCase {

	/**
	 * A core transient as WordPress stores it.
	 *
	 * @param string $response Response of the first offer.
	 * @param string $version Version of the first offer.
	 */
	private function core( string $response, string $version ): object {
		return (object) array(
			'updates'         => array(
				(object) array(
					'response' => $response,
					'current'  => $version,
					'version'  => $version,
					'locale'   => 'en_US',
				),
				(object) array(
					'response' => 'autoupdate',
					'current'  => '6.7.9',
				),
			),
			'last_checked'    => 1799990000,
			'version_checked' => '6.7.1',
		);
	}

	/**
	 * Plugin transient.
	 *
	 * @param array<string,string> $updates File => new version.
	 * @param int                  $translations Translation updates.
	 */
	private function plugins( array $updates, int $translations = 0 ): object {
		$response = array();
		foreach ( $updates as $file => $version ) {
			$response[ $file ] = (object) array(
				'slug'        => dirname( $file ),
				'plugin'      => $file,
				'new_version' => $version,
			);
		}

		return (object) array(
			'last_checked' => 1799995000,
			'response'     => $response,
			'no_update'    => array( 'hello.php' => (object) array() ),
			'translations' => array_fill( 0, $translations, array( 'language' => 'bn_BD' ) ),
		);
	}

	private function check( FakeUpdates $probe ): UpdatesCheck {
		return new UpdatesCheck( $probe, new Threshold( 1, null ), new Threshold( 1, 10 ), new Threshold( 1, null ) );
	}

	public function test_core_counter(): void {
		$this->assertSame( array( 'available' => true, 'version' => '6.8', 'last_checked' => 1799990000 ), UpdateCounter::core( $this->core( 'upgrade', '6.8' ), '6.7.1' ) );
		$this->assertFalse( UpdateCounter::core( $this->core( 'latest', '6.7.1' ), '6.7.1' )['available'] );
		$this->assertFalse( UpdateCounter::core( $this->core( 'upgrade', '6.7.1' ), '6.7.1' )['available'], 'Same version is not an update' );
		$this->assertFalse( UpdateCounter::core( $this->core( 'development', '6.9-beta1' ), '6.7.1' )['available'] );
		$this->assertFalse( UpdateCounter::core( false, '6.7.1' )['available'] );
		$this->assertNull( UpdateCounter::core( false, '6.7.1' )['last_checked'] );
	}

	public function test_core_counter_accepts_arrays_and_picks_highest(): void {
		$transient = array(
			'updates' => array(
				array( 'response' => 'upgrade', 'current' => '6.7.2' ),
				array( 'response' => 'upgrade', 'current' => '6.8.1' ),
			),
		);
		$this->assertSame( '6.8.1', UpdateCounter::core( $transient, '6.7.1' )['version'] );
	}

	public function test_extension_counter(): void {
		$counts = UpdateCounter::extensions( $this->plugins( array( 'woocommerce/woocommerce.php' => '9.5.0', 'akismet/akismet.php' => '5.3.5' ), 2 ) );
		$this->assertSame( 2, $counts['count'] );
		$this->assertSame( array( 'akismet/akismet.php' => '5.3.5', 'woocommerce/woocommerce.php' => '9.5.0' ), $counts['items'] );
		$this->assertSame( 2, $counts['translations'] );
		$this->assertSame( 1799995000, $counts['last_checked'] );
		$this->assertSame( 0, UpdateCounter::extensions( 'garbage' )['count'] );
	}

	public function test_up_to_date_site(): void {
		$result = $this->check( new FakeUpdates() )->run();
		$this->assertSame( Status::OK, $result->status );
		$this->assertSame( 'Everything is up to date.', $result->message );
		$this->assertCount( 4, $result->samples, 'One pending sample per type, no last checked without data' );
	}

	public function test_pending_updates(): void {
		$probe          = new FakeUpdates();
		$probe->core    = $this->core( 'upgrade', '6.8' );
		$probe->plugins = $this->plugins( array( 'a/a.php' => '2.0' ), 3 );
		$result         = $this->check( $probe )->run();

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'WordPress 6.8 is available; 1 plugin updates pending (limit 1)', $result->message );
		$this->assertSame( '6.8', $result->details['core']['version'] );
		$this->assertSame( array( 'a/a.php' => '2.0' ), $result->details['plugins'] );
		$this->assertSame( 3, $result->details['translations'] );

		$by_type = array();
		foreach ( $result->samples as $sample ) {
			if ( 'updates_pending' === $sample->name ) {
				$by_type[ $sample->labels['type'] ] = $sample->value;
			}
		}
		$this->assertSame( array( 'core' => 1.0, 'plugin' => 1.0, 'theme' => 0.0, 'translation' => 3.0 ), $by_type );
	}

	public function test_many_plugin_updates_are_critical(): void {
		$probe          = new FakeUpdates();
		$updates        = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$updates[ "p{$i}/p.php" ] = '1.1';
		}
		$probe->plugins = $this->plugins( $updates );
		$this->assertSame( Status::CRITICAL, $this->check( $probe )->run()->status );
	}
}
