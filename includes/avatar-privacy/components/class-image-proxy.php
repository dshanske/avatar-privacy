<?php
/**
 * This file is part of Avatar Privacy.
 *
 * Copyright 2018-2019 Peter Putzer.
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

namespace Avatar_Privacy\Components;

use Avatar_Privacy\Core;

use Avatar_Privacy\Avatar_Handlers\Avatar_Handler;
use Avatar_Privacy\Avatar_Handlers\Default_Icons_Handler;
use Avatar_Privacy\Avatar_Handlers\Gravatar_Cache_Handler;
use Avatar_Privacy\Avatar_Handlers\User_Avatar_Handler;

use Avatar_Privacy\Data_Storage\Filesystem_Cache;
use Avatar_Privacy\Data_Storage\Options;
use Avatar_Privacy\Data_Storage\Site_Transients;
use Avatar_Privacy\Data_Storage\Transients;

use Avatar_Privacy\Tools\Images;

/**
 * Handles the creation and caching of avatar images. Default icons are created by
 * Icon_Provider instances, remote Gravatar.com avatars by a Gravatar_Cache_Handler.
 *
 * @since 1.0.0
 * @since 2.0.0 Renamed to Image_Proxy.
 *
 * @author Peter Putzer <github@mundschenk.at>
 */
class Image_Proxy implements \Avatar_Privacy\Component {

	const CRON_JOB_LOCK_GRAVATARS  = 'cron_job_lock_gravatars';
	const CRON_JOB_LOCK_ALL_IMAGES = 'cron_job_lock_all_images';

	const CRON_JOB_ACTION = 'avatar_privacy_daily';

	/**
	 * The options handler.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * The site transients handler.
	 *
	 * @var Site_Transients
	 */
	private $site_transients;

	/**
	 * The file system caching handler.
	 *
	 * @var Filesystem_Cache
	 */
	private $file_cache;

	/**
	 * The available avatar handlers.
	 *
	 * @var Avatar_Handler[]
	 */
	private $handlers;

	/**
	 * Creates a new instance.
	 *
	 * @param Site_Transients        $site_transients The site transients handler.
	 * @param Options                $options         The options handler.
	 * @param Filesystem_Cache       $file_cache      The filesystem cache handler.
	 * @param Gravatar_Cache_Handler $gravatar        The Gravatar.com icon provider.
	 * @param User_Avatar_Handler    $user_avatar     The user avatar handler.
	 * @param Default_Icons_Handler  $default_icons   The default icons handler.
	 */
	public function __construct( Site_Transients $site_transients, Options $options, Filesystem_Cache $file_cache, Gravatar_Cache_Handler $gravatar, User_Avatar_Handler $user_avatar, Default_Icons_Handler $default_icons ) {
		$this->site_transients = $site_transients;
		$this->options         = $options;
		$this->file_cache      = $file_cache;

		// Avatar handlers.
		$this->handlers[ Avatar_Handler::GRAVATAR ]       = $gravatar;
		$this->handlers[ Avatar_Handler::USER_AVATAR ]    = $user_avatar;
		$this->handlers[ Avatar_Handler::DEFAULT_AVATAR ] = $default_icons;
	}

	/**
	 * Sets up the various hooks for the plugin component.
	 *
	 * @return void
	 */
	public function run() {
		// Add new default avatars.
		\add_filter( 'avatar_defaults', [ $this->handlers[ Avatar_Handler::DEFAULT_AVATAR ], 'avatar_defaults' ] );

		// Generate the correct avatar images.
		\add_filter( 'avatar_privacy_default_icon_url',     [ $this->handlers[ Avatar_Handler::DEFAULT_AVATAR ], 'get_url' ], 10, 4 );
		\add_filter( 'avatar_privacy_gravatar_icon_url',    [ $this->handlers[ Avatar_Handler::GRAVATAR ], 'get_url' ],       10, 4 );
		\add_filter( 'avatar_privacy_user_avatar_icon_url', [ $this->handlers[ Avatar_Handler::USER_AVATAR ], 'get_url' ],    10, 4 );

		// Automatically regenerate missing image files.
		\add_action( 'init',          [ $this, 'add_cache_rewrite_rules' ] );
		\add_action( 'parse_request', [ $this, 'load_cached_avatar' ] );

		// Clean up cache once per day.
		\add_action( 'init', [ $this, 'enable_image_cache_cleanup' ] );
	}

