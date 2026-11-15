<?php
/**
 * Counts pending updates from the WordPress update transients.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Updates;

/**
 * Reads the update_core, update_plugins and update_themes site transients.
 *
 * These are stored by WordPress after its own scheduled update checks, so
 * reading them never causes a remote request. Values can be objects
 * (normal) or arrays (some object caches), so both are accepted.
 */
final class UpdateCounter {

	/**
	 * Core update details.
	 *
	 * @param mixed  $transient The update_core transient.
	 * @param string $installed Installed WordPress version.
	 * @return array{available:bool,version:string|null,last_checked:int|null}
	 */
	public static function core( $transient, string $installed ): array {
		$data   = self::to_array( $transient );
		$result = array(
			'available'    => false,
			'version'      => null,
			'last_checked' => self::timestamp( $data['last_checked'] ?? null ),
		);
		foreach ( (array) ( $data['updates'] ?? array() ) as $offer ) {
			$offer    = self::to_array( $offer );
			$response = (string) ( $offer['response'] ?? '' );
			$version  = (string) ( $offer['current'] ?? $offer['version'] ?? '' );
			if ( 'upgrade' !== $response || '' === $version ) {
				continue;
			}
			if ( '' !== $installed && version_compare( $version, $installed, '<=' ) ) {
				continue;
			}
			if ( null === $result['version'] || version_compare( $version, $result['version'], '>' ) ) {
				$result['available'] = true;
				$result['version']   = $version;
			}
		}

		return $result;
	}

	/**
	 * Plugin or theme update details.
	 *
	 * @param mixed $transient The update_plugins or update_themes transient.
	 * @return array{count:int,items:array<string,string>,translations:int,last_checked:int|null}
	 *         Items map the plugin file or theme slug to the new version.
	 */
	public static function extensions( $transient ): array {
		$data  = self::to_array( $transient );
		$items = array();
		foreach ( (array) ( $data['response'] ?? array() ) as $key => $offer ) {
			$offer                  = self::to_array( $offer );
			$items[ (string) $key ] = (string) ( $offer['new_version'] ?? '' );
		}
		ksort( $items );

		return array(
			'count'        => count( $items ),
			'items'        => $items,
			'translations' => is_array( $data['translations'] ?? null ) ? count( $data['translations'] ) : 0,
			'last_checked' => self::timestamp( $data['last_checked'] ?? null ),
		);
	}

	/**
	 * Object or array to array.
	 *
	 * @param mixed $value Value.
	 * @return array<string|int,mixed>
	 */
	private static function to_array( $value ): array {
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Positive integer timestamp or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function timestamp( $value ): ?int {
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}
}
