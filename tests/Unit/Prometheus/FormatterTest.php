<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Prometheus;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Prometheus\Formatter;
use Saizul\HealthApi\Prometheus\MetricFamily;

final class FormatterTest extends TestCase {

	public function test_renders_help_type_and_samples(): void {
		$family = new MetricFamily( 'wp_health_updates_pending', 'gauge', 'Pending updates by type.' );
		$family->add( 3, array( 'type' => 'plugin' ) );
		$family->add( 0, array( 'type' => 'core' ) );

		$this->assertSame(
			"# HELP wp_health_updates_pending Pending updates by type.\n"
			. "# TYPE wp_health_updates_pending gauge\n"
			. "wp_health_updates_pending{type=\"plugin\"} 3\n"
			. "wp_health_updates_pending{type=\"core\"} 0\n",
			Formatter::render( array( $family ) )
		);
	}

	public function test_output_ends_with_a_line_feed_and_empty_output_is_empty(): void {
		$family = ( new MetricFamily( 'up', 'gauge' ) )->add( 1 );
		$this->assertStringEndsWith( "\n", Formatter::render( array( $family ) ) );
		$this->assertSame( '', Formatter::render( array() ) );
		$this->assertSame( '', Formatter::render( array( new MetricFamily( 'unused', 'gauge', 'No samples.' ) ) ) );
	}

	public function test_help_is_omitted_when_empty(): void {
		$family = ( new MetricFamily( 'up', 'gauge' ) )->add( 1 );
		$this->assertSame( "# TYPE up gauge\nup 1\n", Formatter::render( array( $family ) ) );
	}

	public function test_help_escapes_backslash_and_line_feed_only(): void {
		$this->assertSame( 'Path C:\\\\temp\\nnext "quoted"', Formatter::escape_help( "Path C:\\temp\nnext \"quoted\"" ) );
	}

	public function test_label_values_escape_backslash_quote_and_line_feed(): void {
		$this->assertSame( 'a\\\\b \\"c\\" d\\ne', Formatter::escape_label_value( "a\\b \"c\" d\ne" ) );

		$family = ( new MetricFamily( 'm', 'gauge' ) )->add( 1, array( 'path' => "C:\\wp \"x\"\n" ) );
		$this->assertStringContainsString( 'm{path="C:\\\\wp \\"x\\"\\n"} 1', Formatter::render( array( $family ) ) );
	}

	public function test_labels_are_sorted_and_empty_values_dropped(): void {
		$family = ( new MetricFamily( 'm', 'gauge' ) )->add( 1, array( 'z' => '1', 'a' => '2', 'empty' => '' ) );
		$this->assertStringContainsString( 'm{a="2",z="1"} 1', Formatter::render( array( $family ) ) );
	}

	public function test_utf8_label_values_pass_through(): void {
		$family = ( new MetricFamily( 'm', 'gauge' ) )->add( 1, array( 'site' => 'দোকান' ) );
		$this->assertStringContainsString( 'm{site="দোকান"} 1', Formatter::render( array( $family ) ) );
	}

	/**
	 * @return array<string,array{0:float,1:string}>
	 */
	public function values(): array {
		return array(
			'integer'        => array( 42.0, '42' ),
			'zero'           => array( 0.0, '0' ),
			'negative'       => array( -1.0, '-1' ),
			'fraction'       => array( 0.25, '0.25' ),
			'short decimal'  => array( 0.1, '0.1' ),
			'big integer'    => array( 1731600000.0, '1731600000' ),
			'needs 17'       => array( 0.30000000000000004, '0.30000000000000004' ),
			'nan'            => array( NAN, 'NaN' ),
			'plus infinity'  => array( INF, '+Inf' ),
			'minus infinity' => array( -INF, '-Inf' ),
		);
	}

	/**
	 * @dataProvider values
	 */
	public function test_values( float $value, string $expected ): void {
		$this->assertSame( $expected, Formatter::value( $value ) );
	}

