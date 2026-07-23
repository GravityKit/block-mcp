<?php
/**
 * Abilities API coverage for the create-pattern ability, including gate
 * parity with its REST twin's dedicated permission callback
 * (REST_Controller::check_create_pattern_permissions()).
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Abilities_Registry;
use GravityKit\BlockMCP\Block_Abilities;
use GravityKit\BlockMCP\Tool_Executor;
use GravityKit\BlockMCP\Yoast_Bridge;

class CreatePatternAbilityTest extends RestControllerTestCase {

	/**
	 * Registration defaults to opt-in (off); enable it the same way
	 * AbilitiesRegistryTest does, before any test can trigger the Abilities
	 * API's first-touch bootstrap. See that file's set_up() docblock.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
	}

	/**
	 * create-pattern registers as an ability.
	 */
	public function test_create_pattern_ability_is_registered() {
		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		$this->assertContains( 'gk-block-mcp/create-pattern', $registry->get_ability_ids() );
	}

	/**
	 * Executing the ability with an editor (who holds both edit_posts and
	 * publish_posts, hence wp_block's create_posts cap) creates a real
	 * synced wp_block post — proving Tool_Executor dispatches to
	 * Pattern_Manager::create_pattern() instead of 400ing "Unknown Block MCP
	 * tool", and that the permission callback doesn't over-deny a capable
	 * actor.
	 */
	public function test_create_pattern_ability_creates_synced_pattern() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/create-pattern' )->execute(
			array(
				'title'   => 'Ability Pattern',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$post = get_post( $result['pattern_id'] );
		$this->assertSame( 'wp_block', $post->post_type );
		$this->assertSame( 'Ability Pattern', $post->post_title );
	}

	/**
	 * The base read permission branch denies a subscriber outright (lacks
	 * edit_posts), matching every other write ability's first-half check.
	 */
	public function test_create_pattern_ability_denies_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/create-pattern' )->execute(
			array(
				'title'   => 'Should Not Land',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Gate parity, the actual bug this issue fixes: a Contributor has
	 * edit_posts (passes the base check_permissions() half of the REST
	 * route's permission callback) but not publish_posts — which is what
	 * the wp_block post type's create_posts capability maps to — so the
	 * REST route denies them. A manifest permission of plain 'edit_post'
	 * would let this exact actor through the Abilities path even though
	 * POST /patterns denies the identical request; the ability must deny
	 * them too, via the same dedicated check.
	 */
	public function test_create_pattern_ability_denies_contributor_without_publish_posts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'sanity: contributor must have edit_posts' );
		$this->assertFalse( current_user_can( 'publish_posts' ), 'sanity: contributor must NOT have publish_posts' );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/create-pattern' )->execute(
			array(
				'title'   => 'Should Not Land',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertWPError( $result, 'a Contributor lacking publish_posts must be denied, matching the REST route' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Mirror of the REST-level dedicated permission callback exercised
	 * directly: REST_Controller::check_create_pattern_permissions() must
	 * deny the same Contributor the ability path denies, proving both
	 * surfaces share one gate rather than two independently-maintained ones
	 * that could drift.
	 */
	public function test_check_create_pattern_permissions_denies_same_contributor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$result = $this->controller->check_create_pattern_permissions();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
	}

	/**
	 * create-pattern is annotated non-readonly (it mutates) and
	 * non-destructive (it only adds a new pattern, matching
	 * src/tools/patterns.ts's destructiveHint: false).
	 */
	public function test_create_pattern_ability_annotations() {
		$meta = wp_get_ability( 'gk-block-mcp/create-pattern' )->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'], 'create-pattern only adds a pattern' );
	}
}
