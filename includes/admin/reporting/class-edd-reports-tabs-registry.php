<?php
/**
 * Reports API Tabs Registry
 *
 * @package     EDD
 * @subpackage  Admin/Reports
 * @copyright   Copyright (c) 2018, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.0
 */
namespace EDD\Admin\Reports;

use EDD\Utils\Registry;
use EDD\Utils\Exception as EDD_Exception;
use EDD\Admin\Reports\Exception as Reports_Exception;

/**
 * Implements a singleton registry for registering reports tabs.
 *
 * @since 3.0
 *
 * @see \EDD\Utils\Registry
 */
class Tabs_Registry extends Registry {

	/**
	 * The one true EDD_Reports_Tabs_Registry instance.
	 *
	 * @since 3.0
	 * @var   \EDD\Admin\Reports\Tabs_Registry
	 */
	private static $instance;

	/**
	 * Retrieves the one true Reports Tabs Registry instance.
	 *
	 * @since 3.0
	 *
	 * @return \EDD\Admin\Reports\Tabs_Registry Reports tabs registry instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Tabs_Registry;
		}

		return self::$instance;
	}

	/**
	 * Adds a new reports tab to the master registry.
	 *
	 * @since 3.0
	 *
	 * @param string $tab_id     Reports tab ID.
	 * @param array  $attributes {
	 *     Attributes of the reports tab.
	 *
	 *     @type string $label    Tab label.
	 *     @type int    $priority Priority by which to register the tab.
	 *     @type array  $filters  Registered filters to expose for the tab.
	 *     @type string $graph    Class to instantiate for building the graph.
	 * }
	 * @return bool True if the tab was successfully registered, otherwise false.
	 */
	public function add_tab( $tab_id, $attributes ) {
		$result = false;

		if ( ! is_array( $attributes ) ) {
			throw new Reports_Exception( 'New tab attributes must take the form of an array.' );

			return $result;
		} else {
			$attributes['tiles'] = new Tiles_Registry();
		}

		try {

			$result = parent::add_item( $tab_id, $attributes );

		} catch( EDD_Exception $exception ) {

			$exception->log();

		}

		return $result;
	}

	/**
	 * Removes a reports tab by ID from the master registry.
	 *
	 * @since 3.0
	 *
	 * @param string $tab_id Reports tab ID.
	 */
	public function remove_tab( $tab_id ) {
		parent::remove_item( $tab_id );
	}

	/**
	 * Retrieves a specific reports tab by ID from the master registry.
	 *
	 * @since 3.0
	 *
	 * @param string $tab_id        Name of the reports tab to retrieve.
	 * @param bool   $extract_tiles Optional. Whether to extract registered tiles as an array.
	 *                              Default true.
	 * @return array The tab's attributes if it exists, otherwise an empty array.
	 */
	public function get_tab( $tab_id, $extract_tiles = true ) {
		$tab = array();

		try {

			$tab = parent::get_item( $tab_id );

			if ( true === $extract_tiles ) {
				// Reduce tile registry to an array.
				if ( ! empty( $tab['tiles'] ) ) {
					$tab['tiles'] = $tab['tiles']->getArrayCopy();
				}
			}

		} catch( EDD_Exception $exception ) {

			$exception->log();

		}

		return $tab;
	}

	/**
	 * Retrieves all of the registered reports tab records.
	 *
	 * @since 3.0
	 *
	 * @param bool $extract_tiles Optional. Whether to extract registered tiles as arrays.
	 *                            Default true.
	 * @return array All registered reports tabs.
	 */
	public function get_tabs( $extract_tiles = true ) {
		/** @var \ArrayObject[] $tabs */
		$tabs = parent::get_items();

		if ( true === $extract_tiles ) {
			foreach ( $tabs as $tab_id => $attributes ) {
				if ( ! empty( $attributes['tiles'] ) ) {
					$tabs[ $tab_id ]['tiles'] = $attributes['tiles']->getArrayCopy();
				}
			}
		}

		return $tabs;
	}

	/**
	 * Registers a new Reports tab tile.
	 *
	 * @since 3.0
	 *
	 * @param string $tile_id    New tab tile ID.
	 * @param string $tab_id     Reports tab ID.
	 * @param array  $attributes Tile attributes.
	 * @return bool True if the tile was added, otherwise false.
	 */
	public function add_tile( $tile_id, $tab_id, $attributes ) {

		$added = false;

		try {

			$tiles->add_tile( $tab_id, $tile_id, $attributes );

			$added = true;

		} catch( EDD_Exception $exception ) {

			$exception->log();

		}

		return $added;
	}

	/**
	 * Removes a tile from a tab.
	 *
	 * @since 3.0
	 *
	 * @param string $tile_id Reports tab tile ID.
	 * @param string $tab_id  Reports tab ID.
	 */
	public function remove_tile( $tile_id, $tab_id ) {

		try {

			$tiles->remove_tile( $tab_id, $tile_id );

		} catch( EDD_Exception $exception ) {
			$exception->log();
		}

	}

	/**
	 * Retrieves a tile record.
	 *
	 * @since 3.0
	 *
	 * @param string $tab_id  Reports tab ID.
	 * @param string $tile_id Reports tab tile ID.
	 * @return array Attributes for the requested tile if it exists, otherwise an empty array.
	 */
	public function get_tile( $tab_id, $tile_id ) {
		$tiles = edd_get_registry( 'reports:tiles' );
		$tile  = array();

		try {

			$tile = $tiles->get_tile( $tab_id, $tile_id );

		} catch( EDD_Exception $exception ) {
			$exception->log();
		}

		return $tile;
	}

}
