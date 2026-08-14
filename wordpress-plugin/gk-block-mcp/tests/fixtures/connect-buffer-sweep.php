<?php
/**
 * Child-process probe for Connect_Page's output-buffer sweep.
 *
 * PHP will not let a test pop or flush-close a buffer opened without the
 * matching handler flags, so a buffer of that kind cannot be created and then
 * cleaned up inside the PHPUnit process. This script creates a real one, runs
 * the production sweep and guard against it, and prints a JSON verdict on
 * stderr — the leaked buffer dies with the process.
 *
 * Usage: php connect-buffer-sweep.php <flags-constant-name>
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

define( 'ABSPATH', __DIR__ );

require_once dirname( __DIR__, 2 ) . '/includes/class-connect-page.php';

/**
 * Exposes the sweep and the guard, and keeps the harness's own buffer open.
 */
class Connect_Buffer_Sweep_Probe extends GravityKit\BlockMCP\Connect_Page {

	/**
	 * Buffer level the sweep must not go below.
	 *
	 * @var int
	 */
	public $floor = 0;

	/**
	 * Run the sweep, then report whether the response reads as clean.
	 *
	 * @return bool
	 */
	public function sweep_then_check() {
		$this->discard_output_buffers();

		return $this->response_is_clean();
	}

	/**
	 * @return int
	 */
	protected function output_buffer_floor() {
		return $this->floor;
	}

	/**
	 * The CLI SAPI has already printed, which is not what is under test here.
	 *
	 * @return bool
	 */
	protected function response_already_started() {
		return false;
	}
}

$flag_names = array(
	'none'      => 0,
	'cleanable' => PHP_OUTPUT_HANDLER_CLEANABLE,
	'flushable' => PHP_OUTPUT_HANDLER_FLUSHABLE,
);

$requested = isset( $argv[1] ) ? $argv[1] : 'none';
$flags     = isset( $flag_names[ $requested ] ) ? $flag_names[ $requested ] : 0;

$probe = new Connect_Buffer_Sweep_Probe();

// Swallow the stray bytes at shutdown so they never reach the harness's stdout.
ob_start( function () { return ''; } );
$probe->floor = ob_get_level();

ob_start( function ( $buffer ) { return $buffer; }, 0, $flags );
echo 'STRAY';

$clean = $probe->sweep_then_check();

fwrite(
	STDERR,
	wp_json_encode_fallback(
		array(
			'flags'   => $requested,
			'clean'   => $clean,
			'pending' => buffered_bytes_above( $probe->floor ),
		)
	)
);

/**
 * Total bytes still held in buffers above $floor.
 *
 * @param int $floor Buffer level the sweep stopped at.
 *
 * @return int
 */
function buffered_bytes_above( $floor ) {
	$total = 0;

	foreach ( ob_get_status( true ) as $index => $status ) {
		if ( $index >= $floor ) {
			$total += (int) ( isset( $status['buffer_used'] ) ? $status['buffer_used'] : 0 );
		}
	}

	return $total;
}

/**
 * json_encode(), named so it does not shadow a WordPress function.
 *
 * @param array $data Data to encode.
 *
 * @return string
 */
function wp_json_encode_fallback( array $data ) {
	return (string) json_encode( $data );
}
