<?php
/**
 * Plugin Name: Thumbnail Source Repair
 * Description: Memeriksa gambar unggulan posting, memulihkannya dari sumber yang terkait, dan menghapus permanen posting tanpa gambar hanya melalui tindakan admin.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Thumbnail Source Repair Contributors
 * License: GPL-2.0-or-later
 * Text Domain: thumbnail-source-repair
 */

defined( 'ABSPATH' ) || exit;

define( 'WTSR_VERSION', '1.0.0' );
define( 'WTSR_FILE', __FILE__ );
define( 'WTSR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WTSR_URL', plugin_dir_url( __FILE__ ) );

require_once WTSR_PATH . 'includes/class-resolver.php';
require_once WTSR_PATH . 'includes/class-runner.php';
require_once WTSR_PATH . 'includes/class-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( is_admin() ) {
			new \WTSR\Runner();
			new \WTSR\Admin();
		}
	}
);
