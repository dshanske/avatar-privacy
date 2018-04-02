<?php
/**
 * This file is part of Avatar Privacy.
 *
 * Copyright 2018 Peter Putzer.
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

namespace Avatar_Privacy\Default_Icons;

use Avatar_Privacy\Data_Storage\Filesystem_Cache;

use Bitverse\Identicon\Color\Color;
use Bitverse\Identicon\Generator\PixelsGenerator;

/**
 * An icon provider for "retro" 8-bit style icons.
 *
 * @since 1.0.0
 *
 * @author Peter Putzer <github@mundschenk.at>
 */
class Retro_Icon_Provider extends Abstract_Icon_Provider {

	/**
	 * The filesystem cache handler.
	 *
	 * @var Filesystem_Cache
	 */
	private $file_cache;

	/**
	 * The icon generator.
	 *
	 * @var \Bitverse\Identicon\Generator\GeneratorInterface
	 */
	private $generator;

	/**
	 * Creates a new instance.
	 *
	 * @param Filesystem_Cache $file_cache  The file cache handler.
	 */
	public function __construct( Filesystem_Cache $file_cache ) {
		parent::__construct( [ 'retro' ] );

		$this->file_cache = $file_cache;
		$this->generator  = new PixelsGenerator();
	}

	/**
	 * Retrieves the default icon.
	 *
	 * @param  string $identity The identity (mail address) hash. Ignored.
	 * @param  string $size     The requested size in pixels.
	 *
	 * @return string
	 */
	public function get_icon_url( $identity, $size ) {
		$filename = "retro/$identity.svg";

		$this->generator->setBackgroundColor( Color::parseHex( '#' . \substr( md5( $identity ), 0, 6 ) ) );
		$icon = $this->generator->generate( $identity );

		$this->file_cache->set( $filename, $icon );

		return $this->file_cache->get_url( $filename );
	}
}
