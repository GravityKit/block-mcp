<?php
/**
 * Decorator over the live test $wpdb that forces is_mysql on and scripts the
 * GET_LOCK result.
 *
 * The rate-limiter's advisory-lock path is gated on $wpdb->is_mysql, which is
 * false under the SQLite harness, so the GET_LOCK / RELEASE_LOCK SQL never runs
 * in CI. This double reports is_mysql true and returns a scripted value for
 * GET_LOCK while delegating everything else (transient reads/writes and their
 * option queries) to the real $wpdb, so check_rate_limit's lock branch can be
 * exercised without a real MySQL server. Captured holds every GET_LOCK /
 * RELEASE_LOCK statement issued, so a test can assert the lock was acquired and
 * released.
 *
 * @package GravityKit\BlockMCP\Tests
 */

namespace GravityKit\BlockMCP\Tests;

class RateLimitLockWpdbDouble {

	/**
	 * Forces Block_Writer's MySQL gate on.
	 *
	 * @var bool
	 */
	public $is_mysql = true;

	/**
	 * Every GET_LOCK / RELEASE_LOCK statement passed through this double.
	 *
	 * @var string[]
	 */
	public $captured = array();

	/**
	 * The real wpdb the harness built.
	 *
	 * @var \wpdb
	 */
	private $real;

	/**
	 * Scripted GET_LOCK return: 1 acquired, 0 timeout, null error.
	 *
	 * @var int|null
	 */
	private $lock_result;

	/**
	 * @param \wpdb    $real        Real wpdb to delegate to.
	 * @param int|null $lock_result Value GET_LOCK should report.
	 */
	public function __construct( $real, $lock_result ) {
		$this->real        = $real;
		$this->lock_result = $lock_result;
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->real->$name;
	}

	/**
	 * @param string $name  Property name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function __set( $name, $value ) {
		$this->real->$name = $value;
	}

	/**
	 * @param string  $name Method name.
	 * @param mixed[] $args Arguments.
	 * @return mixed
	 */
	public function __call( $name, $args ) {
		return $this->real->$name( ...$args );
	}

	/**
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return $this->real->prepare( $query, ...$args );
	}

	/**
	 * Intercept the GET_LOCK probe; delegate every other read.
	 *
	 * @param string|null $query Query.
	 * @param int         $x     Column offset.
	 * @param int         $y     Row offset.
	 * @return mixed
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		if ( is_string( $query ) && false !== strpos( $query, 'GET_LOCK' ) ) {
			$this->captured[] = $query;
			return $this->lock_result;
		}
		return $this->real->get_var( $query, $x, $y );
	}

	/**
	 * Swallow the RELEASE_LOCK statement; delegate every other query.
	 *
	 * @param string $query Query.
	 * @return int|bool
	 */
	public function query( $query ) {
		if ( is_string( $query ) && false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			$this->captured[] = $query;
			return 1;
		}
		return $this->real->query( $query );
	}
}
