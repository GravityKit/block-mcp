# Docs Lifecycle Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add four MCP tools (`create_post`, `update_post`, `list_terms`, `upload_media`) so block-mcp owns the full docs authoring lifecycle without requiring sibling MCPs.

**Architecture:** Three new PHP service classes (`Post_Manager`, `Term_Manager`, `Media_Manager`) wired into the existing `REST_Controller` via four new `gk-block-api/v1` routes. Three new TypeScript tool modules (`posts.ts`, `terms.ts`, `media.ts`) plus extensions to `client.ts` and `types.ts`. End-to-end exercised against gkclone (wp-env, port 7701).

**Tech Stack:** PHP 7.4+ (WordPress 6.0+, PHPUnit), TypeScript ES2022 (Node 20, Vitest, axios, esbuild bundle to CJS).

**Spec:** `docs/specs/2026-04-27-docs-lifecycle-tools.md` — read before starting; the field rules and error codes there are authoritative.

**Repo paths in this plan are relative to** `/Users/zackkatz/Dropbox/MonoKit/MCPs/block-mcp/`.

---

## Phase 1 — PHP: Post_Manager (create + update)

### Task 1: Post_Manager class skeleton (no bootstrap wiring yet)

> Bootstrap wiring for all three managers happens together in Task 7, after the manager classes exist. Touching the bootstrap here would break the existing `RestSummaryTest` because the autoloader would fail on still-missing `Term_Manager` / `Media_Manager` references (the silent catch swallows the error but routes don't register).

**Files:**
- Create: `wordpress-plugin/gk-block-api/includes/class-post-manager.php`

- [ ] **Step 1: Create the class skeleton.**

```php
<?php
/**
 * Post-level CRUD (metadata + status). Block-content edits stay on
 * the existing per-block endpoints.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Manager {

	/**
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * @var Block_CRUD
	 */
	private $block_crud;

	public function __construct( Preferences $preferences, Block_CRUD $block_crud ) {
		$this->preferences = $preferences;
		$this->block_crud  = $block_crud;
	}

	/**
	 * @param array $args See spec §3.1.
	 * @return array|\WP_Error
	 */
	public function create_post( array $args ) {
		return new \WP_Error( 'not_implemented', 'create_post not implemented yet' );
	}

	/**
	 * @param int   $post_id
	 * @param array $args See spec §3.2.
	 * @return array|\WP_Error
	 */
	public function update_post( $post_id, array $args ) {
		return new \WP_Error( 'not_implemented', 'update_post not implemented yet' );
	}

	/**
	 * Built-in allow-list when the option is unset.
	 * @return string[]
	 */
	private function default_allowed_post_types() {
		$built_in = array( 'post', 'page' );
		$rest_enabled = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
			$rest_enabled[] = $type;
		}
		return array_values( array_unique( array_merge( $built_in, $rest_enabled ) ) );
	}
}
```

Write to `wordpress-plugin/gk-block-api/includes/class-post-manager.php`.

- [ ] **Step 2: Run existing PHP tests to confirm nothing regressed.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml
```

Expected: existing 162 tests still green. Bootstrap unchanged.

- [ ] **Step 3: Commit.**

```bash
git add wordpress-plugin/gk-block-api/includes/class-post-manager.php
git commit -m "feat(block-api): scaffold Post_Manager class"
```

---

### Task 2: PHPUnit — failing test for create_post happy path

**Files:**
- Create: `wordpress-plugin/gk-block-api/tests/PostManagerTest.php`

- [ ] **Step 1: Read existing test conventions.**

Open `wordpress-plugin/gk-block-api/tests/BlockCrudTest.php` and confirm the bootstrap pattern (`bootstrap.php` instantiates dependencies; tests extend `WP_UnitTestCase`).

- [ ] **Step 2: Write the test file.**

```php
<?php
namespace GravityKit\BlockAPI\Tests;

use GravityKit\BlockAPI\Post_Manager;
use GravityKit\BlockAPI\Preferences;
use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;

class PostManagerTest extends \WP_UnitTestCase {

	/** @var Post_Manager */
	private $post_manager;

	public function set_up() {
		parent::set_up();
		$preferences  = new Preferences();
		$safety       = new Block_Safety();
		$transformer  = new HTML_Transformer();
		$block_crud   = new Block_CRUD( $preferences, $safety, $transformer );
		$this->post_manager = new Post_Manager( $preferences, $block_crud );

		// Force capability for editor user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
	}

	public function test_create_post_with_title_only() {
		$result = $this->post_manager->create_post( array( 'title' => 'Hello' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'Hello', $result['title'] );
		$this->assertSame( 'post', $result['post_type'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( get_permalink( $result['id'] ), $result['permalink'] );
	}

	public function test_create_post_rejects_missing_title() {
		$result = $this->post_manager->create_post( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_title', $result->get_error_code() );
	}

	public function test_create_post_rejects_invalid_status_trash() {
		$result = $this->post_manager->create_post( array(
			'title'  => 'X',
			'status' => 'trash',
		) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}

	public function test_create_post_rejects_invalid_post_type() {
		$result = $this->post_manager->create_post( array(
			'title'     => 'X',
			'post_type' => 'nope_xyz',
		) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}
}
```

- [ ] **Step 3: Run; expect failures.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml --filter PostManagerTest
```

Expected: All four tests **fail** because `create_post` returns the `not_implemented` `WP_Error`.

- [ ] **Step 4: Commit the failing tests.**

```bash
git add wordpress-plugin/gk-block-api/tests/PostManagerTest.php
git commit -m "test(block-api): failing tests for Post_Manager::create_post"
```

---

### Task 3: Implement create_post — happy path

**Files:**
- Modify: `wordpress-plugin/gk-block-api/includes/class-post-manager.php`

- [ ] **Step 1: Replace `create_post()` with the full implementation.**

```php
public function create_post( array $args ) {
	if ( empty( $args['title'] ) || ! is_string( $args['title'] ) ) {
		return new \WP_Error( 'missing_title', __( 'A non-empty "title" is required.', 'gk-block-api' ), array( 'status' => 400 ) );
	}

	$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
	if ( ! in_array( $post_type, $this->default_allowed_post_types(), true ) ) {
		return new \WP_Error( 'invalid_post_type', sprintf( 'Post type "%s" is not allowed.', $post_type ), array( 'status' => 400 ) );
	}

	$pt_object = get_post_type_object( $post_type );
	$cap       = $pt_object && isset( $pt_object->cap->create_posts ) ? $pt_object->cap->create_posts : 'edit_posts';
	if ( ! current_user_can( $cap ) ) {
		return new \WP_Error( 'rest_cannot_create', __( 'Sorry, you are not allowed to create posts of this type.', 'gk-block-api' ), array( 'status' => 403 ) );
	}

	$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft';
	$allowed_statuses = array( 'draft', 'pending', 'private', 'publish', 'future' );
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		return new \WP_Error( 'invalid_status', sprintf( 'Status "%s" is not allowed on create. Use update_post for trash transitions.', $status ), array( 'status' => 400 ) );
	}

	if ( 'publish' === $status && ! current_user_can( $pt_object && isset( $pt_object->cap->publish_posts ) ? $pt_object->cap->publish_posts : 'publish_posts' ) ) {
		return new \WP_Error( 'rest_cannot_publish', __( 'You cannot publish posts of this type.', 'gk-block-api' ), array( 'status' => 403 ) );
	}

	if ( isset( $args['content'], $args['blocks'] ) ) {
		return new \WP_Error( 'mutually_exclusive', '"content" and "blocks" are mutually exclusive.', array( 'status' => 400 ) );
	}

	$warnings = array();
	$content  = '';
	if ( ! empty( $args['blocks'] ) && is_array( $args['blocks'] ) ) {
		$validation = $this->validate_blocks_for_insert( $args['blocks'] );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$warnings = $validation['warnings'];
		$content  = serialize_blocks( $validation['blocks'] );
	} elseif ( ! empty( $args['content'] ) ) {
		$content = wp_kses_post( $args['content'] );
	}

	$postarr = array(
		'post_type'    => $post_type,
		'post_status'  => $status,
		'post_title'   => sanitize_text_field( $args['title'] ),
		'post_content' => $content,
	);

	if ( isset( $args['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $args['slug'] );
	}
	if ( isset( $args['excerpt'] ) ) {
		$postarr['post_excerpt'] = sanitize_text_field( $args['excerpt'] );
	}
	if ( isset( $args['parent'] ) ) {
		$parent_validation = $this->validate_parent( (int) $args['parent'], $post_type, 0 );
		if ( is_wp_error( $parent_validation ) ) {
			return $parent_validation;
		}
		$postarr['post_parent'] = (int) $args['parent'];
	}
	if ( isset( $args['date'] ) ) {
		$postarr['post_date'] = sanitize_text_field( $args['date'] );
	}
	if ( isset( $args['menu_order'] ) ) {
		$postarr['menu_order'] = (int) $args['menu_order'];
	}
	if ( isset( $args['comment_status'] ) ) {
		$postarr['comment_status'] = in_array( $args['comment_status'], array( 'open', 'closed' ), true ) ? $args['comment_status'] : 'closed';
	}
	if ( isset( $args['ping_status'] ) ) {
		$postarr['ping_status'] = in_array( $args['ping_status'], array( 'open', 'closed' ), true ) ? $args['ping_status'] : 'closed';
	}
	if ( isset( $args['author'] ) ) {
		$author_id = (int) $args['author'];
		if ( $author_id !== get_current_user_id() && ! current_user_can( $pt_object && isset( $pt_object->cap->edit_others_posts ) ? $pt_object->cap->edit_others_posts : 'edit_others_posts' ) ) {
			return new \WP_Error( 'rest_cannot_assign_author', __( 'You cannot assign authorship to other users.', 'gk-block-api' ), array( 'status' => 403 ) );
		}
		$postarr['post_author'] = $author_id;
	}
	if ( isset( $args['featured_media'] ) ) {
		$fm = (int) $args['featured_media'];
		if ( $fm > 0 ) {
			$mime = get_post_mime_type( $fm );
			if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
				return new \WP_Error( 'invalid_featured_media', 'featured_media is not a valid image attachment.', array( 'status' => 400 ) );
			}
		}
	}

	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( isset( $args['featured_media'] ) ) {
		$fm = (int) $args['featured_media'];
		if ( $fm > 0 ) {
			set_post_thumbnail( $post_id, $fm );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}

	$term_assignment = $this->assign_terms( $post_id, $post_type, $args );
	if ( is_wp_error( $term_assignment ) ) {
		wp_delete_post( $post_id, true );
		return $term_assignment;
	}

	$revisions   = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
	$revision_id = $revisions ? (int) array_values( $revisions )[0]->ID : null;

	$post = get_post( $post_id );

	return array(
		'success'            => true,
		'id'                 => $post_id,
		'post_type'          => $post->post_type,
		'status'             => $post->post_status,
		'title'              => $post->post_title,
		'slug'               => $post->post_name,
		'permalink'          => get_permalink( $post ),
		'edit_link'          => get_edit_post_link( $post, 'raw' ),
		'before_revision_id' => null,
		'revision_id'        => $revision_id,
		'warnings'           => $warnings,
	);
}

/**
 * Validate blocks against registry + preferences.
 *
 * @param array $blocks
 * @return array{blocks:array,warnings:array}|\WP_Error
 */
private function validate_blocks_for_insert( array $blocks ) {
	$warnings = array();
	$registry = \WP_Block_Type_Registry::get_instance();
	foreach ( $blocks as $block ) {
		$name = isset( $block['name'] ) ? (string) $block['name'] : '';
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_block', 'Each block requires a "name".', array( 'status' => 400 ) );
		}
		if ( ! $registry->is_registered( $name ) ) {
			return new \WP_Error( 'unregistered_block', sprintf( 'Block "%s" is not registered.', $name ), array( 'status' => 400 ) );
		}
		$tier = $this->preferences->get_block_score( $name );
		if ( 'legacy' === $tier['tier'] ) {
			return new \WP_Error( 'legacy_block', sprintf( 'Block "%s" is legacy and cannot be inserted.', $name ), array( 'status' => 400 ) );
		}
		if ( 'avoid' === $tier['tier'] ) {
			$warnings[] = array(
				'block'                 => $name,
				'message'               => sprintf( 'Block "%s" is on the avoid list.', $name ),
				'suggested_replacement' => $this->preferences->get_replacement( $name ),
			);
		}
	}
	$normalized = array_map(
		function ( $block ) {
			return array(
				'blockName'    => $block['name'],
				'attrs'        => isset( $block['attributes'] ) && is_array( $block['attributes'] ) ? $block['attributes'] : array(),
				'innerBlocks'  => isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(),
				'innerHTML'    => isset( $block['innerHTML'] ) ? wp_kses_post( $block['innerHTML'] ) : '',
				'innerContent' => isset( $block['innerContent'] ) ? $block['innerContent'] : array(),
			);
		},
		$blocks
	);
	return array( 'blocks' => $normalized, 'warnings' => $warnings );
}

/**
 * @param int    $parent_id
 * @param string $post_type
 * @param int    $self_id   The post being updated; 0 on create.
 * @return true|\WP_Error
 */
private function validate_parent( $parent_id, $post_type, $self_id ) {
	if ( 0 === $parent_id ) {
		return true;
	}
	$pt_object = get_post_type_object( $post_type );
	if ( ! $pt_object || empty( $pt_object->hierarchical ) ) {
		return new \WP_Error( 'invalid_parent', sprintf( '"%s" is not hierarchical; parent cannot be set.', $post_type ), array( 'status' => 400 ) );
	}
	if ( $self_id && $parent_id === $self_id ) {
		return new \WP_Error( 'cycle_parent', 'A post cannot be its own parent.', array( 'status' => 400 ) );
	}
	$parent = get_post( $parent_id );
	if ( ! $parent || $parent->post_type !== $post_type ) {
		return new \WP_Error( 'invalid_parent', sprintf( 'Parent post %d does not exist or is not of type "%s".', $parent_id, $post_type ), array( 'status' => 400 ) );
	}
	return true;
}

/**
 * @param int    $post_id
 * @param string $post_type
 * @param array  $args
 * @return true|\WP_Error
 */
private function assign_terms( $post_id, $post_type, array $args ) {
	$assignments = array();
	if ( array_key_exists( 'categories', $args ) ) {
		$assignments['category'] = (array) $args['categories'];
	}
	if ( array_key_exists( 'tags', $args ) ) {
		$assignments['post_tag'] = (array) $args['tags'];
	}
	if ( ! empty( $args['terms'] ) && is_array( $args['terms'] ) ) {
		foreach ( $args['terms'] as $tax => $ids ) {
			$assignments[ sanitize_key( $tax ) ] = (array) $ids;
		}
	}

	foreach ( $assignments as $taxonomy => $ids ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $taxonomy, get_object_taxonomies( $post_type ), true ) ) {
			return new \WP_Error( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" is not registered for post type "%s".', $taxonomy, $post_type ), array( 'status' => 400 ) );
		}
		$ids = array_map( 'absint', $ids );
		foreach ( $ids as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error( 'invalid_term', sprintf( 'Term %d does not exist in taxonomy "%s".', $term_id, $taxonomy ), array( 'status' => 400 ) );
			}
		}
		$result = wp_set_post_terms( $post_id, $ids, $taxonomy, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	return true;
}
```

- [ ] **Step 2: Run tests; expect pass.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml --filter PostManagerTest
```

Expected: 4 passing.

- [ ] **Step 3: Add coverage tests (terms, parent, blocks).**

Append to `PostManagerTest.php`:

```php
public function test_create_post_with_categories() {
	$cat = $this->factory->category->create( array( 'name' => 'Docs' ) );
	$result = $this->post_manager->create_post( array(
		'title'      => 'X',
		'categories' => array( $cat ),
	) );
	$this->assertIsArray( $result );
	$cats = wp_get_post_terms( $result['id'], 'category', array( 'fields' => 'ids' ) );
	$this->assertContains( $cat, $cats );
}

public function test_create_post_rejects_unknown_category() {
	$result = $this->post_manager->create_post( array(
		'title'      => 'X',
		'categories' => array( 999999 ),
	) );
	$this->assertWPError( $result );
	$this->assertSame( 'invalid_term', $result->get_error_code() );
}

public function test_create_post_with_blocks_input() {
	$result = $this->post_manager->create_post( array(
		'title'  => 'X',
		'blocks' => array(
			array( 'name' => 'core/heading', 'attributes' => array( 'level' => 2 ), 'innerHTML' => '<h2>Hi</h2>' ),
		),
	) );
	$this->assertIsArray( $result );
	$post = get_post( $result['id'] );
	$this->assertStringContainsString( '<!-- wp:heading', $post->post_content );
}

public function test_create_post_rejects_legacy_block() {
	$result = $this->post_manager->create_post( array(
		'title'  => 'X',
		'blocks' => array( array( 'name' => 'ugb/heading' ) ),
	) );
	$this->assertWPError( $result );
	$this->assertSame( 'legacy_block', $result->get_error_code() );
}

public function test_create_post_rejects_parent_on_non_hierarchical() {
	$other = $this->factory->post->create();
	$result = $this->post_manager->create_post( array(
		'title'  => 'X',
		'parent' => $other,
	) );
	$this->assertWPError( $result );
	$this->assertSame( 'invalid_parent', $result->get_error_code() );
}

public function test_create_page_with_valid_parent() {
	$parent = $this->factory->post->create( array( 'post_type' => 'page' ) );
	$result = $this->post_manager->create_post( array(
		'title'     => 'Child',
		'post_type' => 'page',
		'parent'    => $parent,
	) );
	$this->assertIsArray( $result );
	$this->assertSame( $parent, get_post( $result['id'] )->post_parent );
}
```

Run, expect all green.

- [ ] **Step 4: Commit.**

```bash
git add wordpress-plugin/gk-block-api/includes/class-post-manager.php \
        wordpress-plugin/gk-block-api/tests/PostManagerTest.php
git commit -m "feat(block-api): Post_Manager::create_post with terms, parent, blocks input"
```

---

### Task 4: Implement update_post

**Files:**
- Modify: `wordpress-plugin/gk-block-api/includes/class-post-manager.php`
- Modify: `wordpress-plugin/gk-block-api/tests/PostManagerTest.php`

- [ ] **Step 1: Add failing tests.**

Append to `PostManagerTest.php`:

```php
public function test_update_post_changes_title() {
	$id = $this->factory->post->create( array( 'post_title' => 'Old', 'post_status' => 'draft' ) );
	$result = $this->post_manager->update_post( $id, array( 'title' => 'New' ) );
	$this->assertIsArray( $result );
	$this->assertSame( 'New', get_post( $id )->post_title );
}

public function test_update_post_publish_transitions() {
	$id = $this->factory->post->create( array( 'post_status' => 'draft' ) );
	$result = $this->post_manager->update_post( $id, array( 'status' => 'publish' ) );
	$this->assertIsArray( $result );
	$this->assertTrue( $result['transitioned_to_publish'] );
	$this->assertSame( 'publish', get_post( $id )->post_status );
}

public function test_update_post_to_trash_uses_wp_trash_post() {
	$id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
	$result = $this->post_manager->update_post( $id, array( 'status' => 'trash' ) );
	$this->assertIsArray( $result );
	$this->assertSame( 'trash', get_post( $id )->post_status );
}

public function test_update_post_untrash() {
	$id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
	wp_trash_post( $id );
	$this->assertSame( 'trash', get_post( $id )->post_status );
	$result = $this->post_manager->update_post( $id, array( 'status' => 'draft' ) );
	$this->assertIsArray( $result );
	$this->assertSame( 'draft', get_post( $id )->post_status );
}

public function test_update_post_404_for_missing() {
	$result = $this->post_manager->update_post( 999999, array( 'title' => 'X' ) );
	$this->assertWPError( $result );
	$this->assertSame( 'post_not_found', $result->get_error_code() );
}

public function test_update_post_cycle_parent_rejected() {
	$id = $this->factory->post->create( array( 'post_type' => 'page' ) );
	$result = $this->post_manager->update_post( $id, array( 'parent' => $id ) );
	$this->assertWPError( $result );
	$this->assertSame( 'cycle_parent', $result->get_error_code() );
}
```

Run; expect failures (returns `not_implemented`).

- [ ] **Step 2: Implement `update_post`.**

Replace the stub with:

```php
public function update_post( $post_id, array $args ) {
	$post_id = absint( $post_id );
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return new \WP_Error( 'post_not_found', sprintf( 'Post %d does not exist.', $post_id ), array( 'status' => 404 ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new \WP_Error( 'rest_cannot_edit', __( 'You cannot edit this post.', 'gk-block-api' ), array( 'status' => 403 ) );
	}

	$pt_object = get_post_type_object( $post->post_type );
	$before_revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
	$before_rev_id    = $before_revisions ? (int) array_values( $before_revisions )[0]->ID : null;

	$transitioned_to_publish = false;
	$untrashed               = false;

	if ( array_key_exists( 'status', $args ) ) {
		$new_status = sanitize_key( $args['status'] );
		$allowed_statuses = array( 'draft', 'pending', 'private', 'publish', 'future', 'trash' );
		if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
			return new \WP_Error( 'invalid_status', sprintf( 'Status "%s" is not allowed.', $new_status ), array( 'status' => 400 ) );
		}
		if ( 'publish' === $new_status && ! current_user_can( $pt_object && isset( $pt_object->cap->publish_posts ) ? $pt_object->cap->publish_posts : 'publish_posts' ) ) {
			return new \WP_Error( 'rest_cannot_publish', __( 'You cannot publish posts of this type.', 'gk-block-api' ), array( 'status' => 403 ) );
		}
		if ( 'trash' === $new_status ) {
			if ( 'trash' !== $post->post_status ) {
				$trashed = wp_trash_post( $post_id );
				if ( ! $trashed ) {
					return new \WP_Error( 'trash_failed', 'wp_trash_post returned false.', array( 'status' => 500 ) );
				}
			}
		} else {
			if ( 'trash' === $post->post_status ) {
				wp_untrash_post( $post_id );
				$untrashed = true;
			}
			if ( 'publish' === $new_status && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'future', 'private' ), true ) ) {
				$transitioned_to_publish = true;
			}
			$args['__post_status'] = $new_status;
		}
	}

	$postarr = array( 'ID' => $post_id );
	if ( array_key_exists( 'title', $args ) ) {
		$postarr['post_title'] = sanitize_text_field( (string) $args['title'] );
	}
	if ( array_key_exists( 'slug', $args ) ) {
		$postarr['post_name'] = sanitize_title( (string) $args['slug'] );
	}
	if ( array_key_exists( 'excerpt', $args ) ) {
		$postarr['post_excerpt'] = sanitize_text_field( (string) $args['excerpt'] );
	}
	if ( array_key_exists( 'date', $args ) ) {
		$postarr['post_date'] = sanitize_text_field( (string) $args['date'] );
	}
	if ( array_key_exists( 'menu_order', $args ) ) {
		$postarr['menu_order'] = (int) $args['menu_order'];
	}
	if ( array_key_exists( 'comment_status', $args ) ) {
		$postarr['comment_status'] = in_array( $args['comment_status'], array( 'open', 'closed' ), true ) ? $args['comment_status'] : 'closed';
	}
	if ( array_key_exists( 'ping_status', $args ) ) {
		$postarr['ping_status'] = in_array( $args['ping_status'], array( 'open', 'closed' ), true ) ? $args['ping_status'] : 'closed';
	}
	if ( array_key_exists( 'parent', $args ) ) {
		$parent_validation = $this->validate_parent( (int) $args['parent'], $post->post_type, $post_id );
		if ( is_wp_error( $parent_validation ) ) {
			return $parent_validation;
		}
		$postarr['post_parent'] = (int) $args['parent'];
	}
	if ( array_key_exists( 'author', $args ) ) {
		$author_id = (int) $args['author'];
		if ( $author_id !== get_current_user_id() && ! current_user_can( $pt_object && isset( $pt_object->cap->edit_others_posts ) ? $pt_object->cap->edit_others_posts : 'edit_others_posts' ) ) {
			return new \WP_Error( 'rest_cannot_assign_author', __( 'You cannot assign authorship to other users.', 'gk-block-api' ), array( 'status' => 403 ) );
		}
		$postarr['post_author'] = $author_id;
	}
	if ( array_key_exists( '__post_status', $args ) ) {
		$postarr['post_status'] = $args['__post_status'];
	}

	if ( count( $postarr ) > 1 ) {
		$updated = wp_update_post( $postarr, true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}

	if ( array_key_exists( 'featured_media', $args ) ) {
		$fm = (int) $args['featured_media'];
		if ( $fm > 0 ) {
			$mime = get_post_mime_type( $fm );
			if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
				return new \WP_Error( 'invalid_featured_media', 'featured_media is not a valid image attachment.', array( 'status' => 400 ) );
			}
			set_post_thumbnail( $post_id, $fm );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}

	$term_assignment = $this->assign_terms( $post_id, $post->post_type, $args );
	if ( is_wp_error( $term_assignment ) ) {
		return $term_assignment;
	}

	$after_revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
	$after_rev_id    = $after_revisions ? (int) array_values( $after_revisions )[0]->ID : null;
	if ( $after_rev_id === $before_rev_id ) {
		$after_rev_id = null;
	}

	$post = get_post( $post_id );

	return array(
		'success'                 => true,
		'id'                      => $post_id,
		'post_type'               => $post->post_type,
		'status'                  => $post->post_status,
		'title'                   => $post->post_title,
		'slug'                    => $post->post_name,
		'permalink'               => get_permalink( $post ),
		'edit_link'               => get_edit_post_link( $post, 'raw' ),
		'transitioned_to_publish' => $transitioned_to_publish,
		'untrashed'               => $untrashed,
		'before_revision_id'      => $before_rev_id,
		'revision_id'             => $after_rev_id,
		'warnings'                => array(),
	);
}
```

- [ ] **Step 3: Run tests; expect all PostManagerTest tests pass.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml --filter PostManagerTest
```

- [ ] **Step 4: Commit.**

```bash
git add wordpress-plugin/gk-block-api/includes/class-post-manager.php \
        wordpress-plugin/gk-block-api/tests/PostManagerTest.php
git commit -m "feat(block-api): Post_Manager::update_post with status transitions and trash handling"
```

---

## Phase 2 — PHP: Term_Manager (list_terms)

### Task 5: Term_Manager class + tests

**Files:**
- Create: `wordpress-plugin/gk-block-api/includes/class-term-manager.php`
- Create: `wordpress-plugin/gk-block-api/tests/TermManagerTest.php`

- [ ] **Step 1: Write failing tests first.**

```php
<?php
namespace GravityKit\BlockAPI\Tests;

use GravityKit\BlockAPI\Term_Manager;

class TermManagerTest extends \WP_UnitTestCase {
	/** @var Term_Manager */
	private $term_manager;

	public function set_up() {
		parent::set_up();
		$this->term_manager = new Term_Manager();
	}

	public function test_list_terms_default_taxonomy() {
		$cat = $this->factory->category->create( array( 'name' => 'Z-test' ) );
		$result = $this->term_manager->list_terms( array() );
		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['taxonomy'] );
		$names = wp_list_pluck( $result['terms'], 'name' );
		$this->assertContains( 'Z-test', $names );
	}

	public function test_list_terms_search() {
		$this->factory->category->create( array( 'name' => 'Documentation' ) );
		$this->factory->category->create( array( 'name' => 'News' ) );
		$result = $this->term_manager->list_terms( array( 'search' => 'Doc' ) );
		$names = wp_list_pluck( $result['terms'], 'name' );
		$this->assertContains( 'Documentation', $names );
		$this->assertNotContains( 'News', $names );
	}

	public function test_list_terms_invalid_taxonomy() {
		$result = $this->term_manager->list_terms( array( 'taxonomy' => 'doesnotexist' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	public function test_list_terms_pagination() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->factory->tag->create( array( 'name' => 'tag-' . $i ) );
		}
		$page1 = $this->term_manager->list_terms( array( 'taxonomy' => 'post_tag', 'per_page' => 2, 'page' => 1, 'orderby' => 'name' ) );
		$page2 = $this->term_manager->list_terms( array( 'taxonomy' => 'post_tag', 'per_page' => 2, 'page' => 2, 'orderby' => 'name' ) );
		$this->assertCount( 2, $page1['terms'] );
		$this->assertCount( 2, $page2['terms'] );
		$this->assertNotEquals( $page1['terms'][0]['id'], $page2['terms'][0]['id'] );
	}
}
```

Run, expect failure (class not found).

- [ ] **Step 2: Implement `Term_Manager`.**

```php
<?php
/**
 * Read-only term listing for taxonomy lookup.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Term_Manager {

	const MAX_PER_PAGE = 200;

	/**
	 * @param array $args
	 * @return array|\WP_Error
	 */
	public function list_terms( array $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), array( 'status' => 400 ) );
		}

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 100;
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$orderby_allowed = array( 'name', 'count', 'term_id', 'slug' );
		$orderby         = isset( $args['orderby'] ) && in_array( $args['orderby'], $orderby_allowed, true ) ? $args['orderby'] : 'name';
		$order           = isset( $args['order'] ) && 'desc' === strtolower( $args['order'] ) ? 'DESC' : 'ASC';

		$query_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => isset( $args['hide_empty'] ) ? (bool) $args['hide_empty'] : false,
			'number'     => $per_page,
			'offset'     => $offset,
			'orderby'    => $orderby,
			'order'      => $order,
		);
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$query_args['search'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( isset( $args['parent'] ) ) {
			$query_args['parent'] = (int) $args['parent'];
		}
		if ( isset( $args['slug'] ) ) {
			$query_args['slug'] = sanitize_title( (string) $args['slug'] );
		}
		if ( ! empty( $args['include'] ) && is_array( $args['include'] ) ) {
			$query_args['include'] = array_map( 'absint', $args['include'] );
		}

		$terms = get_terms( $query_args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args = $query_args;
		unset( $count_args['number'], $count_args['offset'] );
		$total = (int) wp_count_terms( $count_args );

		$formatted = array_map( array( $this, 'format_term' ), $terms );

		return array(
			'taxonomy' => $taxonomy,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'terms'    => $formatted,
		);
	}

	private function format_term( \WP_Term $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'taxonomy'    => $term->taxonomy,
			'link'        => get_term_link( $term ),
		);
	}
}
```

- [ ] **Step 3: Run tests; expect all pass.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml --filter TermManagerTest
```

- [ ] **Step 4: Commit.**

```bash
git add wordpress-plugin/gk-block-api/includes/class-term-manager.php \
        wordpress-plugin/gk-block-api/tests/TermManagerTest.php
git commit -m "feat(block-api): Term_Manager::list_terms with pagination and search"
```

---

## Phase 3 — PHP: Media_Manager (upload_media)

### Task 6: Media_Manager class + tests

**Files:**
- Create: `wordpress-plugin/gk-block-api/includes/class-media-manager.php`
- Create: `wordpress-plugin/gk-block-api/tests/MediaManagerTest.php`
- Create: `wordpress-plugin/gk-block-api/tests/fixtures/sample.png` (1x1 transparent PNG)

- [ ] **Step 1: Add the fixture.**

```bash
# 1x1 transparent PNG, base64-decoded into the file:
mkdir -p wordpress-plugin/gk-block-api/tests/fixtures
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' \
  | base64 --decode > wordpress-plugin/gk-block-api/tests/fixtures/sample.png
file wordpress-plugin/gk-block-api/tests/fixtures/sample.png   # → expect "PNG image data"
```

- [ ] **Step 2: Write failing tests.**

```php
<?php
namespace GravityKit\BlockAPI\Tests;

use GravityKit\BlockAPI\Media_Manager;

class MediaManagerTest extends \WP_UnitTestCase {
	/** @var Media_Manager */
	private $media_manager;

	public function set_up() {
		parent::set_up();
		$this->media_manager = new Media_Manager();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	public function test_upload_via_base64() {
		$png = file_get_contents( __DIR__ . '/fixtures/sample.png' );
		$result = $this->media_manager->upload( array(
			'data_base64' => base64_encode( $png ),
			'filename'    => 'sample.png',
			'alt_text'    => 'sample',
		) );
		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'sample', $result['alt_text'] );
		$this->assertNotEmpty( $result['url'] );
	}

	public function test_upload_rejects_disallowed_mime() {
		$result = $this->media_manager->upload( array(
			'data_base64' => base64_encode( '<?php echo "x"; ?>' ),
			'filename'    => 'shell.php',
		) );
		$this->assertWPError( $result );
		$this->assertSame( 'disallowed_mime', $result->get_error_code() );
	}

	public function test_upload_requires_one_input_mode() {
		$result = $this->media_manager->upload( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_file', $result->get_error_code() );
	}

	public function test_upload_via_multipart_local_file() {
		$src = __DIR__ . '/fixtures/sample.png';
		$tmp = wp_tempnam( 'sample.png' );
		copy( $src, $tmp );
		$_FILES['file'] = array(
			'name'     => 'sample.png',
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		$result = $this->media_manager->upload( array( 'file_field' => 'file', 'alt_text' => 'alt' ) );
		unset( $_FILES['file'] );
		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'alt', $result['alt_text'] );
	}
}
```

Run, expect failure.

- [ ] **Step 3: Implement Media_Manager.**

```php
<?php
/**
 * Media library uploads (multipart, base64, URL sideload).
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Manager {

	const URL_DOWNLOAD_MAX_BYTES = 26214400; // 25 MB

	/**
	 * @param array $args
	 * @return array|\WP_Error
	 */
	public function upload( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$has_multipart = ! empty( $args['file_field'] ) && ! empty( $_FILES[ $args['file_field'] ] );
		$has_url       = ! empty( $args['url'] );
		$has_base64    = ! empty( $args['data_base64'] );

		$mode_count = (int) $has_multipart + (int) $has_url + (int) $has_base64;
		if ( 0 === $mode_count ) {
			return new \WP_Error( 'missing_file', 'Provide one of: multipart "file" field, "url", or "data_base64".', array( 'status' => 400 ) );
		}
		if ( $mode_count > 1 ) {
			return new \WP_Error( 'multiple_inputs', 'Only one of "file", "url", or "data_base64" may be supplied.', array( 'status' => 400 ) );
		}

		if ( $has_multipart ) {
			$attachment_id = $this->handle_multipart( $args );
		} elseif ( $has_url ) {
			$attachment_id = $this->handle_url( $args );
		} else {
			$attachment_id = $this->handle_base64( $args );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$this->apply_metadata( $attachment_id, $args );

		return $this->format_attachment( $attachment_id );
	}

	private function handle_multipart( array $args ) {
		$field = $args['file_field'];
		$post_parent = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		$mime_check = wp_check_filetype_and_ext( $_FILES[ $field ]['tmp_name'], $_FILES[ $field ]['name'] );
		if ( empty( $mime_check['type'] ) ) {
			return new \WP_Error( 'disallowed_mime', sprintf( 'Disallowed file type for "%s".', sanitize_file_name( $_FILES[ $field ]['name'] ) ), array( 'status' => 400 ) );
		}

		$attachment_id = media_handle_upload( $field, $post_parent );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		return $attachment_id;
	}

	private function handle_url( array $args ) {
		$url = esc_url_raw( $args['url'] );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', 'URL is not valid or not allowed.', array( 'status' => 400 ) );
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'url_fetch_failed', $tmp->get_error_message(), array( 'status' => 502 ) );
		}
		if ( filesize( $tmp ) > self::URL_DOWNLOAD_MAX_BYTES ) {
			@unlink( $tmp );
			return new \WP_Error( 'file_too_large', 'Downloaded file exceeds size cap.', array( 'status' => 400 ) );
		}

		$filename = isset( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'remote' ) );
		$file = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$mime_check = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime_check['type'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( 'Disallowed file type for "%s".', $filename ), array( 'status' => 400 ) );
		}

		$post_parent = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$attachment_id = media_handle_sideload( $file, $post_parent );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		return $attachment_id;
	}

	private function handle_base64( array $args ) {
		if ( empty( $args['filename'] ) ) {
			return new \WP_Error( 'invalid_filename', '"filename" is required for base64 uploads.', array( 'status' => 400 ) );
		}
		$decoded = base64_decode( $args['data_base64'], true );
		if ( false === $decoded || '' === $decoded ) {
			return new \WP_Error( 'invalid_base64', 'data_base64 is not valid base64.', array( 'status' => 400 ) );
		}

		$filename = sanitize_file_name( $args['filename'] );
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new \WP_Error( 'sideload_failed', 'Could not create temp file.', array( 'status' => 500 ) );
		}
		file_put_contents( $tmp, $decoded );

		if ( filesize( $tmp ) > wp_max_upload_size() ) {
			@unlink( $tmp );
			return new \WP_Error( 'file_too_large', 'Uploaded file exceeds the site upload limit.', array( 'status' => 400 ) );
		}

		$mime_check = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime_check['type'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( 'Disallowed file type for "%s".', $filename ), array( 'status' => 400 ) );
		}

		$file = array( 'name' => $filename, 'tmp_name' => $tmp );
		$post_parent = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$attachment_id = media_handle_sideload( $file, $post_parent );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		return $attachment_id;
	}

	private function apply_metadata( $attachment_id, array $args ) {
		$updates = array( 'ID' => $attachment_id );
		if ( isset( $args['title'] ) ) {
			$updates['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['caption'] ) ) {
			$updates['post_excerpt'] = sanitize_text_field( (string) $args['caption'] );
		}
		if ( isset( $args['description'] ) ) {
			$updates['post_content'] = wp_kses_post( (string) $args['description'] );
		}
		if ( count( $updates ) > 1 ) {
			wp_update_post( $updates );
		}
		if ( isset( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt_text'] ) );
		}
	}

	private function format_attachment( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return new \WP_Error( 'attachment_missing', 'Attachment not found after upload.', array( 'status' => 500 ) );
		}
		$meta     = wp_get_attachment_metadata( $attachment_id );
		$src      = wp_get_attachment_url( $attachment_id );
		$filename = wp_basename( get_attached_file( $attachment_id ) );

		$out = array(
			'success'     => true,
			'id'          => (int) $attachment_id,
			'title'       => $post->post_title,
			'filename'    => $filename,
			'url'         => $src,
			'source_url'  => $src,
			'mime_type'   => $post->post_mime_type,
			'alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'post_parent' => (int) $post->post_parent,
		);

		if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
			$out['width']  = (int) $meta['width'];
			$out['height'] = (int) $meta['height'];
			$out['sizes']  = array();
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_name => $size_data ) {
					$size_src = wp_get_attachment_image_src( $attachment_id, $size_name );
					if ( $size_src ) {
						$out['sizes'][ $size_name ] = array(
							'url'    => $size_src[0],
							'width'  => (int) $size_src[1],
							'height' => (int) $size_src[2],
						);
					}
				}
			}
			$full = wp_get_attachment_image_src( $attachment_id, 'full' );
			if ( $full ) {
				$out['sizes']['full'] = array( 'url' => $full[0], 'width' => (int) $full[1], 'height' => (int) $full[2] );
			}
		}

		return $out;
	}
}
```

- [ ] **Step 4: Run tests; expect green.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml --filter MediaManagerTest
```

