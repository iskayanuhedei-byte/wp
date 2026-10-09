<?php
/** Use the session's supported proxy, without storing authentication values in code. */
$wtsr_proxy = getenv( 'HTTPS_PROXY' ) ?: getenv( 'https_proxy' );
$wtsr_proxy_parts = $wtsr_proxy ? parse_url( $wtsr_proxy ) : false;
if ( is_array( $wtsr_proxy_parts ) && ! empty( $wtsr_proxy_parts['host'] ) ) {
    define( 'WP_PROXY_HOST', $wtsr_proxy_parts['host'] );
    define( 'WP_PROXY_PORT', isset( $wtsr_proxy_parts['port'] ) ? (int) $wtsr_proxy_parts['port'] : 8080 );
    define( 'WP_PROXY_BYPASS_HOSTS', 'localhost,127.0.0.1,::1' );
    if ( isset( $wtsr_proxy_parts['user'] ) ) { define( 'WP_PROXY_USERNAME', rawurldecode( $wtsr_proxy_parts['user'] ) ); }
    if ( isset( $wtsr_proxy_parts['pass'] ) ) { define( 'WP_PROXY_PASSWORD', rawurldecode( $wtsr_proxy_parts['pass'] ) ); }
}
unset( $wtsr_proxy, $wtsr_proxy_parts );