	/**
	 * Add rewrite rules for nice avatar caching.
	 */
	public function add_cache_rewrite_rules() {
		/**
		 * The global WordPress instance.
		 *
		 * @var \WP
		 */
		global $wp;
		$wp->add_query_var( 'avatar-privacy-file' );

		$basedir = \str_replace( \ABSPATH, '', $this->file_cache->get_base_dir() );
		\add_rewrite_rule( "^{$basedir}(.*)", [ 'avatar-privacy-file' => '$matches[1]' ], 'top' );
	}

	/**
	 * Short-circuits WordPress initialization and load displays the cached avatar image.
	 *
	 * @param \WP $wp The WordPress global object.
	 */
	public function load_cached_avatar( \WP $wp ) {
		if ( empty( $wp->query_vars['avatar-privacy-file'] ) || ! \preg_match( '#^([a-z]+)/((?:[0-9a-z]/)*)([a-f0-9]{64})(?:-([0-9]+))?\.(jpg|png|svg)$#i', $wp->query_vars['avatar-privacy-file'], $parts ) ) {
			// Abort early.
			return;
		}

		list(, $type, $subdir, $hash, $size, $extension ) = $parts;

		$file = "{$this->file_cache->get_base_dir()}{$type}/" . ( $subdir ?: '' ) . $hash . ( empty( $size ) ? '' : "-{$size}" ) . ".{$extension}";

		if ( ! \file_exists( $file ) ) {
			// Default size (for SVGs mainly, which ignore it).
			$size = $size ?: 100;

			if ( isset( $this->handlers[ $type ] ) ) {
				$success = $this->handlers[ $type ]->cache_image( $type, $hash, $size, $subdir, $extension );
			} else {
				$success = $this->handlers[ Avatar_Handler::DEFAULT_AVATAR ]->cache_image( $type, $hash, $size, $subdir, $extension );
			}

			if ( ! $success ) {
				/* translators: $file path */
				\wp_die( \esc_html( \sprintf( \__( 'Error generating avatar file %s.', 'avatar-privacy' ), $file ) ) );
			}
		}

		$this->send_image( $file, \DAY_IN_SECONDS, Images\Type::CONTENT_TYPE[ $extension ] );

		// We're done.
		$this->exit_request();
	}

	/**
	 * Sends an image file to the browser.
	 *
	 * @since 2.1.0 Visibility changed to protected.
	 *
	 * @param  string $file         The full path to the image.
	 * @param  int    $cache_time   The time the image should be cached by the brwoser (in seconds).
	 * @param  string $content_type The content MIME type.
	 */
	protected function send_image( $file, $cache_time, $content_type ) {
		$image = @\file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, Generic.PHP.NoSilencedErrors.Discouraged

		if ( ! empty( $image ) ) {
			$length        = \strlen( $image );
			$last_modified = \filemtime( $file );

			// Let's set some HTTP headers.
			\header( "Content-Type: {$content_type}" );
			\header( "Content-Length: {$length}" );
			\header( 'Last-Modified: ' . \gmdate( 'D, d M Y H:i:s \G\M\T', $last_modified ) );
			\header( 'Expires: ' . \gmdate( 'D, d M Y H:i:s \G\M\T', \time() + $cache_time ) );

			// Here comes the content.
			echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			/* translators: $file path */
			\wp_die( \esc_html( \sprintf( \__( 'Error generating avatar file %s.', 'avatar-privacy' ), $file ) ) );
		}
	}