- [ ] **Step 5: Commit.**

```bash
git add wordpress-plugin/gk-block-api/includes/class-media-manager.php \
        wordpress-plugin/gk-block-api/tests/MediaManagerTest.php \
        wordpress-plugin/gk-block-api/tests/fixtures/sample.png
git commit -m "feat(block-api): Media_Manager with multipart/base64/url upload modes"
```

---

## Phase 4 — PHP: REST routes for the four new endpoints

### Task 7: Extend REST_Controller (constructor, properties, routes, handlers) + wire bootstrap

**Files:**
- Modify: `wordpress-plugin/gk-block-api/includes/class-rest-controller.php`
- Modify: `wordpress-plugin/gk-block-api/gk-block-api.php`
- Modify: `wordpress-plugin/gk-block-api/tests/RestSummaryTest.php`

- [ ] **Step 0: Wire the three new managers into bootstrap.**

In `wordpress-plugin/gk-block-api/gk-block-api.php`, replace the `rest_api_init` body (lines 54-79) with:

```php
add_action( 'rest_api_init', function () {
	try {
		$preferences      = new Preferences();
		$usage_stats      = new Usage_Stats();
		$block_registry   = new Block_Registry( $preferences, $usage_stats );
		$pattern_manager  = new Pattern_Manager( $preferences );
		$block_safety     = new Block_Safety();
		$html_transformer = new HTML_Transformer();
		$block_crud       = new Block_CRUD( $preferences, $block_safety, $html_transformer );
		$block_mutator    = new Block_Mutator( $block_crud, $preferences, $block_safety, $html_transformer );
		$post_manager     = new Post_Manager( $preferences, $block_crud );
		$term_manager     = new Term_Manager();
		$media_manager    = new Media_Manager();

		$controller = new REST_Controller(
			$block_registry,
			$pattern_manager,
			$block_crud,
			$usage_stats,
			$block_mutator,
			$post_manager,
			$term_manager,
			$media_manager
		);

		$controller->register_routes();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'GK Block API init error: ' . $e->getMessage() );
		}
	}
} );
```

