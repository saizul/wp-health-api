<?php
/**
 * Prometheus text exposition format.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Prometheus;

use InvalidArgumentException;

/**
 * Renders metric families in the Prometheus text format, version 0.0.4.
 *
 * Rules applied (https://prometheus.io/docs/instrumenting/exposition_formats/):
 * - lines end with "\n", including the last one;
 * - at most one HELP and one TYPE line per metric, both before its samples;
 * - all samples of a metric form one group;
 * - in HELP text, backslash and line feed are escaped as `\\` and `\n`;
 * - in label values, backslash, double quote and line feed are escaped as
 *   `\\`, `\"` and `\n`;
 * - values are Go-parseable floats, with `NaN`, `+Inf` and `-Inf`.
 */
final class Formatter {

	const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

	/**
	 * Render families. Families with the same name are merged.
	 *
	 * @param MetricFamily[] $families Families.
	 * @throws InvalidArgumentException When two families share a name but not a type, or repeat a label set.
	 */
	public static function render( array $families ): string {
		$merged = array();
		foreach ( $families as $family ) {
			$name = $family->name();
			if ( ! isset( $merged[ $name ] ) ) {
				$merged[ $name ] = new MetricFamily( $name, $family->type(), $family->help() );
			} elseif ( $merged[ $name ]->type() !== $family->type() ) {
				throw new InvalidArgumentException( sprintf( 'Metric "%s" is declared as both %s and %s.', $name, $merged[ $name ]->type(), $family->type() ) );
			}
			foreach ( $family->samples() as $sample ) {
				$merged[ $name ]->add( $sample[1], $sample[0] );
			}
		}

		$lines = array();
		foreach ( $merged as $family ) {
			if ( array() === $family->samples() ) {
				continue;
			}
			if ( '' !== $family->help() ) {
				$lines[] = '# HELP ' . $family->name() . ' ' . self::escape_help( $family->help() );
			}
			$lines[] = '# TYPE ' . $family->name() . ' ' . $family->type();
			foreach ( $family->samples() as $sample ) {
				$lines[] = $family->name() . self::labels( $sample[0] ) . ' ' . self::value( $sample[1] );
			}
		}

		return array() === $lines ? '' : implode( "\n", $lines ) . "\n";
	}

	/**
	 * Escape HELP text.
	 *
	 * @param string $text Text.
	 */
	public static function escape_help( string $text ): string {
		return str_replace( array( '\\', "\n" ), array( '\\\\', '\\n' ), $text );
	}

	/**
	 * Escape a label value.
	 *
	 * @param string $value Value.
	 */
	public static function escape_label_value( string $value ): string {
		return str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $value );
	}

	/**
	 * Render a label set, `{a="1",b="2"}` or an empty string.
	 *
	 * @param array<string,string> $labels Labels.
	 */
	public static function labels( array $labels ): string {
		if ( array() === $labels ) {
			return '';
		}
		$pairs = array();
		foreach ( $labels as $name => $value ) {
			$pairs[] = $name . '="' . self::escape_label_value( (string) $value ) . '"';
		}

		return '{' . implode( ',', $pairs ) . '}';
	}

	/**
	 * Render a sample value.
	 *
	 * Integral values are written without a decimal point; other values use
	 * the shortest of 15 or 17 significant digits that round-trips.
	 *
	 * @param float $value Value.
	 */
	public static function value( float $value ): string {
		if ( is_nan( $value ) ) {
			return 'NaN';
		}
		if ( is_infinite( $value ) ) {
			return $value > 0 ? '+Inf' : '-Inf';
		}
		if ( floor( $value ) === $value && abs( $value ) < 1e15 ) {
			return sprintf( '%.0f', $value );
		}
		$text = sprintf( '%.15g', $value );
		if ( (float) $text !== $value ) {
			$text = sprintf( '%.17g', $value );
		}

		return $text;
	}
}
