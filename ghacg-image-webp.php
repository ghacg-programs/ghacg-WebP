<?php
/**
 * Plugin Name: GHACG Image WebP
 * Description: Converts supported uploaded images to WebP before WordPress stores them.
 * Version: 1.0.0
 * Copyright: 2026 GHACG Programs
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: ghacg-image-webp
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/ghacg-image-webp/class-ghacg-image-webp.php';

GHACG_Image_WebP::init();
