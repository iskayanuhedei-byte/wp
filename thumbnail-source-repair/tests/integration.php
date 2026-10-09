<?php
/** Run against a disposable WordPress installation: php tests/integration.php /path/to/wordpress */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || ! is_file( rtrim( $argv[1], '/\\' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Penggunaan: php tests/integration.php /path/to/wordpress\n" );
	exit( 2 );
}
define( 'WP_USE_THEMES', false );
define( 'DOING_AJAX', true );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname( __DIR__ ) . '/includes/class-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-runner.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';

class WTSR_Test_Ajax_Die extends RuntimeException {}

class WTSR_Integration {
	private $posts = array();
	private $attachments = array();
	private $users = array();
	private $responses = array();
	private $requests = array();
	private $saved_hosts;
	private $hosts_existed;
	private $original_user;
	private $admin;
	private $prefix;
	private $passed = 0;
	private $failed = 0;
	private $resolver;
	private $runner;

	public function run() {
		$this->prefix = 'wtsr-test-' . bin2hex( random_bytes( 5 ) );
		$this->original_user = get_current_user_id();
		$sentinel = new stdClass();
		$this->saved_hosts = get_option( 'wtsr_trusted_hosts', $sentinel );
		$this->hosts_existed = $sentinel !== $this->saved_hosts;
		$this->admin = $this->user( 'administrator' );
		wp_set_current_user( $this->admin );
		update_option( 'wtsr_trusted_hosts', array(), false );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		$this->resolver = new \WTSR\Resolver();
		$this->runner = new \WTSR\Runner( $this->resolver );
		try {
			$this->resolver_tests();
			$this->runner_tests();
			$this->ajax_tests();
			$this->source_editor_tests();
		} finally {
			$this->cleanup();
		}
		fwrite( STDOUT, "\n{$this->passed} lulus; {$this->failed} gagal. Fixture dibersihkan.\n" );
		return $this->failed ? 1 : 0;
	}

	private function test( $name, $callback ) {
		$this->responses = array();
		$this->requests = array();
		wp_set_current_user( $this->admin );
		update_option( 'wtsr_trusted_hosts', array(), false );
		try {
			$callback();
			++$this->passed;
			fwrite( STDOUT, "PASS $name\n" );
		} catch ( Throwable $error ) {
			++$this->failed;
			fwrite( STDOUT, "FAIL $name: " . $error->getMessage() . "\n" );
		}
	}

	private function assert( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	private function state( $result, $expected ) {
		$actual = is_wp_error( $result ) ? 'WP_Error:' . $result->get_error_code() : ( isset( $result['state'] ) ? $result['state'] : 'invalid response' );
		$this->assert( $expected === $actual, "Expected $expected; received $actual" );
		$this->assert( ! empty( $result['message'] ) && is_array( $result['evidence'] ), 'Result requires a message and evidence array.' );
	}

	private function post( $content = '', $status = 'publish' ) {
		$id = wp_insert_post( array( 'post_title' => $this->prefix . ' ' . count( $this->posts ), 'post_content' => $content, 'post_status' => $status, 'post_type' => 'post', 'post_author' => $this->admin ), true );
		$this->assert( ! is_wp_error( $id ) && $id > 0, 'Unable to create fixture post.' );
		$this->posts[] = $id;
		return $id;
	}

	private function user( $role ) {
		$id = wp_insert_user( array( 'user_login' => $this->prefix . '-' . $role, 'user_pass' => wp_generate_password(), 'role' => $role ) );
		$this->assert( ! is_wp_error( $id ), 'Unable to create fixture user.' );
		$this->users[] = $id;
		return $id;
	}

	private function png( $width = 320, $height = 240 ) {
		$chunk = function ( $type, $data ) {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};
		$pixels = str_repeat( "\x00" . str_repeat( "\xE6\x42\x36", $width ), $height );
		return "\x89PNG\x0D\x0A\x1A\x0A" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( $pixels ) ) . $chunk( 'IEND', '' );
	}

	private function attachment( $parent = 0, $name = '' ) {
		$upload = wp_upload_bits( $this->prefix . '-' . count( $this->attachments ) . $name . '.png', null, $this->png() );
		$this->assert( empty( $upload['error'] ), 'Unable to write fixture image.' );
		$id = wp_insert_attachment( array( 'post_title' => $this->prefix . ' image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $upload['file'], $parent, true );
		$this->assert( ! is_wp_error( $id ), 'Unable to insert fixture attachment.' );
		$this->attachments[] = $id;
		$metadata = wp_generate_attachment_metadata( $id, $upload['file'] );
		$this->assert( ! is_wp_error( $metadata ) && ! empty( $metadata['width'] ), 'A working GD/Imagick image editor is required.' );
		wp_update_attachment_metadata( $id, $metadata );
		return $id;
	}

	private function snapshot() {
		global $wpdb;
		return hash( 'sha256', serialize( array( $wpdb->get_results( "SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A ) ) ) );
	}

	private function source( $post, $url, $html ) {
		update_post_meta( $post, '_wtsr_source_url', $url );
		$this->response( $url, $html, 'text/html; charset=utf-8' );
	}

	private function response( $url, $body, $type = 'image/png', $code = 200 ) {
		$this->responses[ $url ] = array( 'body' => $body, 'type' => $type, 'code' => $code );
	}

	public function http( $preempt, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		if ( ! isset( $this->responses[ $url ] ) ) {
			return new WP_Error( 'wtsr_fixture_blocked', 'External HTTP disabled by integration fixtures.' );
		}
		$fixture = $this->responses[ $url ];
		if ( is_wp_error( $fixture ) ) {
			return $fixture;
		}
		$body = $fixture['body'];
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $body );
			$body = '';
		}
		return array( 'headers' => array( 'content-type' => $fixture['type'], 'content-length' => strlen( $fixture['body'] ) ), 'body' => $body, 'response' => array( 'code' => $fixture['code'], 'message' => 200 === $fixture['code'] ? 'OK' : 'Not Found' ), 'cookies' => array(), 'filename' => isset( $args['filename'] ) ? $args['filename'] : null );
	}

	private function resolver_tests() {
		$this->test( 'Healthy featured image is preserved', function () {
			$post = $this->post();
			$image = $this->attachment();
			set_post_thumbnail( $post, $image );
			$before = $this->snapshot();
			$this->state( $this->resolver->inspect( $post ), 'healthy' );
			$this->state( $this->resolver->apply( $post ), 'healthy' );
			$this->assert( $before === $this->snapshot(), 'Healthy post/media were mutated.' );
		} );
		$this->test( 'Unrelated library media are never a fallback', function () {
			$this->attachment();
			$post = $this->post( '<p>Artikel tanpa gambar.</p>' );
			$this->state( $this->resolver->inspect( $post ), 'missing' );
			$this->assert( ! get_post_thumbnail_id( $post ), 'An unrelated image was selected.' );
		} );
		$this->test( 'Preview of embedded local image causes no database changes', function () {
			$image = $this->attachment();
			$post = $this->post( '<img class="wp-image-' . $image . '" src="' . esc_url( wp_get_attachment_url( $image ) ) . '">' );
			$before = $this->snapshot();
			$result = $this->resolver->inspect( $post );
			$this->state( $result, 'repairable' );
			$this->assert( $image === (int) $result['attachment_id'], 'Preview selected the wrong media.' );
			$this->assert( $before === $this->snapshot(), 'Preview mutated posts or media.' );
		} );
		$this->test( 'Embedded image ID recovers the correct attachment', function () {
			$image = $this->attachment();
			$post = $this->post( '<figure><img class="wp-image-' . $image . '" src="' . esc_url( wp_get_attachment_url( $image ) ) . '"></figure>' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( $image === (int) get_post_thumbnail_id( $post ), 'The wrong featured image was assigned.' );
		} );
		$this->test( 'Gutenberg attachment ID is an authoritative candidate', function () {
			$image = $this->attachment();
			$post = $this->post( '<!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"></figure><!-- /wp:image -->' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( $image === (int) get_post_thumbnail_id( $post ), 'Gutenberg attachment was not recovered.' );
		} );
		$this->test( 'Gutenberg gallery attachment IDs can supply the featured image', function () {
			$image = $this->attachment();
			$post = $this->post( '<!-- wp:gallery {"ids":[' . $image . ']} --><figure></figure><!-- /wp:gallery -->' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( $image === (int) get_post_thumbnail_id( $post ), 'Gutenberg gallery image was not recovered.' );
		} );
		$this->test( 'WordPress gallery shortcode image is an authoritative candidate', function () {
			$image = $this->attachment();
			$post = $this->post( '[gallery ids="' . $image . '"]' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( $image === (int) get_post_thumbnail_id( $post ), 'Gallery shortcode image was not recovered.' );
		} );
		$this->test( 'A matching local URL reuses media without a network request', function () {
			$image = $this->attachment();
			$post = $this->post( '<img src="' . esc_url( wp_get_attachment_url( $image ) ) . '">' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( $image === (int) get_post_thumbnail_id( $post ) && ! count( $this->requests ), 'Local image should reuse the existing attachment.' );
		} );
		$this->test( 'Attachment belonging to the post can be recovered', function () {
			$post = $this->post();
			$image = $this->attachment( $post );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( $image === (int) get_post_thumbnail_id( $post ), 'Child attachment was not recovered.' );
		} );
		$this->test( 'Broken featured attachment is replaced by valid content evidence', function () {
			$broken = $this->attachment();
			$image = $this->attachment();
			$post = $this->post( '<img class="wp-image-' . $image . '" src="' . esc_url( wp_get_attachment_url( $image ) ) . '">' );
			update_post_meta( $post, '_thumbnail_id', $broken );
			unlink( get_attached_file( $broken ) );
			$broken_metadata = wp_get_attachment_metadata( $broken );
			foreach ( $broken_metadata['sizes'] as $size ) {
				$path = dirname( get_attached_file( $broken ) ) . '/' . $size['file'];
				if ( is_file( $path ) ) { unlink( $path ); }
			}
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( $image === (int) get_post_thumbnail_id( $post ), 'Broken featured image was not replaced.' );
		} );
		$this->test( 'Missing generated thumbnail is regenerated from the valid original', function () {
			$post = $this->post();
			$image = $this->attachment();
			set_post_thumbnail( $post, $image );
			$metadata = wp_get_attachment_metadata( $image );
			$this->assert( isset( $metadata['sizes']['thumbnail']['file'] ), 'Fixture requires generated thumbnail metadata.' );
			$path = dirname( get_attached_file( $image ) ) . '/' . $metadata['sizes']['thumbnail']['file'];
			unlink( $path );
			$before = $this->snapshot();
			$this->state( $this->resolver->inspect( $post ), 'repairable' );
			$this->assert( $before === $this->snapshot() && ! is_file( $path ), 'Preview repaired media.' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			clearstatcache( true, $path );
			$this->assert( is_file( $path ), 'Generated thumbnail file is still missing.' );
			$this->state( $this->resolver->inspect( $post ), 'healthy' );
		} );
		$this->test( 'Retained generated image recovers a missing original without changing shared media', function () {
			$image = $this->attachment();
			$metadata = wp_get_attachment_metadata( $image );
			$url = wp_get_attachment_image_src( $image, 'thumbnail' );
			$this->assert( ! empty( $url[0] ), 'Fixture requires a generated image URL.' );
			$post = $this->post( '<img class="wp-image-' . $image . '" src="' . esc_url( $url[0] ) . '">' );
			$shared = $this->post();
			set_post_thumbnail( $shared, $image );
			unlink( get_attached_file( $image ) );
			$this->state( $this->resolver->inspect( $post ), 'repairable' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$recovered = (int) get_post_thumbnail_id( $post );
			$this->attachments[] = $recovered;
			$this->assert( $recovered > 0 && $recovered !== $image && is_file( get_attached_file( $recovered ) ), 'Retained image was not imported as valid media.' );
			$this->assert( $metadata === wp_get_attachment_metadata( $image ) && $image === (int) get_post_thumbnail_id( $shared ), 'Shared attachment was changed during recovery.' );
			$this->state( $this->resolver->inspect( $post ), 'healthy' );
		} );
		$this->test( 'Remote content image preview verifies bytes but does not import media', function () {
			$url = 'https://example.com/wtsr/content-preview.png';
			$post = $this->post( '<img data-src="' . $url . '">' );
			$this->response( $url, $this->png() );
			$before = $this->snapshot();
			$this->state( $this->resolver->inspect( $post ), 'repairable' );
			$this->assert( $before === $this->snapshot(), 'Remote preview imported attachment metadata.' );
			$this->assert( ! get_post_thumbnail_id( $post ), 'Remote preview assigned a featured image.' );
		} );
		$this->test( 'Remote embedded image is imported and produces a working thumbnail', function () {
			$url = 'https://example.com/wtsr/content-image.png';
			$post = $this->post( '<img src="' . $url . '">' );
			$this->response( $url, $this->png() );
			$result = $this->resolver->apply( $post );
			$this->state( $result, 'fixed' );
			$image = (int) get_post_thumbnail_id( $post );
			$this->attachments[] = $image;
			$this->assert( $image > 0 && is_file( get_attached_file( $image ) ), 'Imported featured image has no valid file.' );
			$this->assert( false !== wp_get_attachment_image_src( $image, 'thumbnail' ), 'Imported featured image does not render.' );
			$this->assert( false !== strpos( implode( ' ', $result['evidence'] ), 'example.com' ), 'Recovery evidence omits the source.' );
			foreach ( $this->requests as $request ) {
				$this->assert( ! empty( $request['args']['sslverify'] ) && ! empty( $request['args']['reject_unsafe_urls'] ), 'TLS or SSRF checks were disabled.' );
			}
		} );
		$this->test( 'Article og:image is accepted from the article host', function () {
			$post = $this->post();
			$image_url = 'https://example.com/wtsr/article.png';
			$this->source( $post, 'https://example.com/wtsr/article', '<html><head><meta property="og:image" content="' . $image_url . '"></head></html>' );
			$this->response( $image_url, $this->png() );
			$result = $this->resolver->apply( $post );
			$this->state( $result, 'fixed' );
			$this->attachments[] = (int) get_post_thumbnail_id( $post );
			$this->assert( false !== strpos( implode( ' ', $result['evidence'] ), '/article' ), 'Article provenance was lost.' );
		} );
		$this->test( 'Article JSON-LD Article.image is supported', function () {
			$post = $this->post();
			$image_url = 'https://example.com/wtsr/jsonld.png';
			$this->source( $post, 'https://example.com/wtsr/jsonld', '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","image":{"@type":"ImageObject","url":"' . $image_url . '"}}</script>' );
			$this->response( $image_url, $this->png() );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->attachments[] = (int) get_post_thumbnail_id( $post );
		} );
		$this->test( 'A different image host requires explicit trust', function () {
			$post = $this->post();
			$image_url = 'https://example.org/wtsr/cdn.png';
			$this->source( $post, 'https://example.com/wtsr/cdn-article', '<meta name="twitter:image" content="' . $image_url . '">' );
			$this->response( $image_url, $this->png() );
			$this->state( $this->resolver->apply( $post ), 'retry' );
			$this->assert( ! get_post_thumbnail_id( $post ), 'An untrusted CDN image was imported.' );
			$this->assert( ! in_array( $image_url, array_column( $this->requests, 'url' ), true ), 'An untrusted host was fetched.' );
			update_option( 'wtsr_trusted_hosts', array( 'example.org' ), false );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->attachments[] = (int) get_post_thumbnail_id( $post );
		} );
		$this->test( 'An article with no image is missing rather than using its logo', function () {
			$post = $this->post();
			$this->source( $post, 'https://example.com/wtsr/no-image', '<html><head></head><body><img class="site-logo" src="https://example.com/logo.png"></body></html>' );
			$this->state( $this->resolver->apply( $post ), 'missing' );
			$this->assert( ! get_post_thumbnail_id( $post ), 'The article logo was selected.' );
		} );
		$this->test( 'A timeout preserves the post and does not create media', function () {
			$url = 'https://example.com/wtsr/timeout.png';
			$post = $this->post( '<img src="' . $url . '">' );
			$this->responses[ $url ] = new WP_Error( 'http_request_failed', 'Connection timed out.' );
			$before = $this->snapshot();
			$this->state( $this->resolver->apply( $post ), 'retry' );
			$this->assert( get_post( $post ) && $before === $this->snapshot(), 'Timeout mutated or deleted the post.' );
		} );
		$this->test( 'Confirmed HTTP 404 is missing', function () {
			$url = 'https://example.com/wtsr/not-found.png';
			$post = $this->post( '<img src="' . $url . '">' );
			$this->response( $url, 'Not Found', 'text/plain', 404 );
			$this->state( $this->resolver->apply( $post ), 'missing' );
		} );
		$this->test( 'Non-image response is never assigned as featured media', function () {
			$url = 'https://example.com/wtsr/html.png';
			$post = $this->post( '<img src="' . $url . '">' );
			$this->response( $url, '<html>not an image</html>', 'text/html' );
			$result = $this->resolver->apply( $post );
			$this->assert( in_array( $result['state'], array( 'missing', 'retry' ), true ) && ! get_post_thumbnail_id( $post ), 'HTML masquerading as an image was imported.' );
		} );
		$this->test( 'Images exceeding 10 MiB are not imported', function () {
			$url = 'https://example.com/wtsr/oversized.png';
			$post = $this->post( '<img src="' . $url . '">' );
			$this->response( $url, $this->png() . str_repeat( "\0", 11 * 1024 * 1024 ) );
			$this->state( $this->resolver->apply( $post ), 'retry' );
			$this->assert( ! get_post_thumbnail_id( $post ), 'Oversized image was imported.' );
		} );
		$this->test( 'Private-network source URL is rejected without losing the post', function () {
			$post = $this->post();
			update_post_meta( $post, '_wtsr_source_url', 'https://127.0.0.1/private/article' );
			$this->state( $this->resolver->apply( $post ), 'retry' );
			$this->assert( get_post( $post ) && ! get_post_thumbnail_id( $post ), 'Private source URL was used.' );
		} );
		$this->test( 'Insecure article source URL is rejected', function () {
			$post = $this->post();
			update_post_meta( $post, '_wtsr_source_url', 'http://example.com/wtsr/insecure' );
			$this->state( $this->resolver->apply( $post ), 'retry' );
			$this->assert( ! count( $this->requests ), 'An insecure source URL was fetched.' );
		} );
		$this->test( 'Unsupported raster MIME remains retry rather than confirmed missing', function () {
			$url = 'https://example.com/wtsr/unsupported.bmp';
			$post = $this->post( '<img src="' . $url . '">' );
			$bmp = 'BM' . pack( 'VvvV', 58, 0, 0, 54 ) . pack( 'V3v2V6', 40, 1, 1, 1, 24, 0, 4, 0, 0, 0, 0 ) . "\x00\x00\xFF\x00";
			$this->response( $url, $bmp, 'image/bmp' );
			$this->state( $this->resolver->apply( $post ), 'retry' );
			$this->assert( get_post( $post ) && ! get_post_thumbnail_id( $post ), 'Unsupported raster was imported or lost.' );
		} );
		$this->test( 'Local media reuse cannot bypass article-host provenance checks', function () {
			$image = $this->attachment();
			$post = $this->post();
			$this->source( $post, 'https://example.com/wtsr/local-bypass', '<meta property="og:image" content="' . esc_url( wp_get_attachment_url( $image ) ) . '">' );
			$this->state( $this->resolver->apply( $post ), 'retry' );
			$this->assert( ! get_post_thumbnail_id( $post ), 'Local attachment bypassed source-host checks.' );
		} );
		$this->test( 'Corrupt generated image is rebuilt from the valid original', function () {
			$post = $this->post();
			$image = $this->attachment();
			set_post_thumbnail( $post, $image );
			$metadata = wp_get_attachment_metadata( $image );
			$path = dirname( get_attached_file( $image ) ) . '/' . $metadata['sizes']['thumbnail']['file'];
			file_put_contents( $path, 'Corrupted nonempty thumbnail fixture' );
			$this->state( $this->resolver->inspect( $post ), 'repairable' );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$this->assert( 'image/png' === wp_get_image_mime( $path ), 'Generated thumbnail is still corrupt.' );
		} );
		$this->test( 'Reserved and link-local source URLs are never fetched', function () {
			foreach ( array( '169.254.169.254', '192.0.0.1', '198.18.0.1', '100.64.0.1', '[fc00::1]' ) as $host ) {
				$post = $this->post();
				update_post_meta( $post, '_wtsr_source_url', 'https://' . $host . '/private' );
				$this->state( $this->resolver->apply( $post ), 'retry' );
				$this->assert( ! get_post_thumbnail_id( $post ), 'A reserved address supplied an image.' );
			}
			$this->assert( ! count( $this->requests ), 'A reserved address reached the HTTP transport.' );
		} );
		$this->test( 'Lazy image placeholder media cannot replace its real data-src image', function () {
			$placeholder = $this->attachment( 0, '-placeholder' );
			$url = 'https://example.com/wtsr/real-lazy-image.png';
			$post = $this->post( '<img class="wp-image-' . $placeholder . '" src="' . esc_url( wp_get_attachment_url( $placeholder ) ) . '" data-src="' . $url . '">' );
			$this->response( $url, $this->png() );
			$this->state( $this->resolver->apply( $post ), 'fixed' );
			$image = (int) get_post_thumbnail_id( $post );
			$this->attachments[] = $image;
			$this->assert( $image > 0 && $image !== $placeholder, 'The local placeholder was used instead of the real image.' );
		} );
		$this->test( 'Excessive JSON-LD nesting is ambiguous and preserves the post', function () {
			$post = $this->post();
			$data = array( '@type' => 'Article', 'image' => 'https://example.com/wtsr/deep.png' );
			for ( $depth = 0; $depth < 30; ++$depth ) { $data = array( 'nested' => $data ); }
			$this->source( $post, 'https://example.com/wtsr/deep', '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>' );
			$this->state( $this->resolver->apply( $post ), 'retry' );
		} );
		$this->test( 'JSON-LD article identity preserves distinguishing query parameters', function () {
			$post = $this->post();
			$url = 'https://example.com/wtsr/wrong-story.png';
			$this->source( $post, 'https://example.com/wtsr/story?id=123', '<script type="application/ld+json">{"@type":"Article","url":"https://example.com/wtsr/story?id=999","image":"' . $url . '"}</script>' );
			$this->response( $url, $this->png() );
			$result = $this->resolver->apply( $post );
			$this->assert( in_array( $result['state'], array( 'missing', 'retry' ), true ) && ! get_post_thumbnail_id( $post ), 'An image from a different article query was accepted.' );
			$this->assert( ! in_array( $url, array_column( $this->requests, 'url' ), true ), 'Wrong article image was fetched.' );
		} );
		$this->test( 'Unresolved JSON-LD image graph references are retry rather than missing', function () {
			$post = $this->post();
			$this->source( $post, 'https://example.com/wtsr/graph', '<script type="application/ld+json">{"@type":"Article","image":{"@id":"https://example.com/wtsr/graph#primaryimage"}}</script>' );
			$this->state( $this->resolver->apply( $post ), 'retry' );
		} );
	}

	private function runner_tests() {
		$this->test( 'Repair-only mode preserves a confirmed missing post', function () {
			$post = $this->post();
			$this->state( $this->runner->process( $post, 'repair', $this->runner->fingerprint( $post ) ), 'missing' );
			$this->assert( get_post( $post ), 'Repair-only mode deleted a post.' );
		} );
		$this->test( 'Permanent deletion requires explicit confirmation', function () {
			$post = $this->post();
			$result = $this->runner->process( $post, 'repair_delete', $this->runner->fingerprint( $post ), false );
			$this->assert( is_wp_error( $result ) && get_post( $post ), 'Deletion proceeded without confirmation.' );
		} );
		$this->test( 'Confirmed missing post is permanently deleted', function () {
			$post = $this->post();
			$this->state( $this->runner->process( $post, 'repair_delete', $this->runner->fingerprint( $post ), true ), 'deleted' );
			$this->assert( ! get_post( $post ), 'Post was trashed instead of permanently deleted.' );
		} );
		$this->test( 'Recovery in destructive mode preserves a repairable post', function () {
			$image = $this->attachment();
			$post = $this->post( '<img class="wp-image-' . $image . '">' );
			$this->state( $this->runner->process( $post, 'repair_delete', $this->runner->fingerprint( $post ), true ), 'fixed' );
			$this->assert( get_post( $post ) && $image === (int) get_post_thumbnail_id( $post ), 'Recovered post was deleted.' );
		} );
		$this->test( 'Destructive mode preserves posts on network failures', function () {
			$url = 'https://example.com/wtsr/delete-timeout.png';
			$post = $this->post( '<img src="' . $url . '">' );
			$this->responses[ $url ] = new WP_Error( 'http_request_failed', 'Timeout.' );
			$this->state( $this->runner->process( $post, 'repair_delete', $this->runner->fingerprint( $post ), true ), 'retry' );
			$this->assert( get_post( $post ), 'Network failure deleted the post.' );
		} );
		$this->test( 'Destructive mode preserves posts with an untrusted source', function () {
			$post = $this->post();
			$this->source( $post, 'https://example.com/wtsr/delete-untrusted', '<meta property="og:image" content="https://example.org/image.png">' );
			$this->state( $this->runner->process( $post, 'repair_delete', $this->runner->fingerprint( $post ), true ), 'retry' );
			$this->assert( get_post( $post ), 'Untrusted image source deleted the post.' );
		} );
		$this->test( 'A stale scan fingerprint prevents processing', function () {
			$post = $this->post();
			$fingerprint = $this->runner->fingerprint( $post );
			wp_update_post( array( 'ID' => $post, 'post_content' => 'Edited after scan' ) );
			$result = $this->runner->process( $post, 'repair_delete', $fingerprint, true );
			$this->assert( is_wp_error( $result ) && get_post( $post ), 'Stale fingerprint allowed deletion.' );
		} );
		$this->test( 'Changes during resolution cancel deletion', function () {
			$post = $this->post();
			$resolver = new class {
				public function apply( $id ) {
					wp_update_post( array( 'ID' => $id, 'post_content' => 'New editor content during resolution' ) );
					return array( 'state' => 'missing', 'message' => 'Fixture missing', 'evidence' => array(), 'attachment_id' => 0, 'url' => '' );
				}
			};
			$runner = new \WTSR\Runner( $resolver );
			$result = $runner->process( $post, 'repair_delete', $runner->fingerprint( $post ), true );
			$this->assert( is_wp_error( $result ) && get_post( $post ), 'Deletion was not cancelled after an editor race.' );
			$this->assert( false === get_option( 'wtsr_lock_' . $post ), 'Race cancellation leaked a lock.' );
		} );
		$this->test( 'An active lock prevents competing processing', function () {
			$post = $this->post();
			$key = 'wtsr_lock_' . $post;
			$value = wp_json_encode( array( 'token' => 'fixture-active-lock', 'expires' => time() + 900 ) );
			add_option( $key, $value, '', false );
			try {
				$result = $this->runner->process( $post, 'repair_delete', $this->runner->fingerprint( $post ), true );
				$this->assert( is_wp_error( $result ) && get_post( $post ), 'An active lock was bypassed.' );
				$this->assert( $value === get_option( $key ), 'An active lock was stolen.' );
			} finally {
				delete_option( $key );
			}
		} );
		$this->test( 'An expired lock can be reclaimed and released', function () {
			$post = $this->post();
			$key = 'wtsr_lock_' . $post;
			add_option( $key, wp_json_encode( array( 'token' => 'fixture-expired-lock', 'expires' => time() - 1 ) ), '', false );
			$this->state( $this->runner->process( $post, 'repair', $this->runner->fingerprint( $post ) ), 'missing' );
			$this->assert( false === get_option( $key ), 'Expired lock was not released after processing.' );
		} );
		$this->test( 'Resolver exceptions preserve posts and release locks', function () {
			$post = $this->post();
			$resolver = new class { public function apply( $id ) { throw new RuntimeException( 'Fixture failure' ); } };
			$runner = new \WTSR\Runner( $resolver );
			$this->state( $runner->process( $post, 'repair_delete', $runner->fingerprint( $post ), true ), 'retry' );
			$this->assert( get_post( $post ) && false === get_option( 'wtsr_lock_' . $post ), 'Exception deleted post or leaked a lock.' );
		} );
		$this->test( 'Only published standard posts can be processed', function () {
			$post = $this->post( '', 'draft' );
			$result = $this->runner->process( $post, 'repair_delete', $this->runner->fingerprint( $post ), true );
			$this->assert( is_wp_error( $result ) && get_post( $post ), 'Draft post was processed.' );
			$page = $this->post();
			wp_update_post( array( 'ID' => $page, 'post_type' => 'page' ) );
			$result = $this->runner->process( $page, 'repair_delete', $this->runner->fingerprint( $page ), true );
			$this->assert( is_wp_error( $result ) && get_post( $page ), 'Page was processed as a standard post.' );
		} );
		$this->test( 'Administrator and post deletion capabilities are enforced', function () {
			$post = $this->post();
			$fingerprint = $this->runner->fingerprint( $post );
			wp_set_current_user( $this->user( 'subscriber' ) );
			$this->assert( is_wp_error( $this->runner->scan() ), 'Non-administrator could scan.' );
			$this->assert( is_wp_error( $this->runner->process( $post, 'repair_delete', $fingerprint, true ) ) && get_post( $post ), 'Non-administrator could delete.' );
			wp_set_current_user( $this->admin );
			$deny_delete = function ( $caps, $cap ) { return 'delete_post' === $cap ? array( 'do_not_allow' ) : $caps; };
			add_filter( 'map_meta_cap', $deny_delete, 10, 2 );
			try {
				$this->assert( is_wp_error( $this->runner->process( $post, 'repair_delete', $fingerprint, true ) ) && get_post( $post ), 'Missing delete capability was ignored.' );
			} finally {
				remove_filter( 'map_meta_cap', $deny_delete, 10 );
			}
		} );
		$this->test( 'Fingerprint changes with article source and trusted hosts', function () {
			$post = $this->post();
			$before = $this->runner->fingerprint( $post );
			update_post_meta( $post, '_wtsr_source_url', 'https://example.com/article' );
			$after_source = $this->runner->fingerprint( $post );
			$this->assert( $before !== $after_source, 'Source URL is absent from the fingerprint.' );
			update_option( 'wtsr_trusted_hosts', array( 'example.org' ), false );
			$this->assert( $after_source !== $this->runner->fingerprint( $post ), 'Trusted hosts are absent from the fingerprint.' );
		} );
		foreach ( array( 'mediaId', 'ids', 'local URL' ) as $reference_type ) {
			$this->test( 'Deletion detects concurrent non-child attachment change via ' . $reference_type, function () use ( $reference_type ) {
				$image = $this->attachment();
				$content = 'mediaId' === $reference_type ? '<!-- wp:image {"mediaId":' . $image . '} --><!-- /wp:image -->' : ( 'ids' === $reference_type ? '[gallery ids="' . $image . '"]' : '<img src="' . esc_url( wp_get_attachment_url( $image ) ) . '">' );
				$post = $this->post( $content );
				$original = wp_get_attachment_metadata( $image );
				$change = function () use ( $image, $original ) {
					global $wpdb;
					$metadata = $original;
					$metadata['wtsr_race_marker'] = 1;
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => maybe_serialize( $metadata ) ), array( 'post_id' => $image, 'meta_key' => '_wp_attachment_metadata' ) );
				};
				$runner = $this->mutating_runner( $change );
				try {
					$result = $runner->process( $post, 'repair_delete', $runner->fingerprint( $post ), true );
					$this->assert( is_wp_error( $result ) && get_post( $post ), 'Concurrent embedded attachment change allowed deletion.' );
				} finally {
					wp_cache_delete( $image, 'post_meta' );
					wp_update_attachment_metadata( $image, $original );
				}
			} );
		}
		$this->test( 'Deletion detects concurrent trusted-host changes despite cached options', function () {
			$post = $this->post();
			$change = function () {
				global $wpdb;
				$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array( 'example.org' ) ) ), array( 'option_name' => 'wtsr_trusted_hosts' ) );
			};
			$runner = $this->mutating_runner( $change );
			try {
				$result = $runner->process( $post, 'repair_delete', $runner->fingerprint( $post ), true );
				$this->assert( is_wp_error( $result ) && get_post( $post ), 'Concurrent trusted-host change allowed deletion.' );
			} finally {
				wp_cache_delete( 'wtsr_trusted_hosts', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				update_option( 'wtsr_trusted_hosts', array(), false );
			}
		} );
		$this->test( 'Scan includes healthy rows and bounds each ordered batch to five', function () {
			$ids = array();
			for ( $index = 0; $index < 7; ++$index ) {
				$ids[] = $this->post();
				if ( 0 === $index ) {
					set_post_thumbnail( $ids[0], $this->attachment() );
					$this->post( '', 'draft' );
				}
			}
			$before = $this->snapshot();
			$batch = $this->runner->scan( $ids[0] - 1, 100 );
			$this->assert( ! is_wp_error( $batch ) && 5 === count( $batch['items'] ) && ! $batch['done'], 'Scan did not enforce the batch maximum.' );
			$this->assert( array_slice( $ids, 0, 5 ) === array_column( $batch['items'], 'post_id' ), 'Scan ordering or published-post filter is incorrect.' );
			$this->assert( 'healthy' === $batch['items'][0]['state'], 'Healthy scanned rows were omitted.' );
			foreach ( $batch['items'] as $item ) {
				$this->assert( 64 === strlen( $item['fingerprint'] ) && isset( $item['title'], $item['edit_url'], $item['evidence'] ), 'Scan row lacks review data.' );
			}
			$last = $this->runner->scan( $batch['next_cursor'], 5 );
			$this->assert( array_slice( $ids, 5 ) === array_column( $last['items'], 'post_id' ) && $last['done'], 'Scan cursor skipped or repeated posts.' );
			$this->assert( $before === $this->snapshot(), 'Scan mutated posts or attachments.' );
		} );
	}

	public function die_handler() {
		return function () { throw new WTSR_Test_Ajax_Die( 'AJAX terminated' ); };
	}

	private function mutating_runner( $change ) {
		$resolver = new class( $change ) {
			private $change;
			public function __construct( $change ) { $this->change = $change; }
			public function apply( $id ) {
				call_user_func( $this->change );
				return array( 'state' => 'missing', 'message' => 'Fixture missing', 'evidence' => array(), 'attachment_id' => 0, 'url' => '' );
			}
		};
		return new \WTSR\Runner( $resolver );
	}

	private function ajax( $method, $parameters, $request_method = 'POST' ) {
		$saved_post = $_POST;
		$saved_request = $_REQUEST;
		$saved_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
		$_POST = $parameters;
		$_REQUEST = $parameters;
		$_SERVER['REQUEST_METHOD'] = $request_method;
		ob_start();
		try {
			$this->runner->$method();
		} catch ( WTSR_Test_Ajax_Die $expected ) {
			// Capture WordPress's AJAX response without terminating the test process.
		} finally {
			$output = ob_get_clean();
			$_POST = $saved_post;
			$_REQUEST = $saved_request;
			if ( null === $saved_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $saved_method; }
		}
		$response = json_decode( $output, true );
		$this->assert( is_array( $response ) && array_key_exists( 'success', $response ), 'AJAX did not return valid WordPress JSON.' );
		return $response;
	}

	private function ajax_tests() {
		$this->test( 'AJAX rejects GET and missing or invalid nonce', function () {
			$post = $this->post();
			$parameters = array( 'post_id' => $post, 'mode' => 'repair_delete', 'fingerprint' => $this->runner->fingerprint( $post ), 'confirm_delete' => '1' );
			$this->assert( ! $this->ajax( 'ajax_process', $parameters, 'GET' )['success'], 'GET mutation was allowed.' );
			$this->assert( ! $this->ajax( 'ajax_process', $parameters )['success'], 'Missing nonce was allowed.' );
			$parameters['nonce'] = 'invalid-fixture-nonce';
			$this->assert( ! $this->ajax( 'ajax_process', $parameters )['success'] && get_post( $post ), 'Invalid nonce allowed deletion.' );
		} );
		$this->test( 'AJAX requires literal deletion confirmation and allows nonce-authenticated repair', function () {
			$post = $this->post();
			$parameters = array( 'nonce' => wp_create_nonce( 'wtsr_admin' ), 'post_id' => $post, 'mode' => 'repair_delete', 'fingerprint' => $this->runner->fingerprint( $post ), 'confirm_delete' => 'yes' );
			$this->assert( ! $this->ajax( 'ajax_process', $parameters )['success'] && get_post( $post ), 'Nonliteral deletion confirmation was accepted.' );
			$parameters['mode'] = 'repair';
			$response = $this->ajax( 'ajax_process', $parameters );
			$this->assert( $response['success'] && 'missing' === $response['data']['state'] && get_post( $post ), 'Valid repair AJAX request failed.' );
		} );
	}

	private function source_editor_tests() {
		$admin = new \WTSR\Admin();
		$this->test( 'Source metabox cannot save without a valid separate nonce', function () use ( $admin ) {
			$post = $this->post();
			$original = 'https://example.com/wtsr/original';
			update_post_meta( $post, '_wtsr_source_url', $original );
			$saved = $_POST;
			try {
				$_POST = array( 'wtsr_source_url' => 'https://example.com/wtsr/new' );
				$admin->save_source( $post, get_post( $post ) );
				$this->assert( $original === get_post_meta( $post, '_wtsr_source_url', true ), 'Missing source nonce was accepted.' );
				$_POST['wtsr_source_nonce'] = 'invalid-source-nonce';
				$admin->save_source( $post, get_post( $post ) );
				$this->assert( $original === get_post_meta( $post, '_wtsr_source_url', true ), 'Invalid source nonce was accepted.' );
			} finally { $_POST = $saved; }
		} );
		$this->test( 'Source metabox preserves existing source for insecure or credential URLs', function () use ( $admin ) {
			$post = $this->post();
			$original = 'https://example.com/wtsr/original';
			update_post_meta( $post, '_wtsr_source_url', $original );
			$saved = $_POST;
			try {
				foreach ( array( 'http://example.com/insecure', 'https://user:password@example.com/credential' ) as $invalid ) {
					$_POST = array( 'wtsr_source_nonce' => wp_create_nonce( 'wtsr_save_source' ), 'wtsr_source_url' => $invalid );
					$admin->save_source( $post, get_post( $post ) );
					$this->assert( $original === get_post_meta( $post, '_wtsr_source_url', true ), 'Invalid URL replaced the original source.' );
				}
			} finally { $_POST = $saved; }
		} );
		$this->test( 'Source metabox enforces edit capability and supports explicit clearing', function () use ( $admin ) {
			$post = $this->post();
			$saved = $_POST;
			try {
				$subscriber = get_user_by( 'login', $this->prefix . '-subscriber' );
				$this->assert( $subscriber instanceof WP_User, 'Subscriber fixture unavailable.' );
				wp_set_current_user( $subscriber->ID );
				$_POST = array( 'wtsr_source_nonce' => wp_create_nonce( 'wtsr_save_source' ), 'wtsr_source_url' => 'https://example.com/wtsr/new' );
				$admin->save_source( $post, get_post( $post ) );
				$this->assert( '' === get_post_meta( $post, '_wtsr_source_url', true ), 'User without edit capability changed the source.' );
				wp_set_current_user( $this->admin );
				$_POST['wtsr_source_nonce'] = wp_create_nonce( 'wtsr_save_source' );
				$admin->save_source( $post, get_post( $post ) );
				$this->assert( 'https://example.com/wtsr/new' === get_post_meta( $post, '_wtsr_source_url', true ), 'Valid source was not saved.' );
				$_POST['wtsr_source_url'] = '';
				$admin->save_source( $post, get_post( $post ) );
				$this->assert( '' === get_post_meta( $post, '_wtsr_source_url', true ), 'Source was not explicitly cleared.' );
			} finally { $_POST = $saved; wp_set_current_user( $this->admin ); }
		} );
	}

	private function cleanup() {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		$imported = $this->posts ? get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent__in' => $this->posts, 'posts_per_page' => -1, 'fields' => 'ids' ) ) : array();
		foreach ( array_unique( array_merge( $this->attachments, $imported ) ) as $id ) {
			if ( $id ) { wp_delete_attachment( $id, true ); }
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
			delete_option( 'wtsr_lock_' . $id );
		}
		wp_set_current_user( $this->original_user );
		foreach ( $this->users as $id ) {
			delete_transient( 'wtsr_source_error_' . $id );
			wp_delete_user( $id );
		}
		if ( $this->hosts_existed ) { update_option( 'wtsr_trusted_hosts', $this->saved_hosts, false ); } else { delete_option( 'wtsr_trusted_hosts' ); }
	}
}

try {
	exit( ( new WTSR_Integration() )->run() );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Unable to run integration tests: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
