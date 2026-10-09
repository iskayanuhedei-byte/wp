<?php
/** Idempotent initialization of the isolated development WordPress installation. */
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8088';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	$installed = wp_install( 'Thumbnail Source Repair Development', 'wtsr-local', 'dev@example.test', true, '', wp_generate_password( 40, true, true ) );
	if ( is_wp_error( $installed ) ) {
		fwrite( STDERR, "WordPress initialization failed.\n" );
		exit( 1 );
	}
	echo "WordPress installed. Local administrator password generated; no production credentials required.\n";
}
update_option( 'home', 'http://127.0.0.1:8088' );
update_option( 'siteurl', 'http://127.0.0.1:8088' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin( 'thumbnail-source-repair/thumbnail-source-repair.php' );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, "Plugin activation failed: " . $result->get_error_message() . "\n" );
	exit( 1 );
}
echo "WordPress and Thumbnail Source Repair are initialized.\n";