- [ ] **Step 1: Add constructor params and properties.**

In `class-rest-controller.php`, add three new private properties below the existing ones (around line 65):

```php
/** @var Post_Manager */
private $post_manager;

/** @var Term_Manager */
private $term_manager;

/** @var Media_Manager */
private $media_manager;
```

Update the constructor signature (around line 76) to accept the three new dependencies and assign them. Match the order used in bootstrap.

- [ ] **Step 2: Register the four new routes.**

At the end of `register_routes()` (just before the closing `}` of the method), append:

```php
// Post lifecycle.
register_rest_route(
	self::NAMESPACE,
	'/posts',
	array(
		'methods'             => \WP_REST_Server::CREATABLE,
		'callback'            => array( $this, 'create_post' ),
		'permission_callback' => array( $this, 'check_edit_permissions' ),
	)
);

register_rest_route(
	self::NAMESPACE,
	'/posts/(?P<id>\d+)',
	array(
		'methods'             => \WP_REST_Server::EDITABLE, // POST/PUT/PATCH
		'callback'            => array( $this, 'update_post' ),
		'permission_callback' => array( $this, 'check_edit_permissions' ),
		'args'                => array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		),
	)
);

// Terms (read-only).
register_rest_route(
	self::NAMESPACE,
	'/terms',
	array(
		'methods'             => \WP_REST_Server::READABLE,
		'callback'            => array( $this, 'list_terms' ),
		'permission_callback' => array( $this, 'check_permissions' ),
	)
);

// Media upload.
register_rest_route(
	self::NAMESPACE,
	'/media',
	array(
		'methods'             => \WP_REST_Server::CREATABLE,
		'callback'            => array( $this, 'upload_media' ),
		'permission_callback' => array( $this, 'check_upload_permissions' ),
	)
);
```

