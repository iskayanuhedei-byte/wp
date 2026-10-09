<?php
namespace WTSR;

defined( 'ABSPATH' ) || exit;

/** Bounded admin operations, authorization and deletion concurrency protection. */
class Runner {
	const LOCK_TTL = 900;
	private $resolver;

	public function __construct( $resolver = null ) {
		$this->resolver = $resolver ?: new Resolver();
		add_action( 'wp_ajax_wtsr_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_wtsr_process', array( $this, 'ajax_process' ) );
	}

	public function ajax_scan() {
		$this->authorize_request();
		$cursor = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 5;
		$this->respond( $this->scan( $cursor, $limit ) );
	}

	public function ajax_process() {
		$this->authorize_request();
		$id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$fingerprint = isset( $_POST['fingerprint'] ) && is_string( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '';
		$confirm = isset( $_POST['confirm_delete'] ) && '1' === (string) $_POST['confirm_delete'];
		$this->respond( $this->process( $id, $mode, $fingerprint, $confirm ) );
	}

	private function authorize_request() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			wp_send_json_error( array( 'message' => 'Gunakan permintaan POST.' ), 405 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Hanya administrator dapat menjalankan alat ini.' ), 403 );
		}
		if ( ! check_ajax_referer( 'wtsr_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Sesi pemeriksaan berakhir. Muat ulang halaman.' ), 403 );
		}
	}

	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), $status );
		}
		wp_send_json_success( $result );
	}

	public function scan( $cursor = 0, $limit = 5 ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wtsr_forbidden', 'Hanya administrator dapat memeriksa posting.', array( 'status' => 403 ) );
		}
		$cursor = max( 0, (int) $cursor );
		$limit = max( 1, min( 5, (int) $limit ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d", 'post', 'publish', $cursor, $limit + 1 ) );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'wtsr_database', 'Daftar posting belum dapat dibaca. Coba ulang.' );
		}
		$done = count( $ids ) <= $limit;
		$ids = array_slice( $ids, 0, $limit );
		$items = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$before = $this->fingerprint( $id );
			try {
				$result = current_user_can( 'edit_post', $id ) ? $this->resolver->inspect( $id ) : $this->retry( 'Anda tidak memiliki izin mengedit posting ini.' );
			} catch ( \Throwable $exception ) {
				$result = $this->retry( 'Pemeriksaan belum selesai. Coba ulang.' );
			}
			$after = $this->fingerprint( $id );
			if ( ! hash_equals( $before, $after ) ) {
				$result = $this->retry( 'Posting berubah selama pemeriksaan. Periksa ulang.' );
			}
			$items[] = $this->row( $id, $result, $after );
			$cursor = $id;
		}
		return array( 'items' => $items, 'next_cursor' => $cursor, 'done' => $done );
	}

	/** Snapshot all editorial input used to decide which image belongs to a post. */
	public function fingerprint( $post_id ) {
		global $wpdb;
		// Read current database values rather than a request's stale object cache.
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status, post_author, post_content, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		if ( $wpdb->last_error ) {
			return hash( 'sha256', 'read-error:' . wp_generate_uuid4() );
		}
		if ( ! $post ) {
			return hash( 'sha256', 'missing:' . (int) $post_id );
		}
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_thumbnail_id', '_wtsr_source_url') ORDER BY meta_id", $post->ID ), ARRAY_A );
		if ( $wpdb->last_error ) { return hash( 'sha256', 'read-error:' . wp_generate_uuid4() ); }
		$children = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = %d ORDER BY ID", $post->ID ) );
		if ( $wpdb->last_error ) { return hash( 'sha256', 'read-error:' . wp_generate_uuid4() ); }
		$trusted = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wtsr_trusted_hosts' ) );
		if ( $wpdb->last_error ) { return hash( 'sha256', 'read-error:' . wp_generate_uuid4() ); }
		$thumbnail_id = 0;
		foreach ( $meta as $entry ) {
			if ( '_thumbnail_id' === $entry['meta_key'] ) {
				$thumbnail_id = (int) $entry['meta_value'];
			}
		}
		$media = array();
		$ids = array_merge( array( $thumbnail_id ), $children );
		$references = $this->referenced_ids( $post );
		if ( false === $references ) { return hash( 'sha256', 'read-error:' . wp_generate_uuid4() ); }
		$ids = array_merge( $ids, $references );
		sort( $ids, SORT_NUMERIC );
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$attachment = $wpdb->get_row( $wpdb->prepare( "SELECT post_modified_gmt, post_type, post_parent, post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
			if ( $wpdb->last_error ) { return hash( 'sha256', 'read-error:' . wp_generate_uuid4() ); }
			$attachment_meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata') ORDER BY meta_id", $id ), ARRAY_A );
			if ( $wpdb->last_error ) { return hash( 'sha256', 'read-error:' . wp_generate_uuid4() ); }
			$file = '';
			foreach ( $attachment_meta as $entry ) {
				if ( '_wp_attached_file' === $entry['meta_key'] ) {
					$file = $entry['meta_value'];
					if ( $file && '/' !== substr( $file, 0, 1 ) && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $file ) ) {
						$uploads = wp_get_upload_dir();
						$file = $uploads['basedir'] . '/' . $file;
					}
				}
			}
			clearstatcache( true, $file ?: '' );
			$media[] = array( (int) $id, $attachment, $attachment_meta, $file, $file && is_file( $file ) ? @filesize( $file ) : null, $file && is_file( $file ) ? @filemtime( $file ) : null );
		}
		if ( $wpdb->last_error ) {
			return hash( 'sha256', 'read-error:' . wp_generate_uuid4() );
		}
		$input = array( $post, $meta, $trusted, $media );
		return hash( 'sha256', wp_json_encode( $input ) );
	}

	/** Include media referenced by URLs, galleries and blocks even when owned by another post. */
	private function referenced_ids( $post ) {
		global $wpdb;
		$ids = $urls = array();
		preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches );
		$ids = array_map( 'intval', $matches[1] );
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'gallery' ) ) . '/s', $post->post_content, $galleries, PREG_SET_ORDER ) ) {
			foreach ( $galleries as $gallery ) {
				if ( '[' === $gallery[1] && ']' === $gallery[6] ) { continue; }
				$attrs = shortcode_parse_atts( $gallery[3] );
				foreach ( array( 'ids', 'include' ) as $key ) {
					if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
						$ids = array_merge( $ids, array_map( 'absint', explode( ',', $attrs[ $key ] ) ) );
					}
				}
			}
		}
		$visit = function ( $blocks ) use ( &$visit, &$ids, &$urls ) {
			foreach ( $blocks as $block ) {
				$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
				$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
				if ( in_array( $name, array( 'core/image', 'core/gallery', 'core/cover', 'core/media-text' ), true ) ) {
					foreach ( array( 'id', 'mediaId' ) as $key ) {
						if ( ! empty( $attrs[ $key ] ) ) { $ids[] = absint( $attrs[ $key ] ); }
					}
					if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
						$ids = array_merge( $ids, array_map( 'absint', $attrs['ids'] ) );
					}
					foreach ( array( 'url', 'mediaUrl', 'src' ) as $key ) {
						if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) { $urls[] = $attrs[ $key ]; }
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) { $visit( $block['innerBlocks'] ); }
			}
		};
		$visit( parse_blocks( $post->post_content ) );
		$tags = new \WP_HTML_Tag_Processor( $post->post_content );
		while ( $tags->next_tag( 'IMG' ) ) {
			foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original', 'data-original-src', 'data-echo' ) as $key ) {
				$value = $tags->get_attribute( $key );
				if ( is_string( $value ) ) { $urls[] = $value; }
			}
			foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $key ) {
				$value = $tags->get_attribute( $key );
				if ( is_string( $value ) ) {
					foreach ( explode( ',', $value ) as $entry ) {
						if ( preg_match( '/^\s*(\S+)/', $entry, $match ) ) { $urls[] = $match[1]; }
					}
				}
			}
		}
		$uploads = wp_get_upload_dir();
		$base = wp_parse_url( $uploads['baseurl'] );
		$prefix = rtrim( isset( $base['path'] ) ? $base['path'] : '', '/' ) . '/';
		foreach ( $urls as $url ) {
			$url = \WP_Http::make_absolute_url( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), get_permalink( $post->ID ) );
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $base['host'] ) ) { continue; }
			$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
			if ( 0 !== strpos( $path, $prefix ) || preg_match( '~(?:^|/)\.\.(?:/|$)~', $path ) ) { continue; }
			$file = substr( $path, strlen( $prefix ) );
			$original = preg_replace( '/-[0-9]+x[0-9]+(?=\.[a-z0-9]+$)/i', '', $file );
			$matched = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN (%s, %s)", $file, $original ) );
			if ( $wpdb->last_error ) { return false; }
			$ids = array_merge( $ids, $matched );
		}
		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	public function process( $post_id, $mode, $fingerprint, $confirm_delete = false ) {
		$post_id = absint( $post_id );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'wtsr_forbidden', 'Izin mengedit posting tidak tersedia.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $mode, array( 'repair', 'repair_delete' ), true ) ) {
			return new \WP_Error( 'wtsr_mode', 'Mode tindakan tidak valid.' );
		}
		if ( 'repair_delete' === $mode && ( ! $confirm_delete || ! current_user_can( 'delete_post', $post_id ) ) ) {
			return new \WP_Error( 'wtsr_confirmation', 'Penghapusan permanen memerlukan konfirmasi dan izin menghapus.', array( 'status' => 403 ) );
		}
		if ( ! is_string( $fingerprint ) || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return new \WP_Error( 'wtsr_fingerprint', 'Hasil pemeriksaan tidak valid. Periksa ulang.' );
		}
		$lock = $this->acquire_lock( $post_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$this->refresh_post( $post_id );
			$post = get_post( $post_id );
			if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
				return new \WP_Error( 'wtsr_scope', 'Hanya posting terbit yang dapat diproses.', array( 'status' => 409 ) );
			}
			if ( ! hash_equals( $fingerprint, $this->fingerprint( $post_id ) ) ) {
				return new \WP_Error( 'wtsr_changed', 'Posting atau sumber gambarnya berubah. Periksa ulang.', array( 'status' => 409 ) );
			}
			$result = $this->resolver->apply( $post_id );
			if ( ! is_array( $result ) || ! isset( $result['state'] ) || ! in_array( $result['state'], array( 'healthy', 'fixed', 'repairable', 'missing', 'retry' ), true ) ) {
				return $this->row( $post_id, $this->retry( 'Hasil pemulihan belum dapat diverifikasi.' ) );
			}
			if ( 'missing' === $result['state'] && 'repair_delete' === $mode ) {
				$this->refresh_post( $post_id );
				if ( ! $this->owns_lock( $post_id, $lock ) || ! hash_equals( $fingerprint, $this->fingerprint( $post_id ) ) || ! current_user_can( 'delete_post', $post_id ) ) {
					return new \WP_Error( 'wtsr_changed', 'Posting berubah selama pemeriksaan; penghapusan dibatalkan.', array( 'status' => 409 ) );
				}
				// wp_delete_post(true) bypasses Trash; media files remain untouched.
				$deleted = wp_delete_post( $post_id, true );
				$this->refresh_post( $post_id );
				if ( ! $deleted || get_post( $post_id ) ) {
					return $this->row( $post_id, $this->retry( 'WordPress belum berhasil menghapus posting.' ) );
				}
				$result['state'] = 'deleted';
				$result['message'] = 'Dihapus permanen setelah semua sumber yang dapat diverifikasi tidak menyediakan gambar.';
				return array_merge( $this->row( $post_id, $result ), array( 'title' => $post->post_title ) );
			}
			return $this->row( $post_id, $result );
		} catch ( \Throwable $exception ) {
			return $this->row( $post_id, $this->retry( 'Pemulihan belum selesai. Posting dipertahankan untuk dicoba ulang.' ) );
		} finally {
			$this->release_lock( $post_id, $lock );
		}
	}

	private function refresh_post( $post_id ) {
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
	}

	private function retry( $message ) {
		return array( 'state' => 'retry', 'message' => $message, 'evidence' => array(), 'attachment_id' => 0, 'url' => '' );
	}

	private function row( $id, $result, $fingerprint = null ) {
		return array_merge( $result, array( 'post_id' => (int) $id, 'title' => (string) get_post_field( 'post_title', $id ), 'edit_url' => get_edit_post_link( $id, 'raw' ) ?: '', 'fingerprint' => null === $fingerprint ? $this->fingerprint( $id ) : $fingerprint ) );
	}

	private function acquire_lock( $id ) {
		global $wpdb;
		$key = 'wtsr_lock_' . $id;
		$value = wp_json_encode( array( 'token' => wp_generate_uuid4(), 'expires' => time() + self::LOCK_TTL ) );
		if ( add_option( $key, $value, '', false ) ) {
			return $value;
		}
		$old = get_option( $key );
		$data = is_string( $old ) ? json_decode( $old, true ) : null;
		if ( is_array( $data ) && isset( $data['expires'] ) && (int) $data['expires'] < time() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $old ) );
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( add_option( $key, $value, '', false ) ) {
				return $value;
			}
		}
		return new \WP_Error( 'wtsr_busy', 'Posting sedang diproses oleh permintaan lain. Coba ulang.', array( 'status' => 409 ) );
	}

	private function release_lock( $id, $value ) {
		global $wpdb;
		$key = 'wtsr_lock_' . $id;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $value ) );
		wp_cache_delete( $key, 'options' );
	}

	private function owns_lock( $id, $value ) {
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wtsr_lock_' . $id ) );
		$data = json_decode( $value, true );
		return is_string( $current ) && hash_equals( $value, $current ) && ! empty( $data['expires'] ) && (int) $data['expires'] > time();
	}
}
