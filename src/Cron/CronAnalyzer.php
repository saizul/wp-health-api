<?php
/**
 * WP-Cron schedule analysis.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Cron;

/**
 * Counts events and overdue events in a WP-Cron array.
 *
 * The cron array has the shape
 * `[ timestamp => [ hook => [ md5(args) => [ 'schedule' => ..., 'args' => [...] ] ] ], 'version' => 2 ]`.
 * An event is overdue when its timestamp is older than now minus a grace
 * period: WP-Cron only runs on page loads (or a system cron), so events a
 * few minutes late are normal.
 */
final class CronAnalyzer {

	/**
	 * Analyse a cron array.
	 *
	 * @param array<int|string,mixed> $cron Cron array.
	 * @param int                     $now Current time.
	 * @param int                     $grace Seconds an event may be late before it counts as overdue.
	 * @return array{events:int,overdue:int,oldest_overdue_age:int|null,overdue_hooks:array<string,int>,next_run:int|null}
	 */
	public static function analyse( array $cron, int $now, int $grace ): array {
		$result = array(
			'events'             => 0,
			'overdue'            => 0,
			'oldest_overdue_age' => null,
			'overdue_hooks'      => array(),
			'next_run'           => null,
		);
		$cutoff = $now - max( 0, $grace );
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) && ! ctype_digit( (string) $timestamp ) ) {
				continue; // The "version" entry.
			}
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			$timestamp = (int) $timestamp;
			foreach ( $hooks as $hook => $instances ) {
				$count             = is_array( $instances ) ? count( $instances ) : 0;
				$result['events'] += $count;
				if ( 0 === $count ) {
					continue;
				}
				if ( $timestamp < $cutoff ) {
					$result['overdue']               += $count;
					$hook                             = (string) $hook;
					$result['overdue_hooks'][ $hook ] = ( $result['overdue_hooks'][ $hook ] ?? 0 ) + $count;
					$age                              = $now - $timestamp;
					if ( null === $result['oldest_overdue_age'] || $age > $result['oldest_overdue_age'] ) {
						$result['oldest_overdue_age'] = $age;
					}
				}
				if ( null === $result['next_run'] || $timestamp < $result['next_run'] ) {
					$result['next_run'] = $timestamp;
				}
			}
		}
		arsort( $result['overdue_hooks'] );

		return $result;
	}
}