- [ ] **Step 3: Add handler methods.**

Append to the class (before the closing brace):

```php
public function check_upload_permissions() {
	if ( ! current_user_can( 'upload_files' ) ) {
		return new \WP_Error( 'rest_cannot_upload', __( 'Sorry, you cannot upload files.', 'gk-block-api' ), array( 'status' => 403 ) );
	}
	return true;
}

public function create_post( \WP_REST_Request $request ) {
	try {
		$args = $request->get_json_params();
		if ( ! is_array( $args ) ) {
			$args = $request->get_params();
		}
		$result = $this->post_manager->create_post( (array) $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	} catch ( \Throwable $e ) {
		return $this->handle_error( $e );
	}
}

public function update_post( \WP_REST_Request $request ) {
	try {
		$post_id = (int) $request['id'];
		$args = $request->get_json_params();
		if ( ! is_array( $args ) ) {
			$args = $request->get_params();
		}
		$cap_check = $this->check_post_edit_permission( $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		$result = $this->post_manager->update_post( $post_id, (array) $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	} catch ( \Throwable $e ) {
		return $this->handle_error( $e );
	}
}

public function list_terms( \WP_REST_Request $request ) {
	try {
		$result = $this->term_manager->list_terms( $request->get_query_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	} catch ( \Throwable $e ) {
		return $this->handle_error( $e );
	}
}

public function upload_media( \WP_REST_Request $request ) {
	try {
		$args = $request->get_params();
		// Tell Media_Manager which form-data field to look at if multipart.
		if ( ! empty( $request->get_file_params() ) ) {
			$file_keys = array_keys( $request->get_file_params() );
			$args['file_field'] = isset( $file_keys[0] ) ? (string) $file_keys[0] : 'file';
			// REST request file params land in $_FILES already in WP.
		}
		$result = $this->media_manager->upload( (array) $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	} catch ( \Throwable $e ) {
		return $this->handle_error( $e );
	}
}
```