	/**
	 * Schedules a cron job to clean up the image cache once per day. Otherwise the
	 * cache would grow unchecked and new avatar images uploaded to Gravatar.com
	 * would not be picked up.
	 */
	public function enable_image_cache_cleanup() {
		// Schedule our cron action.
		if ( ! \wp_next_scheduled( self::CRON_JOB_ACTION ) ) {
			\wp_schedule_event( \time(), 'daily', self::CRON_JOB_ACTION );
		}

		// Add separate jobs for gravatars other images.
		\add_action( self::CRON_JOB_ACTION, [ $this, 'trim_gravatar_cache' ] );
		\add_action( self::CRON_JOB_ACTION, [ $this, 'trim_image_cache' ] );
	}

	/**
	 * Deletes cached gravatar images that are too old. Uses a site transient to ensure
	 * that the clean-up happens only once per day on multisite installations.
	 */
	public function trim_gravatar_cache() {
		if ( ! $this->site_transients->get( self::CRON_JOB_LOCK_GRAVATARS ) ) {
			/**
			 * Filters how long cached gravatar images are kept.
			 *
			 * @param int $max_age The maximum age of the cached files (in seconds). Default 2 days.
			 */
			$max_age = \apply_filters( 'avatar_privacy_gravatars_max_age', 2 * \DAY_IN_SECONDS );

			/**
			 * Filters how often the clean-up cron job for old gravatar images should run.
			 *
			 * @param int $interval Time until the cron job should run again (in seconds). Default 1 day.
			 */
			$interval = \apply_filters( 'avatar_privacy_gravatars_cleanup_interval', \DAY_IN_SECONDS );

			$this->invalidate_cached_images( self::CRON_JOB_LOCK_GRAVATARS, 'gravatar', $interval, $max_age );
		}
	}

	/**
	 * Deletes cached image files that are too old. Uses a site transient to ensure
	 * that the clean-up happens only once per day on multisite installations.
	 */
	public function trim_image_cache() {
		if ( ! $this->site_transients->get( self::CRON_JOB_LOCK_ALL_IMAGES ) ) {
			/**
			 * Filters how long cached images are kept.
			 *
			 * Normally, generated default icons and local avatar images don't
			 * change, so they can be kept longer than gravatars. To keep cache
			 * size under control, the limit should be set approximately between
			 * a week and a month, depending on the number of commenters on your
			 * site.
			 *
			 * @param int $max_age The maximum age of the cached files (in seconds). Default 1 week.
			 */
			$max_age = \apply_filters( 'avatar_privacy_all_images_max_age', 7 * \DAY_IN_SECONDS );

			/**
			 * Filters how often the clean-up cron job for old images should run.
			 *
			 * @param int $interval Time until the cron job should run again (in seconds). Default 1 week.
			 */
			$interval = \apply_filters( 'avatar_privacy_all_images_cleanup_interval', 7 * \DAY_IN_SECONDS );

			$this->invalidate_cached_images( self::CRON_JOB_LOCK_ALL_IMAGES, '', $interval, $max_age );
		}
	}

	/**
	 * Removes all files older than the maximum age from given subdirectory.
	 *
	 * @since 2.1.0 Visibility changed to protected.
	 *
	 * @param  string $lock     The site transient key for ensuring that the job is not run more often than necessary.
	 * @param  string $subdir   The subdirectory to clean.
	 * @param  int    $interval The cron job run interval in seconds.
	 * @param  int    $max_age  The maximum age of the image files in seconds.
	 */
	protected function invalidate_cached_images( $lock, $subdir, $interval, $max_age ) {
		// Invalidate all files in the subdirectory older than the maximum age.
		$this->file_cache->invalidate_files_older_than( $max_age, $subdir );

		// Don't run the job again until the interval is up.
		$this->site_transients->set( $lock, true, $interval );
	}

	/**
	 * Stops executing the current request early.
	 *
	 * @since 2.1.0
	 * @codeCoverageIgnore
	 *
	 * @param  int $status Optional. A status code in the range 0 to 254. Default 0.
	 */
	protected function exit_request( $status = 0 ) {
		exit( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
