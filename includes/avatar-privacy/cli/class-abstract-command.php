<?php
/**
 * This file is part of Avatar Privacy.
 *
 * Copyright 2019 Peter Putzer.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 *  ***
 *
 * @package mundschenk-at/avatar-privacy
 * @license http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Avatar_Privacy\CLI;

use Avatar_Privacy\Core;

/**
 * An abstract base class for CLI command implementations.
 *
 * @since 2.3.0
 */
abstract class Abstract_Command implements Command {

	/**
	 * Clears all of the caches for memory management. Should be called after
	 * every 100 updates or so.
	 *
	 * @global \WP_Object_Cache $wp_object_cache The WordPress object cache.
	 * @global \wpdb            $wpdb            The WordPress database.
	 */
	protected function stop_the_insanity() {
		global $wpdb, $wp_object_cache;

		// Clean up saved queries.
		$wpdb->queries = [];

		// FIXME: Check if any of these are at least somewhat universal.
		if ( \is_object( $wp_object_cache ) ) {
			if ( \property_exists( $wp_object_cache, 'group_ops' ) ) {
				$wp_object_cache->group_ops = [];
			}

			if ( \property_exists( $wp_object_cache, 'stats' ) ) {
				$wp_object_cache->stats = [];
			}

			if ( \property_exists( $wp_object_cache, 'memcache_debug' ) ) {
				$wp_object_cache->memcache_debug = [];
			}

			if ( \property_exists( $wp_object_cache, 'cache' ) ) {
				$wp_object_cache->cache = [];
			}

			// For some large memcached implementations.
			if ( \method_exists( $wp_object_cache, '__remoteset' ) ) {
				$wp_object_cache->__remoteset(); // @codeCoverageIgnore
			}
		}
	}

	/**
	 * Copies the iterator into an array.
	 *
	 * This method replaces to the builtin `\iterator_to_array()` to facilitate
	 * unit testing.
	 *
	 * @param  \Iterator $iterator Any iterator.
	 *
	 * @return array
	 */
	protected function iterator_to_array( \Iterator $iterator ) {
		$result = [];

		foreach ( $iterator as $key => $item ) {
			$result[ $key ] = $item;
		}

		return $result;
	}
}