- [ ] **Step 4: Update RestSummaryTest.**

Add cases asserting the four new routes exist and have correct methods:

```php
public function test_post_lifecycle_routes_registered() {
	$server = rest_get_server();
	$routes = $server->get_routes();
	$this->assertArrayHasKey( '/gk-block-api/v1/posts', $routes );
	$this->assertArrayHasKey( '/gk-block-api/v1/posts/(?P<id>\d+)', $routes );
	$this->assertArrayHasKey( '/gk-block-api/v1/terms', $routes );
	$this->assertArrayHasKey( '/gk-block-api/v1/media', $routes );
}
```

- [ ] **Step 5: Run full PHP suite.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml
```

Expected: all tests green (existing 162 + new ~25).

- [ ] **Step 6: Commit.**

```bash
git add wordpress-plugin/gk-block-api/includes/class-rest-controller.php \
        wordpress-plugin/gk-block-api/gk-block-api.php \
        wordpress-plugin/gk-block-api/tests/RestSummaryTest.php
git commit -m "feat(block-api): wire create_post, update_post, list_terms, upload_media REST routes"
```

---

## Phase 5 — TypeScript: types and client methods

### Task 8: Extend types.ts

**Files:**
- Modify: `src/types.ts`

- [ ] **Step 1: Append the new request/response types.**

```typescript
// ===== Posts =====

export interface CreatePostRequest {
  title: string;
  post_type?: string;
  status?: 'draft' | 'pending' | 'private' | 'publish' | 'future';
  content?: string;
  blocks?: Array<{
    name: string;
    attributes?: Record<string, unknown>;
    innerBlocks?: unknown[];
    innerHTML?: string;
    innerContent?: unknown[];
  }>;
  slug?: string;
  parent?: number;
  excerpt?: string;
  featured_media?: number;
  categories?: number[];
  tags?: number[];
  terms?: Record<string, number[]>;
  date?: string;
  menu_order?: number;
  comment_status?: 'open' | 'closed';
  ping_status?: 'open' | 'closed';
  author?: number;
}

export interface UpdatePostRequest {
  title?: string;
  status?: 'draft' | 'pending' | 'private' | 'publish' | 'future' | 'trash';
  slug?: string;
  parent?: number;
  excerpt?: string;
  featured_media?: number;
  categories?: number[];
  tags?: number[];
  terms?: Record<string, number[]>;
  date?: string;
  menu_order?: number;
  comment_status?: 'open' | 'closed';
  ping_status?: 'open' | 'closed';
  author?: number;
}

export interface PostMutationResponse {
  success: boolean;
  id: number;
  post_type: string;
  status: string;
  title: string;
  slug: string;
  permalink: string;
  edit_link: string;
  before_revision_id: number | null;
  revision_id: number | null;
  transitioned_to_publish?: boolean;
  untrashed?: boolean;
  warnings: Array<Record<string, unknown>>;
}

// ===== Terms =====

export interface ListTermsRequest {
  taxonomy?: string;
  search?: string;
  parent?: number;
  hide_empty?: boolean;
  per_page?: number;
  page?: number;
  orderby?: 'name' | 'count' | 'term_id' | 'slug';
  order?: 'asc' | 'desc';
  include?: number[];
  slug?: string;
}

export interface Term {
  id: number;
  name: string;
  slug: string;
  description: string;
  parent: number;
  count: number;
  taxonomy: string;
  link: string;
}

export interface ListTermsResponse {
  taxonomy: string;
  total: number;
  page: number;
  per_page: number;
  terms: Term[];
}

// ===== Media =====

export interface UploadMediaRequest {
  path?: string;
  url?: string;
  data_base64?: string;
  filename?: string;
  title?: string;
  alt_text?: string;
  caption?: string;
  description?: string;
  post_id?: number;
}

export interface UploadMediaResponse {
  success: boolean;
  id: number;
  title: string;
  filename: string;
  url: string;
  source_url: string;
  mime_type: string;
  alt_text: string;
  caption?: string;
  description?: string;
  post_parent: number;
  width?: number;
  height?: number;
  sizes?: Record<string, { url: string; width: number; height: number }>;
}
```

- [ ] **Step 2: Commit.**

```bash
git add src/types.ts
git commit -m "feat(mcp): types for post/term/media tools"
```

---

### Task 9: Extend client.ts

**Files:**
- Modify: `src/client.ts`

- [ ] **Step 1: Add the four client methods.**

At the bottom of the `WordPressBlockClient` class (before the closing brace), insert:

```typescript
async createPost(args: CreatePostRequest): Promise<PostMutationResponse> {
  return this.request<PostMutationResponse>('POST', '/posts', { data: args });
}

