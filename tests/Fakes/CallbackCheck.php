<?php
/**
 * Check driven by a closure.
 *
 * @package Saizul\HealthApi
 */

declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Fakes;

use Saizul\HealthApi\Check\Check;
use Saizul\HealthApi\CheckResult;

final class CallbackCheck implements Check {
	/** @var string */
	private $id;
	/** @var callable */
	private $callback;
	/** @var int */
	public $runs = 0;

	public function __construct( string $id, callable $callback ) {
		$this->id       = $id;
		$this->callback = $callback;
	}

	public function id(): string {
		return $this->id;
	}

	public function run(): CheckResult {
		++$this->runs;
		return call_user_func( $this->callback, $this->id );
	}
}
