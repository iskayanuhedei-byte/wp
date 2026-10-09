<?php
/** Environment-only CA trust; excluded from the distributed plugin ZIP. */
defined( 'ABSPATH' ) || exit;
add_filter( 'http_request_args', static function ( $args ) {
    if ( is_readable( '/run/wtsr-ca-bundle.pem' ) ) {
        $args['sslcertificates'] = '/run/wtsr-ca-bundle.pem';
    }
    return $args;
} );