async updatePost(postId: number, args: UpdatePostRequest): Promise<PostMutationResponse> {
  return this.request<PostMutationResponse>('PATCH', `/posts/${postId}`, { data: args });
}

async listTerms(args: ListTermsRequest = {}): Promise<ListTermsResponse> {
  return this.request<ListTermsResponse>('GET', '/terms', { params: args });
}

async uploadMedia(args: UploadMediaRequest): Promise<UploadMediaResponse> {
  // Accept a local filesystem path: read the file, build multipart.
  if (args.path) {
    const fs = await import('node:fs/promises');
    const path = await import('node:path');
    const data = await fs.readFile(args.path);
    const filename = args.filename ?? path.basename(args.path);
    const form = new FormData();
    const blob = new Blob([data]);
    form.append('file', blob, filename);
    if (args.title) form.append('title', args.title);
    if (args.alt_text) form.append('alt_text', args.alt_text);
    if (args.caption) form.append('caption', args.caption);
    if (args.description) form.append('description', args.description);
    if (typeof args.post_id === 'number') form.append('post_id', String(args.post_id));
    return this.request<UploadMediaResponse>('POST', '/media', { data: form });
  }
  // url or data_base64 ride as JSON.
  return this.request<UploadMediaResponse>('POST', '/media', { data: args });
}
```

Add the imports near the top:

```typescript
import type {
  // ... existing imports ...
  CreatePostRequest,
  UpdatePostRequest,
  PostMutationResponse,
  ListTermsRequest,
  ListTermsResponse,
  UploadMediaRequest,
  UploadMediaResponse,
} from './types.js';
```

- [ ] **Step 2: Confirm `request()` already accepts a `data` for FormData.**

Open `src/client.ts` and verify the internal `request()` helper passes `data` through to axios. axios v1 sets multipart Content-Type from FormData automatically. If the helper currently sets a JSON Content-Type header unconditionally, adjust it: only set `Content-Type: application/json` when `data` is not a `FormData` instance.

Add to the helper if missing:
```typescript
const isFormData = data instanceof FormData;
const headers: Record<string, string> = { ...this.authHeaders };
if (data && !isFormData) headers['Content-Type'] = 'application/json';
```

- [ ] **Step 3: Build and confirm no type errors.**

```bash
npm run build
```

- [ ] **Step 4: Commit.**

```bash
git add src/client.ts
git commit -m "feat(mcp): client methods for post/term/media endpoints"
```

---

## Phase 6 — TypeScript: tool modules + tests

### Task 10: posts.ts — create_post + update_post

**Files:**
- Create: `src/tools/posts.ts`
- Create: `src/__tests__/posts.test.ts`

- [ ] **Step 1: Write the failing tests first.**

```typescript
import { describe, it, expect, vi } from 'vitest';
import { POST_TOOLS, handlePostTool } from '../tools/posts.js';

describe('post tools', () => {
  it('exposes create_post and update_post with required schemas', () => {
    const names = POST_TOOLS.map((t) => t.name);
    expect(names).toContain('create_post');
    expect(names).toContain('update_post');
  });

  it('create_post requires title', async () => {
    const client: any = { createPost: vi.fn() };
    const result = await handlePostTool('create_post', {}, client);
    expect(result.isError).toBe(true);
    expect(client.createPost).not.toHaveBeenCalled();
  });

  it('create_post rejects content + blocks together', async () => {
    const client: any = { createPost: vi.fn() };
    const result = await handlePostTool(
      'create_post',
      { title: 'X', content: 'a', blocks: [{ name: 'core/paragraph' }] },
      client,
    );
    expect(result.isError).toBe(true);
    expect(client.createPost).not.toHaveBeenCalled();
  });

  it('create_post calls client', async () => {
    const fake = { success: true, id: 1, post_type: 'post', status: 'draft', title: 'X', slug: 'x', permalink: '', edit_link: '', before_revision_id: null, revision_id: null, warnings: [] };
    const client: any = { createPost: vi.fn().mockResolvedValue(fake) };
    const result = await handlePostTool('create_post', { title: 'X' }, client);
    expect(client.createPost).toHaveBeenCalledWith({ title: 'X' });
    expect(result.isError).toBeFalsy();
  });

  it('update_post requires post_id and at least one mutating field', async () => {
    const client: any = { updatePost: vi.fn() };
    const noId = await handlePostTool('update_post', { title: 'X' }, client);
    expect(noId.isError).toBe(true);
    const noFields = await handlePostTool('update_post', { post_id: 1 }, client);
    expect(noFields.isError).toBe(true);
    expect(client.updatePost).not.toHaveBeenCalled();
  });

  it('update_post calls client with separated id', async () => {
    const fake = { success: true, id: 1, post_type: 'post', status: 'publish', title: 'X', slug: 'x', permalink: '', edit_link: '', before_revision_id: null, revision_id: null, warnings: [] };
    const client: any = { updatePost: vi.fn().mockResolvedValue(fake) };
    await handlePostTool('update_post', { post_id: 1, status: 'publish' }, client);
    expect(client.updatePost).toHaveBeenCalledWith(1, { status: 'publish' });
  });
});
```

Run; expect failures (module not found).

- [ ] **Step 2: Implement `posts.ts`.**

```typescript
import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import type { WordPressBlockClient } from '../client.js';

const POST_STATUS_CREATE = ['draft', 'pending', 'private', 'publish', 'future'] as const;
const POST_STATUS_UPDATE = ['draft', 'pending', 'private', 'publish', 'future', 'trash'] as const;

export const POST_TOOLS: Tool[] = [
  {
    name: 'create_post',
    description:
      'Create a new post or page. Returns the new post ID, slug, permalink, and edit link. Supports content (HTML) OR blocks (structured), but not both. Status defaults to draft.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        title: { type: 'string', description: 'Post title (required, non-empty).' },
        post_type: { type: 'string', description: 'Post type slug (default: post).' },
        status: { type: 'string', enum: [...POST_STATUS_CREATE], description: 'Initial status. Use update_post for trash transitions.' },
        content: { type: 'string', description: 'Raw post_content (HTML or block markup). Mutually exclusive with blocks.' },
        blocks: {
          type: 'array',
          description: 'Structured blocks. Validated against block registry and preference tier.',
          items: { type: 'object', properties: { name: { type: 'string' } }, required: ['name'] },
        },
        slug: { type: 'string' },
        parent: { type: 'number' },
        excerpt: { type: 'string' },
        featured_media: { type: 'number', description: 'Attachment ID. Send 0 to leave unset.' },
        categories: { type: 'array', items: { type: 'number' }, description: 'Term IDs in the category taxonomy.' },
        tags: { type: 'array', items: { type: 'number' }, description: 'Term IDs in the post_tag taxonomy.' },
        terms: { type: 'object', description: 'Map of taxonomy slug → term IDs. For non-built-in taxonomies on CPTs.' },
        date: { type: 'string', description: 'ISO 8601 publish date.' },
        menu_order: { type: 'number' },
        comment_status: { type: 'string', enum: ['open', 'closed'] },
        ping_status: { type: 'string', enum: ['open', 'closed'] },
        author: { type: 'number', description: 'User ID. Other-user authorship requires edit_others_posts cap.' },
      },
      required: ['title'],
    },
  },
  {
    name: 'update_post',
    description:
      'Partial update of post metadata, status, or terms. Block content edits stay on the per-block tools. Use status: trash to trash, status: draft to untrash. At least one mutating field required.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'WordPress post ID.' },
        title: { type: 'string' },
        status: { type: 'string', enum: [...POST_STATUS_UPDATE] },
        slug: { type: 'string' },
        parent: { type: 'number' },
        excerpt: { type: 'string' },
        featured_media: { type: 'number', description: 'Attachment ID. Send 0 to clear.' },
        categories: { type: 'array', items: { type: 'number' } },
        tags: { type: 'array', items: { type: 'number' } },
        terms: { type: 'object' },
        date: { type: 'string' },
        menu_order: { type: 'number' },
        comment_status: { type: 'string', enum: ['open', 'closed'] },
        ping_status: { type: 'string', enum: ['open', 'closed'] },
        author: { type: 'number' },
      },
      required: ['post_id'],
    },
  },
];

export async function handlePostTool(name: string, args: Record<string, unknown>, client: WordPressBlockClient) {
  try {
    if (name === 'create_post') {
      if (typeof args.title !== 'string' || args.title.trim() === '') {
        return errorContent('create_post requires a non-empty "title".', 'create_post');
      }
      if (args.content !== undefined && Array.isArray(args.blocks)) {
        return errorContent('"content" and "blocks" are mutually exclusive.', 'create_post');
      }
      const result = await client.createPost(args as any);
      return jsonContent(result);
    }
    if (name === 'update_post') {
      if (typeof args.post_id !== 'number') {
        return errorContent('update_post requires "post_id" (number).', 'update_post');
      }
      const { post_id, ...rest } = args;
      if (Object.keys(rest).length === 0) {
        return errorContent('update_post requires at least one mutating field besides post_id.', 'update_post');
      }
      const result = await client.updatePost(post_id as number, rest as any);
      return jsonContent(result);
    }
    return errorContent(`Unknown post tool: ${name}`, name);
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : String(e);
    return errorContent(message, name);
  }
}

function jsonContent(payload: unknown) {
  return { content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }] };
}

function errorContent(message: string, tool: string) {
  return {
    isError: true,
    content: [{ type: 'text', text: JSON.stringify({ error: true, message, tool }) }],
  };
}
```

- [ ] **Step 3: Run tests; expect pass.**

```bash
npm test -- posts.test.ts
```

- [ ] **Step 4: Commit.**

```bash
git add src/tools/posts.ts src/__tests__/posts.test.ts
git commit -m "feat(mcp): create_post and update_post tools with input validation"
```

---

### Task 11: terms.ts — list_terms

**Files:**
- Create: `src/tools/terms.ts`
- Create: `src/__tests__/terms.test.ts`

- [ ] **Step 1: Failing test.**

```typescript
import { describe, it, expect, vi } from 'vitest';
import { TERM_TOOLS, handleTermTool } from '../tools/terms.js';

