<?php
/**
 * Shared base for REST_Controller integration tests.
 *
 * Eliminates the 9-collaborator wire-up that previously lived in every
 * `tests/REST/*` file (RestSummaryTest, PatternsRefreshAuthTest,
 * WriteHandlerErrorEnvelopeTest, ...). Subclasses get a ready-to-use
 * `$this->controller` plus the `$this->crud` / `$this->mutator` from
 * BlockApiTestCase, so the collaborator graph is built once.
 *
 * Subclasses that override `set_up()` MUST call `parent::set_up()` first.
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Block_Registry;
use GravityKit\BlockAPI\Media_Manager;
use GravityKit\BlockAPI\Pattern_Manager;
use GravityKit\BlockAPI\Post_Manager;
use GravityKit\BlockAPI\Preferences;
use GravityKit\BlockAPI\REST_Controller;
use GravityKit\BlockAPI\Term_Manager;

abstract class RestControllerTestCase extends BlockApiTestCase {

	/**
	 * Fully-wired REST_Controller under test.
	 *
	 * @var REST_Controller
	 */
	protected $controller;

	public function set_up(): void {
		parent::set_up();

		$preferences = new Preferences();
		$inventory   = new Block_Inventory();

		$this->controller = new REST_Controller(
			new Block_Registry( $preferences, $inventory ),
			new Pattern_Manager( $preferences ),
			$this->crud,
			$inventory,
			$this->mutator,
			new Post_Manager( $this->crud ),
			new Term_Manager(),
			new Media_Manager(),
			$preferences
		);
	}

	/**
	 * Invoke a private/protected method on the controller via reflection.
	 *
	 * PHP 8.1+ makes setAccessible() a no-op for non-public methods, but
	 * the plugin's documented minimum is 7.4 — keep the explicit call so
	 * the suite still works against the lower bound.
	 *
	 * @param string             $method_name Method to call.
	 * @param array<int, mixed>  $args        Positional arguments.
	 *
	 * @return mixed
	 */
	protected function callPrivate( string $method_name, array $args ) {
		$reflection = new \ReflectionClass( REST_Controller::class );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->controller, $args );
	}
}