	public function test_exponent_values_are_go_parseable(): void {
		foreach ( array( 0.0000123, 1.5e20, -4.2e-9 ) as $value ) {
			$text = Formatter::value( $value );
			$this->assertMatchesRegularExpression( '/^-?\d(\.\d+)?e[+-]\d+$/', $text );
			$this->assertSame( $value, (float) $text );
		}
	}

	public function test_values_round_trip(): void {
		foreach ( array( 1 / 3, M_PI, 123456.789, 2.5e-300, 0.1 + 0.2 ) as $value ) {
			$this->assertSame( $value, (float) Formatter::value( $value ) );
		}
	}

	public function test_families_with_the_same_name_are_merged_into_one_group(): void {
		$a = ( new MetricFamily( 'm', 'gauge', 'First help.' ) )->add( 1, array( 'check' => 'a' ) );
		$b = ( new MetricFamily( 'other', 'gauge', 'Other.' ) )->add( 5 );
		$c = ( new MetricFamily( 'm', 'gauge', 'Second help.' ) )->add( 2, array( 'check' => 'b' ) );

		$output = Formatter::render( array( $a, $b, $c ) );
		$this->assertSame( 1, substr_count( $output, '# HELP m ' ) );
		$this->assertSame( 1, substr_count( $output, '# TYPE m ' ) );
		$this->assertStringContainsString( "m{check=\"a\"} 1\nm{check=\"b\"} 2\n", $output );
		$this->assertStringContainsString( '# HELP m First help.', $output );
	}

	public function test_type_line_precedes_samples_for_every_family(): void {
		$families = array(
			( new MetricFamily( 'a', 'gauge', 'A.' ) )->add( 1 ),
			( new MetricFamily( 'b', 'counter', 'B.' ) )->add( 2 ),
		);
		$seen_type = array();
		foreach ( explode( "\n", trim( Formatter::render( $families ) ) ) as $line ) {
			if ( 0 === strpos( $line, '# TYPE ' ) ) {
				$seen_type[ explode( ' ', $line )[2] ] = true;
			} elseif ( 0 !== strpos( $line, '#' ) ) {
				$this->assertArrayHasKey( strtok( $line, '{ ' ), $seen_type );
			}
		}
		$this->assertCount( 2, $seen_type );
	}

	public function test_conflicting_types_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		Formatter::render(
			array(
				( new MetricFamily( 'm', 'gauge' ) )->add( 1 ),
				( new MetricFamily( 'm', 'counter' ) )->add( 1, array( 'x' => 'y' ) ),
			)
		);
	}

	public function test_duplicate_label_sets_are_rejected_when_merging(): void {
		$this->expectException( InvalidArgumentException::class );
		Formatter::render(
			array(
				( new MetricFamily( 'm', 'gauge' ) )->add( 1, array( 'a' => '1' ) ),
				( new MetricFamily( 'm', 'gauge' ) )->add( 2, array( 'a' => '1' ) ),
			)
		);
	}

	public function test_output_is_parseable_line_by_line(): void {
		$family = ( new MetricFamily( 'wp_health_x', 'gauge', "Multi\nline \\ help" ) )
			->add( 0.5, array( 'check' => 'db', 'note' => "a\"b\nc" ) )
			->add( NAN, array( 'check' => 'cache' ) );
		$lines  = explode( "\n", Formatter::render( array( $family ) ) );
		$this->assertSame( '', array_pop( $lines ) );
		$pattern = '/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{([a-zA-Z_][a-zA-Z0-9_]*="([^"\\\\\n]|\\\\[\\\\"n])*",?)*\})? (NaN|[+-]Inf|-?[0-9.]+(e[+-]?[0-9]+)?)$/';
		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, '#' ) ) {
				$this->assertMatchesRegularExpression( '/^# (HELP|TYPE) wp_health_x /', $line );
				continue;
			}
			$this->assertMatchesRegularExpression( $pattern, $line );
		}
	}
}