describe('term tools', () => {
  it('exposes list_terms', () => {
    expect(TERM_TOOLS.map((t) => t.name)).toContain('list_terms');
  });

  it('list_terms defaults taxonomy to category', async () => {
    const client: any = { listTerms: vi.fn().mockResolvedValue({ taxonomy: 'category', total: 0, page: 1, per_page: 100, terms: [] }) };
    await handleTermTool('list_terms', {}, client);
    expect(client.listTerms).toHaveBeenCalledWith({});
  });

  it('list_terms passes filters through', async () => {
    const client: any = { listTerms: vi.fn().mockResolvedValue({}) };
    await handleTermTool('list_terms', { taxonomy: 'post_tag', search: 'wp', per_page: 25 }, client);
    expect(client.listTerms).toHaveBeenCalledWith({ taxonomy: 'post_tag', search: 'wp', per_page: 25 });
  });
});
```

Run, expect failure.

- [ ] **Step 2: Implement `terms.ts`.**

```typescript
import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import type { WordPressBlockClient } from '../client.js';

export const TERM_TOOLS: Tool[] = [
  {
    name: 'list_terms',
    description:
      'List terms in a taxonomy (default: category). Useful for discovering category and tag IDs to pass to create_post or update_post.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        taxonomy: { type: 'string', description: 'Taxonomy slug. Default: category.' },
        search: { type: 'string', description: 'LIKE match against term name.' },
        parent: { type: 'number' },
        hide_empty: { type: 'boolean', description: 'Default: false.' },
        per_page: { type: 'number', description: 'Default 100, max 200.' },
        page: { type: 'number', description: '1-indexed.' },
        orderby: { type: 'string', enum: ['name', 'count', 'term_id', 'slug'] },
        order: { type: 'string', enum: ['asc', 'desc'] },
        include: { type: 'array', items: { type: 'number' } },
        slug: { type: 'string' },
      },
    },
  },
];

export async function handleTermTool(name: string, args: Record<string, unknown>, client: WordPressBlockClient) {
  try {
    if (name === 'list_terms') {
      const result = await client.listTerms(args as any);
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    }
    return {
      isError: true,
      content: [{ type: 'text', text: JSON.stringify({ error: true, message: `Unknown term tool: ${name}`, tool: name }) }],
    };
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : String(e);
    return {
      isError: true,
      content: [{ type: 'text', text: JSON.stringify({ error: true, message, tool: name }) }],
    };
  }
}
```

- [ ] **Step 3: Run tests; expect green. Commit.**

```bash
npm test -- terms.test.ts
git add src/tools/terms.ts src/__tests__/terms.test.ts
git commit -m "feat(mcp): list_terms tool"
```

---

### Task 12: media.ts — upload_media

**Files:**
- Create: `src/tools/media.ts`
- Create: `src/__tests__/media.test.ts`

- [ ] **Step 1: Failing tests.**

```typescript
import { describe, it, expect, vi } from 'vitest';
import { MEDIA_TOOLS, handleMediaTool } from '../tools/media.js';

describe('media tools', () => {
  it('exposes upload_media', () => {
    expect(MEDIA_TOOLS.map((t) => t.name)).toContain('upload_media');
  });

  it('upload_media requires exactly one of path/url/data_base64', async () => {
    const client: any = { uploadMedia: vi.fn() };
    const none = await handleMediaTool('upload_media', { alt_text: 'x' }, client);
    expect(none.isError).toBe(true);
    const both = await handleMediaTool('upload_media', { path: '/a', url: 'https://b' }, client);
    expect(both.isError).toBe(true);
    expect(client.uploadMedia).not.toHaveBeenCalled();
  });

  it('upload_media passes args through to client', async () => {
    const client: any = { uploadMedia: vi.fn().mockResolvedValue({ success: true, id: 1 }) };
    await handleMediaTool('upload_media', { url: 'https://example.com/x.png', alt_text: 'x' }, client);
    expect(client.uploadMedia).toHaveBeenCalledWith({ url: 'https://example.com/x.png', alt_text: 'x' });
  });
});
```

Run, expect failure.

- [ ] **Step 2: Implement `media.ts`.**

```typescript
import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import type { WordPressBlockClient } from '../client.js';

export const MEDIA_TOOLS: Tool[] = [
  {
    name: 'upload_media',
    description:
      'Upload an item to the WordPress media library. Provide exactly one of: path (local filesystem on the MCP host, sent as multipart), url (server-side sideload), or data_base64 (with filename). Returns the attachment ID and URL ready for core/image blocks.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        path: { type: 'string', description: 'Absolute path on the MCP host. Will be read and POSTed as multipart.' },
        url: { type: 'string', description: 'Public URL the WordPress site can fetch.' },
        data_base64: { type: 'string', description: 'Base64-encoded file contents (requires filename).' },
        filename: { type: 'string', description: 'Override filename (required when using data_base64).' },
        title: { type: 'string' },
        alt_text: { type: 'string', description: 'Saved as _wp_attachment_image_alt meta. Critical for accessibility.' },
        caption: { type: 'string' },
        description: { type: 'string' },
        post_id: { type: 'number', description: 'Attach to a parent post (sets post_parent).' },
      },
    },
  },
];

export async function handleMediaTool(name: string, args: Record<string, unknown>, client: WordPressBlockClient) {
  try {
    if (name === 'upload_media') {
      const modes = ['path', 'url', 'data_base64'].filter((k) => typeof args[k] === 'string' && (args[k] as string).length > 0);
      if (modes.length === 0) {
        return errorContent('upload_media requires one of: path, url, or data_base64.', 'upload_media');
      }
      if (modes.length > 1) {
        return errorContent(`upload_media accepts only one of: path, url, data_base64 (got ${modes.join(', ')}).`, 'upload_media');
      }
      if (args.data_base64 && !args.filename) {
        return errorContent('upload_media with data_base64 requires "filename".', 'upload_media');
      }
      const result = await client.uploadMedia(args as any);
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    }
    return errorContent(`Unknown media tool: ${name}`, name);
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : String(e);
    return errorContent(message, name);
  }
}

function errorContent(message: string, tool: string) {
  return {
    isError: true,
    content: [{ type: 'text', text: JSON.stringify({ error: true, message, tool }) }],
  };
}
```

- [ ] **Step 3: Run tests; expect pass. Commit.**

```bash
npm test -- media.test.ts
git add src/tools/media.ts src/__tests__/media.test.ts
git commit -m "feat(mcp): upload_media tool with path/url/base64 modes"
```

---

### Task 13: Wire new tool sets into index.ts

**Files:**
- Modify: `src/index.ts`

- [ ] **Step 1: Add imports.**

Near the top of `src/index.ts`, alongside the existing tool imports:

```typescript
import { POST_TOOLS, handlePostTool } from './tools/posts.js';
import { TERM_TOOLS, handleTermTool } from './tools/terms.js';
import { MEDIA_TOOLS, handleMediaTool } from './tools/media.js';
```

- [ ] **Step 2: Aggregate the tool list.**

Find where `ALL_TOOLS` (or equivalent) is composed (the routing block around line 92-104 per AGENTS.md). Add:

```typescript
const POST_TOOL_NAMES = new Set(POST_TOOLS.map((t) => t.name));
const TERM_TOOL_NAMES = new Set(TERM_TOOLS.map((t) => t.name));
const MEDIA_TOOL_NAMES = new Set(MEDIA_TOOLS.map((t) => t.name));

const ALL_TOOLS = [
  ...DISCOVERY_TOOLS,
  ...READ_TOOLS,
  ...WRITE_TOOLS,
  ...MUTATE_TOOLS,
  ...PATTERN_TOOLS,
  ...POST_TOOLS,
  ...TERM_TOOLS,
  ...MEDIA_TOOLS,
];
```

(Use the actual symbol names — verify via the existing code.)

- [ ] **Step 3: Add dispatch branches.**

In the request handler, add after the existing branches:

```typescript
if (POST_TOOL_NAMES.has(name)) {
  return handlePostTool(name, args, client);
}
if (TERM_TOOL_NAMES.has(name)) {
  return handleTermTool(name, args, client);
}
if (MEDIA_TOOL_NAMES.has(name)) {
  return handleMediaTool(name, args, client);
}
```

- [ ] **Step 4: Build, run all tests.**

```bash
npm run build && npm test
```

Expected: full TS suite green.

- [ ] **Step 5: Commit.**

```bash
git add src/index.ts
git commit -m "feat(mcp): register post/term/media tools in dispatcher"
```

---

## Phase 7 — Docs and version bumps

### Task 14: Update README, AGENTS, version, MCP table

**Files:**
- Modify: `README.md`
- Modify: `AGENTS.md`
- Modify: `wordpress-plugin/AGENTS.md`
- Modify: `package.json`
- Modify: `wordpress-plugin/gk-block-api/gk-block-api.php` (Plugin Header version)

- [ ] **Step 1: Bump versions.**

```bash
# package.json: "version": "1.2.0"
# gk-block-api.php header: Version: 1.2.0
# gk-block-api.php constant: define( 'GK_BLOCK_API_VERSION', '1.2.0' );
```

- [ ] **Step 2: Update the MCP Tools table in README.md.**

Add four rows:
```markdown
| `create_post` | Create a new post or page (draft, publish, etc.) with optional initial blocks |
| `update_post` | Update post metadata, status, or terms — covers publish/trash/untrash transitions |
| `list_terms`  | List taxonomy terms (categories, tags, custom taxonomies) for ID lookup |
| `upload_media` | Upload to the media library via local path, URL sideload, or base64 |
```

Add a **Docs lifecycle** example near the existing Example Usage section showing list_terms → create_post → upload_media → insert_blocks → update_post(publish).

- [ ] **Step 3: Update both AGENTS.md files.**

In `wordpress-plugin/AGENTS.md`, add the four endpoints to the relevant tables and add a single-line Class entry for each new manager.

In `MCPs/block-mcp/AGENTS.md`, add the three new modules to the tool architecture table and the new client methods to the Client section.

- [ ] **Step 4: Commit.**

```bash
git add README.md AGENTS.md wordpress-plugin/AGENTS.md package.json wordpress-plugin/gk-block-api/gk-block-api.php
git commit -m "docs: v1.2 docs lifecycle tools — README, AGENTS, version bump"
```

---

## Phase 8 — Code review

### Task 15: Run code-reviewer agent against the diff

- [ ] **Step 1: Capture the diff range.**

```bash
git log --oneline main..HEAD
git diff main...HEAD --stat
```

- [ ] **Step 2: Invoke code-reviewer.**

Use the Agent tool with `subagent_type: superpowers:code-reviewer` (or `feature-dev:code-reviewer` if the superpowers variant is unavailable). Provide:
- Spec link: `MCPs/block-mcp/docs/specs/2026-04-27-docs-lifecycle-tools.md`
- Plan link: `MCPs/block-mcp/docs/plans/2026-04-27-docs-lifecycle-tools.md`
- Diff range: `main..HEAD`
- Focus: spec adherence, capability checks, sanitization, error envelope consistency, route precedence with existing block routes, base64 size handling, FormData multipart wiring.
- Report format: P0/P1/P2 with file:line citations.

- [ ] **Step 3: Address P0 and P1 findings; re-run tests.**

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml
cd ../.. && npm test
```

