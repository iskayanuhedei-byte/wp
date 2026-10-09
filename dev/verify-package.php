<?php
/** Exercise WordPress' own ZIP extraction and plugin-header parsing. */
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! WP_Filesystem() || empty( $argv[1] ) ) { exit( 1 ); }
$target = trailingslashit( get_temp_dir() ) . 'wtsr-package-' . wp_generate_uuid4();
wp_mkdir_p( $target );
$status = 0;
try {
    $result = unzip_file( $argv[1], $target );
    if ( is_wp_error( $result ) ) { throw new RuntimeException( 'WordPress could not extract the ZIP.' ); }
    $main = $target . '/thumbnail-source-repair/thumbnail-source-repair.php';
    if ( ! is_readable( $main ) ) { throw new RuntimeException( 'Plugin root file is missing or unreadable.' ); }
    $data = get_plugin_data( $main, false, false );
    if ( 'Thumbnail Source Repair' !== $data['Name'] || '1.0.0' !== $data['Version'] ) { throw new RuntimeException( 'Plugin header did not match.' ); }
    foreach ( array( 'includes/class-resolver.php', 'includes/class-runner.php', 'includes/class-admin.php', 'assets/admin.js', 'assets/admin.css' ) as $file ) {
        if ( ! is_readable( dirname( $main ) . '/' . $file ) ) { throw new RuntimeException( 'A runtime file is absent.' ); }
    }
    if ( file_exists( dirname( $main ) . '/tests' ) ) { throw new RuntimeException( 'Development tests must not be included in the ZIP.' ); }
    echo "WordPress ZIP extraction and plugin headers verified.\n";
} catch ( Throwable $error ) {
    fwrite( STDERR, $error->getMessage() . "\n" );
    $status = 1;
} finally {
    global $wp_filesystem;
    $wp_filesystem->delete( $target, true );
}
exit( $status );
