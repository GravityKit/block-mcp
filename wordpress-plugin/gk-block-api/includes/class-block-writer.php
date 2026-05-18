<?php
/**
 * Block_Writer — write-path operations extracted from Block_CRUD.
 *
 * Handles all mutations: update, insert, delete, replace, pattern insertion,
 * save, rate limiting, and optimistic-concurrency helpers. Delegates ref
 * management and tree utilities back to Block_CRUD.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Writer
 *
 * Write-path block operations: update, insert, delete, replace, save.
 */
class Block_Writer {

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
