<?php
/** Resolve featured images using sources belonging to the post. */
namespace WTSR;

defined( 'ABSPATH' ) || exit;

class Resolver {
	private const MAX_IMAGE_BYTES = 10485760;
	private const MAX_HTML_BYTES  = 1048576;
	private const MAX_CANDIDATES  = 64;
	private const MAX_DOWNLOADS   = 8;
	private const MAX_SECONDS     = 25;

	private $evidence = array();
	private $uncertain = array();
	private $seen = array();
	private $downloads = 0;
	private $started = 0;
	private $schema_images = array();

	public function inspect( int $post_id ): array {
		return $this->resolve( $post_id, false );
	}

	public function apply( int $post_id ): array {
		return $this->resolve( $post_id, true );
	}

	private function result( $state, $message, $id = 0, $url = '' ) {
		return array(
			'state' => $state,
			'message' => $message,
			'evidence' => array_values( array_unique( array_merge( $this->evidence, $this->uncertain ) ) ),
			'attachment_id' => (int) $id,
			'url' => $this->display_url( $url ),
		);
	}

	private function resolve( $post_id, $write ) {
		$this->evidence = $this->uncertain = $this->seen = array();
		$this->downloads = 0;
		$this->started = microtime( true );
		$this->schema_images = array();
		try {
			$post = get_post( $post_id );
			if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
				return $this->result( 'retry', 'Posting harus merupakan post yang masih dipublikasikan.' );
			}
			$candidates = array();
			$featured = (int) get_post_thumbnail_id( $post_id );
			$this->database_error();
			if ( $featured ) {
				$existing = $this->attachment( $featured );
				if ( 'ok' === $existing['state'] && empty( $existing['derivative'] ) && $this->display_ready( $featured, $existing['file'] ) ) {
					$this->evidence[] = 'Thumbnail saat ini memiliki berkas gambar asli yang valid (media #' . $featured . ').';
					return $this->result( 'healthy', 'Thumbnail tersedia dan berkas gambar dapat dibaca.', $featured, wp_get_attachment_url( $featured ) );
				}
				$this->add_candidate( $candidates, array( 'id' => $featured, 'label' => 'Thumbnail yang tercatat' ) );
			}
			$this->content_candidates( $post, $candidates );
			$children = get_posts( array(
				'post_type' => 'attachment', 'post_status' => 'inherit',
				'post_parent' => $post_id, 'post_mime_type' => 'image',
				'numberposts' => self::MAX_CANDIDATES + 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids',
			) );
			$this->database_error();
			if ( count( $children ) > self::MAX_CANDIDATES ) {
				$this->uncertain[] = 'Jumlah media anak melebihi batas pemeriksaan; pemeriksaan harus dilanjutkan.';
			}
			foreach ( $children as $id ) {
				$this->add_candidate( $candidates, array( 'id' => (int) $id, 'label' => 'Media milik posting' ) );
			}
			// Reuse local originals before importing an image from the network.
			$remote = $derivatives = array();
			foreach ( $candidates as $candidate ) {
				$id = isset( $candidate['id'] ) ? (int) $candidate['id'] : $this->local_id( $candidate['url'] );
				if ( $id ) {
					$local = $this->attachment( $id );
					if ( 'ok' === $local['state'] ) {
						$this->evidence[] = $candidate['label'] . ': media lokal #' . $id . ' memiliki gambar raster yang valid.';
						if ( ! empty( $local['derivative'] ) ) {
							$derivatives[] = array( 'id' => $id, 'image' => $local );
							continue;
						}
						return $this->use_local( $post_id, $id, $local['file'], $write );
					}
					if ( 'retry' === $local['state'] ) {
						$this->uncertain[] = $candidate['label'] . ': ' . $local['message'];
					} else {
						$this->evidence[] = $candidate['label'] . ': ' . $local['message'];
					}
					$url = wp_get_attachment_url( $id );
					$this->database_error();
					if ( $url ) {
						$candidate['url'] = $url;
						$remote[] = $candidate;
					} elseif ( 'missing' === $local['state'] ) {
						$this->uncertain[] = 'URL asli media #' . $id . ' tidak tersedia untuk memeriksa sumber yang hilang.';
					}
				} elseif ( isset( $candidate['url'] ) ) {
					$remote[] = $candidate;
				} else {
					$this->evidence[] = $candidate['label'] . ': media yang dirujuk sudah tidak ada.';
				}
			}
			if ( $derivatives ) {
				$first = $derivatives[0];
				return $this->use_derivative( $post_id, $first['id'], $first['image'], $write );
			}
			foreach ( $remote as $candidate ) {
				$found = $this->try_remote( $post_id, $candidate, $write );
				if ( $found ) {
					return $found;
				}
			}
			$source = get_post_meta( $post_id, '_wtsr_source_url', true );
			$this->database_error();
			if ( is_string( $source ) && '' !== trim( $source ) ) {
				$source_candidates = $this->source_candidates( $source );
				foreach ( $source_candidates as $candidate ) {
					if ( is_wp_error( $this->safe_url( $candidate['url'], $candidate['hosts'] ) ) ) {
						$this->uncertain[] = $candidate['label'] . ': host gambar bukan host sumber atau CDN yang dipercaya.';
						continue;
					}
					$id = $this->local_id( $candidate['url'] );
					if ( $id ) {
						$local = $this->attachment( $id );
						if ( 'ok' === $local['state'] ) {
							$this->evidence[] = $candidate['label'] . ': cocok dengan media lokal #' . $id . '.';
							if ( ! empty( $local['derivative'] ) ) {
								return $this->use_derivative( $post_id, $id, $local, $write );
							}
							return $this->use_local( $post_id, $id, $local['file'], $write );
						}
					}
					$found = $this->try_remote( $post_id, $candidate, $write );
					if ( $found ) {
						return $found;
					}
				}
			} elseif ( ! empty( $source ) ) {
				$this->uncertain[] = 'URL sumber yang tersimpan bukan nilai URL yang valid.';
			}
			if ( $this->uncertain ) {
				return $this->result( 'retry', 'Belum dapat dipastikan: sumber atau layanan perlu diperiksa ulang. Posting tidak boleh dihapus.' );
			}
			$this->evidence[] = 'Semua sumber yang terkait dengan posting telah diperiksa; tidak ada gambar yang dapat digunakan.';
			return $this->result( 'missing', 'Tidak ditemukan gambar yang valid dari sumber yang terkait dengan posting.' );
		} catch ( \Throwable $error ) {
			$this->uncertain[] = 'Pemeriksaan terhenti karena kesalahan internal; lakukan pemeriksaan ulang.';
			return $this->result( 'retry', 'Pemeriksaan gagal diselesaikan. Posting tidak boleh dihapus.' );
		}
	}

	private function add_candidate( &$list, $candidate ) {
		$key = isset( $candidate['id'] ) ? 'id:' . $candidate['id'] : 'url:' . $candidate['url'];
		if ( isset( $this->seen[ $key ] ) ) {
			return;
		}
		$this->seen[ $key ] = true;
		if ( count( $list ) >= self::MAX_CANDIDATES ) {
			$this->uncertain[] = 'Jumlah referensi gambar melebihi batas pemeriksaan.';
			return;
		}
		$list[] = $candidate;
	}

	private function content_candidates( $post, &$list ) {
		$base = get_permalink( $post->ID );
		$tags = new \WP_HTML_Tag_Processor( $post->post_content );
		while ( $tags->next_tag( 'IMG' ) ) {
			$class = $tags->get_attribute( 'class' );
			if ( is_string( $class ) && preg_match_all( '/\bwp-image-([1-9][0-9]*)\b/', $class, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$this->add_candidate( $list, array( 'id' => (int) $id, 'label' => 'Gambar di isi posting' ) );
				}
			}
			foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original', 'data-original-src', 'data-echo' ) as $name ) {
				$url = $tags->get_attribute( $name );
				if ( is_string( $url ) && '' !== trim( $url ) ) {
					$this->add_candidate( $list, array( 'url' => $this->absolute_url( $url, $base ), 'label' => 'Gambar di isi posting (' . $name . ')' ) );
				}
			}
			foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $name ) {
				$srcset = $tags->get_attribute( $name );
				if ( ! is_string( $srcset ) || '' === trim( $srcset ) ) {
					continue;
				}
				foreach ( explode( ',', $srcset ) as $entry ) {
					if ( preg_match( '/^\s*(\S+)\s+(?:[1-9][0-9]*w|[0-9]+(?:\.[0-9]+)?x)\s*$/', $entry, $match ) || preg_match( '/^\s*(\S+)\s*$/', $entry, $match ) ) {
						$this->add_candidate( $list, array( 'url' => $this->absolute_url( $match[1], $base ), 'label' => 'Gambar di srcset posting' ) );
					} else {
						$this->uncertain[] = 'Referensi srcset tidak dapat dibaca dengan aman.';
					}
				}
			}
		}
		$this->block_candidates( parse_blocks( $post->post_content ), $list, $base );
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'gallery' ) ) . '/s', $post->post_content, $shortcodes, PREG_SET_ORDER ) ) {
			foreach ( $shortcodes as $shortcode ) {
				if ( '[' === $shortcode[1] && ']' === $shortcode[6] ) {
					continue;
				}
				$attrs = shortcode_parse_atts( $shortcode[3] );
				foreach ( array( 'ids', 'include' ) as $key ) {
					if ( ! is_array( $attrs ) || empty( $attrs[ $key ] ) ) {
						continue;
					}
					if ( ! is_string( $attrs[ $key ] ) || ! preg_match( '/^\s*[1-9][0-9]*(?:\s*,\s*[1-9][0-9]*)*\s*$/', $attrs[ $key ] ) ) {
						$this->uncertain[] = 'ID gambar pada shortcode gallery tidak dapat dibaca dengan aman.';
						continue;
					}
					foreach ( explode( ',', $attrs[ $key ] ) as $id ) {
						$this->add_candidate( $list, array( 'id' => (int) $id, 'label' => 'Gambar di shortcode gallery' ) );
					}
				}
			}
		}
	}

	private function block_candidates( $blocks, &$list, $base ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( in_array( $name, array( 'core/image', 'core/gallery', 'core/cover', 'core/media-text' ), true ) ) {
				foreach ( array( 'id', 'mediaId' ) as $key ) {
					if ( ! empty( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) ) {
						$this->add_candidate( $list, array( 'id' => (int) $attrs[ $key ], 'label' => 'Media di blok Gutenberg' ) );
					}
				}
				if ( isset( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
					foreach ( $attrs['ids'] as $id ) {
						if ( is_numeric( $id ) && $id > 0 ) {
							$this->add_candidate( $list, array( 'id' => (int) $id, 'label' => 'Galeri Gutenberg' ) );
						}
					}
				}
				foreach ( array( 'url', 'mediaUrl', 'src' ) as $key ) {
					if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
						$this->add_candidate( $list, array( 'url' => $this->absolute_url( $attrs[ $key ], $base ), 'label' => 'URL gambar di blok Gutenberg' ) );
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->block_candidates( $block['innerBlocks'], $list, $base );
			}
		}
	}

	private function attachment( $id ) {
		if ( microtime( true ) - $this->started >= self::MAX_SECONDS ) {
			return array( 'state' => 'retry', 'message' => 'Batas waktu pemeriksaan media tercapai.' );
		}
		$attachment = get_post( $id );
		if ( $this->database_error() ) {
			return array( 'state' => 'retry', 'message' => 'Database media gagal dibaca.' );
		}
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return array( 'state' => 'missing', 'message' => 'Media yang dirujuk tidak ada atau bukan attachment.' );
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $this->database_error() ) {
			return array( 'state' => 'retry', 'message' => 'Konfigurasi logo situs gagal dibaca.' );
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $this->database_error() ) {
			return array( 'state' => 'retry', 'message' => 'Konfigurasi ikon situs gagal dibaca.' );
		}
		if ( $logo === (int) $id || $icon === (int) $id ) {
			return array( 'state' => 'retry', 'message' => 'Media adalah logo atau ikon situs, bukan gambar khusus posting.' );
		}
		$file = get_attached_file( $id );
		if ( $this->database_error() ) {
			return array( 'state' => 'retry', 'message' => 'Lokasi berkas media gagal dibaca dari database.' );
		}
		if ( ! $file ) {
			return array( 'state' => 'retry', 'message' => 'Lokasi berkas media tidak tercatat; sumber asli perlu diperiksa.' );
		}
		if ( ! file_exists( $file ) && ! is_readable( dirname( $file ) ) ) {
			return array( 'state' => 'retry', 'message' => 'Direktori penyimpanan tidak dapat diperiksa; keberadaan berkas belum dapat dipastikan.' );
		}
		if ( ! file_exists( $file ) ) {
			return $this->derivative( $id, $file, array( 'state' => 'missing', 'message' => 'Berkas asli media #' . $id . ' tidak tersedia di penyimpanan lokal.' ) );
		}
		if ( $this->excluded_path( $file ) ) {
			return array( 'state' => 'retry', 'message' => 'Nama berkas menunjukkan placeholder, logo, atau ikon; sumber perlu ditinjau.' );
		}
		$valid = $this->validate_image( $file );
		return 'ok' === $valid['state'] ? $valid : $this->derivative( $id, $file, $valid );
	}

	private function derivative( $id, $original, $fallback ) {
		$metadata = wp_get_attachment_metadata( $id );
		if ( $this->database_error() ) {
			return array( 'state' => 'retry', 'message' => 'Daftar ukuran gambar media gagal dibaca dari database.' );
		}
		$files = array();
		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$files[] = array( 'file' => $metadata['original_image'], 'area' => PHP_INT_MAX );
		}
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$files[] = array( 'file' => $size['file'], 'area' => (float) ( isset( $size['width'] ) ? $size['width'] : 0 ) * (float) ( isset( $size['height'] ) ? $size['height'] : 0 ) );
				}
			}
		}
		usort( $files, static function ( $left, $right ) { return $right['area'] <=> $left['area']; } );
		foreach ( array_slice( $files, 0, self::MAX_CANDIDATES ) as $record ) {
			if ( microtime( true ) - $this->started >= self::MAX_SECONDS ) {
				return array( 'state' => 'retry', 'message' => 'Pemeriksaan ukuran gambar belum selesai karena batas waktu.' );
			}
			$name = $record['file'];
			if ( wp_basename( $name ) !== $name || preg_match( '/[\x00-\x1f\\\\]/', $name ) || $this->excluded_path( $name ) ) {
				continue;
			}
			$path = dirname( $original ) . '/' . $name;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$valid = $this->validate_image( $path );
			if ( 'ok' === $valid['state'] ) {
				$valid['derivative'] = true;
				return $valid;
			}
		}
		if ( $files ) {
			return array( 'state' => 'retry', 'message' => 'Berkas asli dan ukuran gambar yang tercatat tidak dapat diverifikasi; sumber perlu diperiksa ulang.' );
		}
		return $fallback;
	}

	private function use_derivative( $post_id, $id, $image, $write ) {
		$this->evidence[] = 'Berkas asli media #' . $id . ' rusak atau hilang, tetapi ukuran gambar yang tercatat masih valid. Pemulihan menggunakan salinan ukuran tersebut.';
		if ( ! $write ) {
			return $this->result( 'repairable', 'Thumbnail dapat dipulihkan dari ukuran gambar milik media yang sama.', $id, wp_get_attachment_url( $id ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam( 'wtsr-recovered' );
		if ( ! $temp || ! copy( $image['file'], $temp ) ) {
			if ( $temp && file_exists( $temp ) ) { wp_delete_file( $temp ); }
			return $this->result( 'retry', 'Salinan gambar pemulihan gagal dibuat. Posting tidak boleh dihapus.' );
		}
		try {
			return $this->import_image( $post_id, $temp, $image['mime'], wp_get_attachment_url( $id ) );
		} finally {
			if ( file_exists( $temp ) ) { wp_delete_file( $temp ); }
		}
	}

	private function validate_image( $file ) {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return array( 'state' => 'retry', 'message' => 'Berkas gambar tidak dapat dibaca.' );
		}
		$size = filesize( $file );
		if ( false === $size || $size > self::MAX_IMAGE_BYTES ) {
			return array( 'state' => 'retry', 'message' => 'Ukuran berkas tidak diketahui atau melebihi batas 10 MiB.' );
		}
		$mime = wp_get_image_mime( $file );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
			$probe = wp_getimagesize( $file );
			$detected = function_exists( 'mime_content_type' ) ? mime_content_type( $file ) : '';
			$head = file_get_contents( $file, false, null, 0, 1024 );
			if ( is_string( $head ) && preg_match( '/<svg(?:\s|>)/i', $head ) ) {
				return array( 'state' => 'retry', 'message' => 'SVG ditolak; gunakan sumber gambar raster yang dapat diverifikasi.' );
			}
			if ( $probe || ( is_string( $mime ) && 0 === strpos( $mime, 'image/' ) ) || ( is_string( $detected ) && 0 === strpos( $detected, 'image/' ) ) ) {
				return array( 'state' => 'retry', 'message' => 'Berkas merupakan gambar dalam format yang belum didukung; sumber perlu ditinjau.' );
			}
			return array( 'state' => 'missing', 'message' => 'Isi berkas terbukti bukan format gambar raster yang didukung.' );
		}
		$dimensions = wp_getimagesize( $file );
		if ( ! $dimensions || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return array( 'state' => 'retry', 'message' => 'Berkas mengaku sebagai gambar, tetapi dimensinya gagal diverifikasi.' );
		}
		if ( $dimensions[0] < 2 || $dimensions[1] < 2 ) {
			return array( 'state' => 'retry', 'message' => 'Gambar terlalu kecil dan mungkin merupakan pixel pelacak atau placeholder.' );
		}
		// Avoid decompression bombs before an image editor decodes the full bitmap.
		$pixels = (float) $dimensions[0] * (float) $dimensions[1];
		$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$budget = $memory > 0 ? max( 0, $memory - memory_get_usage( true ) ) : 268435456;
		if ( $pixels > 40000000 || $pixels * 8 > $budget * 0.7 ) {
			return array( 'state' => 'retry', 'message' => 'Dimensi gambar terlalu besar untuk diverifikasi dengan sumber daya saat ini.' );
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array( 'state' => 'retry', 'message' => 'Decoder WordPress tidak dapat membuka gambar; berkas atau dukungan server perlu diperiksa.' );
		}
		$decoded = $editor->get_size();
		unset( $editor );
		if ( ! is_array( $decoded ) || empty( $decoded['width'] ) || empty( $decoded['height'] ) ) {
			return array( 'state' => 'retry', 'message' => 'Gambar gagal didekode menjadi dimensi yang valid.' );
		}
		return array( 'state' => 'ok', 'file' => $file, 'mime' => $mime );
	}

	private function display_ready( $id, $file ) {
		$metadata = wp_get_attachment_metadata( $id );
		if ( ! is_array( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return false;
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					return false;
				}
				$thumbnail = dirname( $file ) . '/' . wp_basename( $size['file'] );
				if ( ! is_file( $thumbnail ) || 'ok' !== $this->validate_image( $thumbnail )['state'] ) {
					return false;
				}
			}
		}
		return (bool) wp_get_attachment_image_src( $id, 'post-thumbnail' ) && (bool) wp_get_attachment_image_src( $id, 'thumbnail' );
	}

	private function use_local( $post_id, $id, $file, $write ) {
		$url = wp_get_attachment_url( $id );
		if ( ! $write ) {
			return $this->result( 'repairable', 'Gambar yang terkait ditemukan; thumbnail dapat dipulihkan dari media lokal.', $id, $url );
		}
		if ( ! $this->display_ready( $id, $file ) ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return $this->result( 'retry', 'Izin untuk memperbarui metadata media tidak tersedia. Posting tidak boleh dihapus.' );
			}
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $id, $file );
			if ( is_wp_error( $metadata ) || ! is_array( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
				return $this->result( 'retry', 'Pembuatan metadata atau ukuran thumbnail gagal. Posting tidak boleh dihapus.' );
			}
			wp_update_attachment_metadata( $id, $metadata );
		}
		if ( ! $this->display_ready( $id, $file ) ) {
			return $this->result( 'retry', 'Berkas thumbnail gagal dibuat atau disimpan. Posting tidak boleh dihapus.' );
		}
		set_post_thumbnail( $post_id, $id );
		if ( (int) get_post_thumbnail_id( $post_id ) !== (int) $id || ! wp_get_attachment_image( $id, 'thumbnail' ) ) {
			return $this->result( 'retry', 'WordPress gagal menyimpan thumbnail posting. Posting tidak boleh dihapus.' );
		}
		return $this->result( 'fixed', 'Thumbnail berhasil dipulihkan dari gambar yang terverifikasi.', $id, $url );
	}

	private function try_remote( $post_id, $candidate, $write ) {
		if ( $this->downloads >= self::MAX_DOWNLOADS || microtime( true ) - $this->started >= self::MAX_SECONDS ) {
			$this->uncertain[] = 'Batas waktu atau jumlah unduhan tercapai; pemeriksaan sumber belum selesai.';
			return null;
		}
		$checked = $this->safe_url( $candidate['url'], isset( $candidate['hosts'] ) ? $candidate['hosts'] : null );
		if ( is_wp_error( $checked ) ) {
			$this->uncertain[] = $candidate['label'] . ': URL ditolak karena tidak memenuhi aturan sumber dan keamanan.';
			return null;
		}
		++$this->downloads;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam( 'wtsr-image' );
		if ( ! $temp ) {
			$this->uncertain[] = 'Penyimpanan sementara tidak tersedia.';
			return null;
		}
		try {
			$fetched = $this->fetch( $checked, self::MAX_IMAGE_BYTES, $temp, isset( $candidate['hosts'] ) ? $candidate['hosts'] : null );
			if ( 'ok' !== $fetched['state'] ) {
				if ( 'retry' === $fetched['state'] ) {
					$this->uncertain[] = $candidate['label'] . ': ' . $fetched['message'];
				} else {
					$this->evidence[] = $candidate['label'] . ': ' . $fetched['message'];
				}
				return null;
			}
			$valid = $this->validate_image( $temp );
			if ( 'ok' !== $valid['state'] ) {
				if ( 'retry' === $valid['state'] ) {
					$this->uncertain[] = $candidate['label'] . ': ' . $valid['message'];
				} else {
					$this->evidence[] = $candidate['label'] . ': ' . $valid['message'];
				}
				return null;
			}
			$this->evidence[] = $candidate['label'] . ': gambar raster terverifikasi dari ' . $this->display_url( $fetched['url'] ) . '.';
			if ( ! $write ) {
				return $this->result( 'repairable', 'Gambar dari sumber yang terkait terverifikasi dan dapat diimpor.', 0, $fetched['url'] );
			}
			return $this->import_image( $post_id, $temp, $valid['mime'], $fetched['url'] );
		} finally {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
		}
	}

	private function import_image( $post_id, $temp, $mime, $origin ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->result( 'retry', 'Izin unggah media diperlukan untuk mengimpor gambar. Posting tidak boleh dihapus.' );
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$extensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif' );
		$id = media_handle_sideload( array( 'name' => 'wtsr-post-' . $post_id . '.' . $extensions[ $mime ], 'tmp_name' => $temp ), $post_id );
		if ( is_wp_error( $id ) || $this->database_error() ) {
			return $this->result( 'retry', 'Impor gambar gagal; izin unggah dan penyimpanan perlu diperiksa. Posting tidak boleh dihapus.' );
		}
		$stored = $this->attachment( $id );
		if ( 'ok' !== $stored['state'] || ! empty( $stored['derivative'] ) ) {
			return $this->result( 'retry', 'Berkas hasil impor gagal diverifikasi. Posting tidak boleh dihapus.' );
		}
		update_post_meta( $id, '_wtsr_origin_url', $this->display_url( $origin ) );
		if ( $this->database_error() ) {
			return $this->result( 'retry', 'Asal gambar hasil impor gagal disimpan. Posting tidak boleh dihapus.' );
		}
		return $this->use_local( $post_id, $id, $stored['file'], true );
	}

	private function fetch( $url, $maximum, $file = null, $hosts = null ) {
		for ( $hop = 0; $hop <= 3; ++$hop ) {
			$remaining = self::MAX_SECONDS - ( microtime( true ) - $this->started );
			if ( $remaining <= 0 ) {
				return array( 'state' => 'retry', 'message' => 'Batas waktu pemeriksaan tercapai.' );
			}
			$args = array( 'timeout' => min( 6, $remaining ), 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => $maximum + 1, 'reject_unsafe_urls' => true );
			if ( $file ) {
				$args['stream'] = true;
				$args['filename'] = $file;
			}
			$response = wp_safe_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return array( 'state' => 'retry', 'message' => 'Permintaan jaringan gagal atau ditolak oleh pemeriksaan keamanan.' );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$next = $this->safe_url( $this->absolute_url( $location, $url ), $hosts );
				if ( 3 === $hop || is_wp_error( $next ) ) {
					return array( 'state' => 'retry', 'message' => 'Pengalihan URL tidak aman, tidak valid, atau terlalu banyak.' );
				}
				$url = $next;
				continue;
			}
			if ( 404 === $code || 410 === $code ) {
				return array( 'state' => 'missing', 'message' => 'Sumber mengembalikan HTTP ' . $code . ' (tidak tersedia).' );
			}
			if ( $code < 200 || $code >= 300 || 206 === $code ) {
				return array( 'state' => 'retry', 'message' => 'Sumber mengembalikan HTTP ' . $code . '; ketersediaan belum dapat dipastikan.' );
			}
			$body = wp_remote_retrieve_body( $response );
			if ( $file && is_string( $body ) && '' !== $body && ( ! file_exists( $file ) || 0 === filesize( $file ) ) ) {
				if ( strlen( $body ) > $maximum || false === file_put_contents( $file, $body ) ) {
					return array( 'state' => 'retry', 'message' => 'Respons terlalu besar atau gagal disimpan.' );
				}
			}
			clearstatcache( true, $file ? $file : '' );
			$bytes = $file && is_file( $file ) ? filesize( $file ) : strlen( $body );
			$declared = wp_remote_retrieve_header( $response, 'content-length' );
			if ( false === $bytes || $bytes > $maximum || ( is_numeric( $declared ) && (float) $declared > $maximum ) ) {
				return array( 'state' => 'retry', 'message' => 'Respons melebihi batas ukuran pemeriksaan.' );
			}
			if ( is_numeric( $declared ) && (float) $declared > $bytes && '' === (string) wp_remote_retrieve_header( $response, 'content-encoding' ) ) {
				return array( 'state' => 'retry', 'message' => 'Respons jaringan terpotong; sumber belum diperiksa sepenuhnya.' );
			}
			if ( $file && ! is_file( $file ) ) {
				return array( 'state' => 'retry', 'message' => 'Berkas unduhan tidak berhasil dibuat.' );
			}
			return array( 'state' => 'ok', 'url' => $url, 'body' => $body, 'response' => $response );
		}
		return array( 'state' => 'retry', 'message' => 'Pemeriksaan pengalihan belum selesai.' );
	}

	private function source_candidates( $source ) {
		$list = array();
		$url = $this->safe_url( $source );
		if ( is_wp_error( $url ) ) {
			$this->uncertain[] = 'URL artikel sumber ditolak; gunakan URL HTTPS publik yang valid.';
			return $list;
		}
		$fetched = $this->fetch( $url, self::MAX_HTML_BYTES );
		if ( 'ok' !== $fetched['state'] ) {
			if ( 'retry' === $fetched['state'] ) {
				$this->uncertain[] = 'Artikel sumber: ' . $fetched['message'];
			} else {
				$this->evidence[] = 'Artikel sumber: ' . $fetched['message'];
			}
			return $list;
		}
		$type = strtolower( (string) wp_remote_retrieve_header( $fetched['response'], 'content-type' ) );
		if ( '' !== $type && false === strpos( $type, 'text/html' ) && false === strpos( $type, 'application/xhtml+xml' ) ) {
			$this->uncertain[] = 'Artikel sumber tidak memberikan dokumen HTML yang dapat diperiksa.';
			return $list;
		}
		$base = $fetched['url'];
		$hosts = array( strtolower( wp_parse_url( $base, PHP_URL_HOST ) ) );
		$trusted = get_option( 'wtsr_trusted_hosts', array() );
		if ( $this->database_error() ) {
			return $list;
		}
		if ( is_array( $trusted ) ) {
			foreach ( $trusted as $host ) {
				if ( is_string( $host ) && preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
					$hosts[] = strtolower( $host );
				}
			}
		}
		$html = $fetched['body'];
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			$this->uncertain[] = 'Artikel sumber memberikan respons kosong; metadata gambar belum dapat diperiksa.';
			return $list;
		}
		if ( ! preg_match( '/<(?:!doctype\s+html|html|head|meta|script)\b/i', $html ) ) {
			$this->uncertain[] = 'Respons artikel sumber tidak dapat dipastikan sebagai dokumen HTML.';
			return $list;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'META' ) ) {
			$key = $tags->get_attribute( 'property' );
			if ( ! is_string( $key ) || '' === $key ) {
				$key = $tags->get_attribute( 'name' );
			}
			$value = $tags->get_attribute( 'content' );
			if ( is_string( $key ) && is_string( $value ) && '' !== trim( $value ) && in_array( strtolower( trim( $key ) ), array( 'og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src' ), true ) ) {
				$this->add_candidate( $list, array( 'url' => $this->absolute_url( $value, $base ), 'hosts' => $hosts, 'label' => 'Metadata gambar artikel sumber (' . strtolower( $key ) . ')' ) );
			}
		}
		$articles = array();
		if ( preg_match_all( '~<script\b([^>]*)>(.*?)</script\s*>~is', $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $script ) {
				$tag = new \WP_HTML_Tag_Processor( '<script ' . $script[1] . '></script>' );
				$tag->next_tag( 'SCRIPT' );
				$script_type = $tag->get_attribute( 'type' );
				if ( ! is_string( $script_type ) || 'application/ld+json' !== strtolower( trim( $script_type ) ) ) {
					continue;
				}
				$data = json_decode( $script[2], true, 32 );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					$this->uncertain[] = 'JSON-LD artikel sumber tidak dapat dibaca; informasi gambar mungkin belum terperiksa.';
					continue;
				}
				$this->collect_articles( $data, $articles, $base );
			}
		}
		$this->article_images( $articles, $list, $base, $hosts );
		if ( ! $list ) {
			$this->evidence[] = 'Artikel sumber tidak mencantumkan og:image, twitter:image, atau Article.image.';
		}
		return $list;
	}

	private function collect_articles( $node, &$articles, $base, $depth = 0 ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( $depth > 24 || count( $articles ) >= self::MAX_CANDIDATES ) {
			$this->uncertain[] = 'Struktur JSON-LD melebihi batas pemeriksaan; sumber belum diperiksa sepenuhnya.';
			return;
		}
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
		foreach ( $types as $type ) {
			if ( is_string( $type ) && 'ImageObject' === preg_replace( '~^https?://schema\.org/~', '', $type ) && ! empty( $node['@id'] ) && is_string( $node['@id'] ) ) {
				$this->schema_images[ $this->absolute_url( $node['@id'], $base ) ] = $node;
			}
			if ( is_string( $type ) && in_array( preg_replace( '~^https?://schema\.org/~', '', $type ), array( 'Article', 'NewsArticle', 'BlogPosting', 'TechArticle', 'ScholarlyArticle' ), true ) && isset( $node['image'] ) ) {
				$articles[] = $node;
				return; // Nested recommendations belong to other articles.
			}
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) && ( is_int( $key ) || in_array( $key, array( '@graph', 'mainEntity', 'mainEntityOfPage' ), true ) ) ) {
				$this->collect_articles( $value, $articles, $base, $depth + 1 );
			} elseif ( is_array( $value ) && preg_match( '/"@type"\s*:\s*(?:"(?:https?:\\/\\/schema\.org\\/)?(?:Article|NewsArticle|BlogPosting|TechArticle|ScholarlyArticle)"|\[[^\]]*"(?:Article|NewsArticle|BlogPosting|TechArticle|ScholarlyArticle)")/', json_encode( $value, JSON_UNESCAPED_SLASHES ) ) ) {
				$this->uncertain[] = 'Article terdapat dalam struktur JSON-LD yang belum didukung; identitas sumber perlu ditinjau.';
			}
		}
	}

	private function article_images( $articles, &$list, $base, $hosts ) {
		$matched = $unknown = array();
		foreach ( $articles as $article ) {
			$identities = array();
			foreach ( array( 'url', '@id', 'mainEntityOfPage' ) as $key ) {
				if ( empty( $article[ $key ] ) ) {
					continue;
				}
				$value = $article[ $key ];
				if ( is_array( $value ) ) {
					$value = isset( $value['@id'] ) ? $value['@id'] : ( isset( $value['url'] ) ? $value['url'] : '' );
				}
				if ( is_string( $value ) && '' !== $value ) {
					$identities[] = $this->identity_url( $this->absolute_url( $value, $base ) );
				}
			}
			if ( ! $identities ) {
				$unknown[] = $article;
			} elseif ( in_array( $this->identity_url( $base ), $identities, true ) ) {
				$matched[] = $article;
			} else {
				$this->evidence[] = 'Article.image untuk artikel lain tidak digunakan sebagai thumbnail posting ini.';
				$this->uncertain[] = 'Identitas Article.image berbeda dari URL sumber; sumber artikel yang benar perlu ditinjau.';
			}
		}
		if ( ! $matched && count( $unknown ) > 1 ) {
			$this->uncertain[] = 'Dokumen memuat beberapa Article tanpa identitas yang jelas; gambar artikel yang benar belum dapat dipastikan.';
			return;
		}
		foreach ( $matched ? $matched : $unknown as $article ) {
			$this->image_object( $article['image'], $list, $base, $hosts );
		}
	}

	private function image_object( $image, &$list, $base, $hosts, $depth = 0 ) {
		if ( $depth > 16 ) {
			$this->uncertain[] = 'Struktur Article.image terlalu dalam untuk diperiksa.';
			return;
		}
		if ( is_string( $image ) && '' !== trim( $image ) ) {
			$this->add_candidate( $list, array( 'url' => $this->absolute_url( $image, $base ), 'hosts' => $hosts, 'label' => 'Article.image pada artikel sumber' ) );
		} elseif ( is_array( $image ) ) {
			$understood = false;
			foreach ( $image as $key => $value ) {
				if ( is_int( $key ) || in_array( $key, array( 'url', 'contentUrl' ), true ) ) {
					$understood = true;
					$this->image_object( $value, $list, $base, $hosts, $depth + 1 );
				}
			}
			if ( ! $understood && ! empty( $image['@id'] ) && is_string( $image['@id'] ) ) {
				$reference = $this->absolute_url( $image['@id'], $base );
				if ( isset( $this->schema_images[ $reference ] ) ) {
					$understood = true;
					$this->image_object( $this->schema_images[ $reference ], $list, $base, $hosts, $depth + 1 );
				}
			}
			if ( ! $understood ) {
				$this->uncertain[] = 'Referensi Article.image tidak dapat diuraikan menjadi URL gambar yang terverifikasi.';
			}
		} else {
			$this->uncertain[] = 'Nilai Article.image tidak dapat dibaca sebagai sumber gambar.';
		}
	}

	private function safe_url( $url, $hosts = null ) {
		if ( ! is_string( $url ) || strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return new \WP_Error( 'wtsr_bad_url' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return new \WP_Error( 'wtsr_bad_url' );
		}
		$host = strtolower( $parts['host'] );
		if ( is_array( $hosts ) && ! in_array( $host, $hosts, true ) ) {
			return new \WP_Error( 'wtsr_untrusted_host' );
		}
		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
		if ( $this->excluded_path( $path ) || preg_match( '/\.svg(?:$|\/)/i', $path ) ) {
			return new \WP_Error( 'wtsr_placeholder' );
		}
		$url = preg_replace( '/#.*$/s', '', $url );
		if ( ! wp_http_validate_url( $url ) || ! $this->public_host( $host ) ) {
			return new \WP_Error( 'wtsr_unsafe_url' );
		}
		return $url;
	}

	private function excluded_path( $path ) {
		return (bool) preg_match( '~(?:^|/)(?:[^/]*[-_.])?(?:placeholder|spacer|transparent|blank|loading|logo|site-icon|favicon)(?:[-_.][^/]*)?(?:\.(?:gif|png|jpe?g|webp|avif|svg|ico))?$~i', $path );
	}

	private function public_host( $host ) {
		if ( preg_match( '/(?:^localhost$|\.(?:localhost|local|internal)$)/i', $host ) ) {
			return false;
		}
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$addresses = array( $host );
		} else {
			$addresses = gethostbynamel( $host );
			if ( ! is_array( $addresses ) || ! $addresses ) {
				return false;
			}
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				// DNS lookup failures are handled below; do not leak a PHP warning into AJAX JSON.
				$records = @dns_get_record( $host, DNS_AAAA );
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						if ( ! empty( $record['ipv6'] ) ) {
							$addresses[] = $record['ipv6'];
						}
					}
				}
			}
		}
		foreach ( $addresses as $address ) {
			if ( ! filter_var( $address, FILTER_VALIDATE_IP, $flags ) || ! $this->public_address( $address ) ) {
				return false;
			}
		}
		return true;
	}

	private function public_address( $address ) {
		if ( filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$denied = array( array( '0.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '100.64.0.0', 10 ), array( '127.0.0.0', 8 ), array( '169.254.0.0', 16 ), array( '172.16.0.0', 12 ), array( '192.0.0.0', 24 ), array( '192.0.2.0', 24 ), array( '192.88.99.0', 24 ), array( '192.168.0.0', 16 ), array( '198.18.0.0', 15 ), array( '198.51.100.0', 24 ), array( '203.0.113.0', 24 ), array( '224.0.0.0', 4 ), array( '240.0.0.0', 4 ) );
			$numeric = ip2long( $address );
			foreach ( $denied as $block ) {
				$mask = -1 << ( 32 - $block[1] );
				if ( ( $numeric & $mask ) === ( ip2long( $block[0] ) & $mask ) ) {
					return false;
				}
			}
			return true;
		}
		$packed = inet_pton( $address );
		// IPv6 global unicast only. Exclude transition, documentation and special-purpose prefixes.
		return false !== $packed && 0x20 === ( ord( $packed[0] ) & 0xe0 ) && substr( $packed, 0, 4 ) !== inet_pton( '2001:db8::' )[0] . inet_pton( '2001:db8::' )[1] . inet_pton( '2001:db8::' )[2] . inet_pton( '2001:db8::' )[3] && !( 0x20 === ord( $packed[0] ) && 0x01 === ord( $packed[1] ) && ord( $packed[2] ) < 2 ) && !( 0x20 === ord( $packed[0] ) && 0x02 === ord( $packed[1] ) );
	}

	private function absolute_url( $url, $base ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ) {
			return $url;
		}
		return \WP_Http::make_absolute_url( $url, $base );
	}

	private function upload_relative_path( $url ) {
		$uploads = wp_get_upload_dir();
		$base = wp_parse_url( $uploads['baseurl'] );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $base ) || ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $base['host'] ) ) {
			return '';
		}
		$prefix = rtrim( isset( $base['path'] ) ? $base['path'] : '', '/' ) . '/';
		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
		if ( 0 !== strpos( $path, $prefix ) || preg_match( '~(?:^|/)\.\.(?:/|$)~', $path ) ) {
			return '';
		}
		return substr( $path, strlen( $prefix ) );
	}

	private function local_id( $url ) {
		// A hostname match alone is insufficient: use exact WordPress media references.
		$clean = preg_replace( '/[?#].*$/s', '', $url );
		$id = attachment_url_to_postid( $clean );
		$this->database_error();
		if ( $id ) {
			return (int) $id;
		}
		$original = preg_replace( '/-[0-9]+x[0-9]+(?=\.[a-z0-9]+$)/i', '', $clean );
		if ( $original !== $clean ) {
			$id = attachment_url_to_postid( $original );
			$this->database_error();
			if ( $id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private function database_error() {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			$this->uncertain[] = 'Database gagal membaca atau menyimpan informasi sumber; pemeriksaan harus diulang.';
			return true;
		}
		return false;
	}

	private function display_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' );
	}

	private function identity_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
}