- [ ] **Step 4: Commit fixes (if any).**

```bash
git add <changed files>
git commit -m "fix(block-api): address code review findings (P0/P1)"
```

---

## Phase 9 — End-to-end test on gkclone

### Task 16: Deploy plugin into gkclone, create app password

**Files (referenced):**
- gkclone path: `/Users/zackkatz/Local/dev/app/public/wp-content/plugins/Tooling/gkclone`
- Synced plugins dir: `<gkclone>/synced/plugins/`

- [ ] **Step 1: Symlink the plugin into the gkclone wp-env mount.**

```bash
cd /Users/zackkatz/Local/dev/app/public/wp-content/plugins/Tooling/gkclone/synced/plugins
ln -sfn /Users/zackkatz/Dropbox/MonoKit/MCPs/block-mcp/wordpress-plugin/gk-block-api gk-block-api
ls -la gk-block-api
```

- [ ] **Step 2: Activate the plugin via wp-env.**

```bash
cd /Users/zackkatz/Local/dev/app/public/wp-content/plugins/Tooling/gkclone
npx wp-env run cli wp plugin activate gk-block-api
npx wp-env run cli wp plugin status gk-block-api
```

Expected: "Status: Active".

- [ ] **Step 3: Create an Application Password for admin.**

```bash
npx wp-env run cli wp user application-password create admin "block-mcp-e2e" --porcelain
```

Capture the printed password. Save to `MCPs/block-mcp/.env.gkclone` (gitignored — confirm `.gitignore` covers `.env*`):

```
GK_SITE_URL=http://localhost:7701
GK_BLOCK_API_USER=admin
GK_BLOCK_API_APP_PASSWORD=<paste-here>
```

- [ ] **Step 4: Verify with a sanity REST call.**

```bash
source MCPs/block-mcp/.env.gkclone
curl -u "$GK_BLOCK_API_USER:$GK_BLOCK_API_APP_PASSWORD" \
  "$GK_SITE_URL/wp-json/gk-block-api/v1/terms?taxonomy=category&per_page=5" \
  | head -40
```

Expected: JSON with at least Uncategorized.

---

### Task 17: Author the e2e smoke script

**Files:**
- Create: `scripts/e2e-gkclone.mjs`
- Create: `scripts/fixtures/screenshot.png` (any test PNG; reuse `tests/fixtures/sample.png` if simpler)

- [ ] **Step 1: Write the script.**

```javascript
#!/usr/bin/env node
import 'node:process';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SITE = process.env.GK_SITE_URL;
const USER = process.env.GK_BLOCK_API_USER;
const PW = process.env.GK_BLOCK_API_APP_PASSWORD;
if (!SITE || !USER || !PW) {
  console.error('Set GK_SITE_URL, GK_BLOCK_API_USER, GK_BLOCK_API_APP_PASSWORD');
  process.exit(2);
}

const auth = 'Basic ' + Buffer.from(`${USER}:${PW}`).toString('base64');
const base = `${SITE}/wp-json/gk-block-api/v1`;

async function api(method, route, { json, form } = {}) {
  const headers = { Authorization: auth };
  let body;
  if (json) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(json);
  } else if (form) {
    body = form; // FormData; fetch handles Content-Type
  }
  const res = await fetch(base + route, { method, headers, body });
  const text = await res.text();
  if (!res.ok) {
    throw new Error(`${method} ${route} → ${res.status}\n${text}`);
  }
  return text ? JSON.parse(text) : null;
}

function step(name, fn) {
  return (async () => {
    const t0 = Date.now();
    process.stdout.write(`▶ ${name}…`);
    const r = await fn();
    process.stdout.write(`  ✓ ${Date.now() - t0}ms\n`);
    return r;
  })();
}

(async () => {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');

  const terms = await step('list_terms', () => api('GET', '/terms?search=uncategorized&taxonomy=category&per_page=1'));
  if (!terms.terms.length) throw new Error('Uncategorized not found');
  const catId = terms.terms[0].id;

  const created = await step('create_post', () =>
    api('POST', '/posts', {
      json: {
        title: `block-mcp e2e ${stamp}`,
        status: 'draft',
        categories: [catId],
        blocks: [{ name: 'core/heading', attributes: { level: 2 }, innerHTML: '<h2 class="wp-block-heading">E2E</h2>' }],
      },
    }),
  );
  const postId = created.id;
  console.log(`  post id: ${postId}, slug: ${created.slug}`);

  const png = await fs.readFile(path.join(__dirname, 'fixtures', 'screenshot.png'));
  const form = new FormData();
  form.append('file', new Blob([png]), 'screenshot.png');
  form.append('alt_text', 'block-mcp e2e fixture');
  form.append('post_id', String(postId));
  const media = await step('upload_media', () => api('POST', '/media', { form }));
  const attId = media.id;
  console.log(`  attachment id: ${attId}, url: ${media.url}`);

  await step('insert image block', () =>
    api('POST', `/posts/${postId}/blocks`, {
      json: {
        after: 0,
        blocks: [{ name: 'core/image', attributes: { id: attId, url: media.url, alt: 'fixture' }, innerHTML: `<figure class="wp-block-image"><img src="${media.url}" alt="fixture"/></figure>` }],
      },
    }),
  );

  const after = await step('get blocks after insert', () => api('GET', `/posts/${postId}/blocks`));
  const names = after.blocks.map((b) => b.name);
  if (!names.includes('core/image')) throw new Error('core/image not found after insert');

  const published = await step('publish', () => api('PATCH', `/posts/${postId}`, { json: { status: 'publish' } }));
  if (published.status !== 'publish') throw new Error('publish failed');
  const headPub = await fetch(published.permalink);
  if (headPub.status !== 200) throw new Error(`permalink HEAD ${headPub.status}`);

  const trashed = await step('trash', () => api('PATCH', `/posts/${postId}`, { json: { status: 'trash' } }));
  if (trashed.status !== 'trash') throw new Error('trash failed');

  const untrashed = await step('untrash to draft', () => api('PATCH', `/posts/${postId}`, { json: { status: 'draft' } }));
  if (untrashed.status !== 'draft' || !untrashed.untrashed) throw new Error('untrash failed');

  await step('final cleanup (re-trash)', () => api('PATCH', `/posts/${postId}`, { json: { status: 'trash' } }));
  console.log(`Done. Post ${postId} left in trash. Attachment ${attId} retained for inspection.`);
})().catch((e) => { console.error('FAIL:', e); process.exit(1); });
```

- [ ] **Step 2: Stage the fixture image.**

```bash
mkdir -p scripts/fixtures
cp wordpress-plugin/gk-block-api/tests/fixtures/sample.png scripts/fixtures/screenshot.png
```

- [ ] **Step 3: Run the script.**

```bash
set -a; source .env.gkclone; set +a
node scripts/e2e-gkclone.mjs
```

Expected: every step prints `✓` and the script exits 0.

- [ ] **Step 4: If a step fails, capture the error and treat as a P0 finding.** Re-run after the fix.

- [ ] **Step 5: Commit the smoke script.**

```bash
git add scripts/e2e-gkclone.mjs scripts/fixtures/screenshot.png
git commit -m "test(e2e): docs lifecycle smoke script for gkclone"
```

---

## Phase 10 — Final wrap

### Task 18: Final verification

- [ ] **Step 1:** Run all tests once more.

```bash
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml
cd ../.. && npm run build && npm test
```

Expected: green / green / green.

- [ ] **Step 2:** Run the e2e script one more time to confirm idempotence.

- [ ] **Step 3:** Summarize delivered scope in a final commit / annotation. No README changes needed beyond Task 14.

- [ ] **Step 4:** Notify the user. Hand back the spec, plan, code-review report, and e2e log paths.

---

## Spec ↔ Task coverage matrix

| Spec requirement | Task |
|---|---|
| `create_post` route + handler | 1, 3, 7 |
| `update_post` route + handler | 1, 4, 7 |
| `list_terms` route + handler | 5, 7 |
| `upload_media` route + handler (3 modes) | 6, 7 |
| Capability checks | 3 (create), 4 (update), 5 (terms read), 7 (upload) |
| Block validation reuse | 3 (`validate_blocks_for_insert`) |
| Term validation | 3 (`assign_terms`) |
| Featured image validation | 3, 4 |
| Status allowlist (no trash on create) | 3 |
| Trash/untrash transitions | 4 |
| Cycle parent rejection | 4 |
| Pagination + search on terms | 5 |
| Multipart, URL sideload, base64 modes | 6 |
| MIME allowlist via WP filters | 6 |
| Size cap on URL sideload | 6 |
| FormData wiring in TS client | 9 |
| Tool input validation (TS) | 10, 11, 12 |
| Tool dispatcher integration | 13 |
| Docs + version bump | 14 |
| Code review | 15 |
| E2E on gkclone | 16, 17 |
