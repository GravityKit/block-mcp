<?php
/**
 * Block_Reader — read-path operations extracted from Block_CRUD.
 *
 * Handles get_blocks() and format_blocks() along with their private recursive
 * helpers. Delegates ref management and tree utilities back to Block_CRUD.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Reader
 *
 * Read-only block operations: parse, format, and render block content.
 */
class Block_Reader {

	/**
	 * Reference back to the owning Block_CRUD instance for shared utilities.
	 *
	 * @var Block_CRUD
	 */
	private $crud;

	/**
	 * Preferences instance.
	 *
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * Block safety checker.
	 *
	 * @var Block_Safety
	 */
	private $safety;

	/**
	 * HTML transformer.
	 *
	 * @var HTML_Transformer
	 */
	private $transformer;

	/**
	 * Site-wide block inventory.
	 *
	 * @var Block_Inventory
	 */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param Block_CRUD       $crud        Owning CRUD instance for shared utilities.
	 * @param Preferences      $preferences Preferences instance.
	 * @param Block_Safety     $safety      Block safety checker.
	 * @param HTML_Transformer $transformer HTML transformer.
	 * @param Block_Inventory  $inventory   Block inventory.
	 */
	public function __construct( Block_CRUD $crud, Preferences $preferences, Block_Safety $safety, HTML_Transformer $transformer, Block_Inventory $inventory ) {
		$this->crud        = $crud;
		$this->preferences = $preferences;
		$this->safety      = $safety;
		$this->transformer = $transformer;
		$this->inventory   = $inventory;
	}
}
