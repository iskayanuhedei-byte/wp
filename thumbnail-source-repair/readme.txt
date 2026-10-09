=== Thumbnail Source Repair ===
Contributors: iskayanuhedei-byte
Tags: featured image, thumbnail, posts, repair
Requires at least: 6.2
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Periksa featured image post, pulihkan dari sumber yang dapat ditelusuri, lalu hapus post tanpa gambar hanya dengan persetujuan eksplisit.

== Description ==

Alat administrator untuk memeriksa post terpublikasi yang kehilangan featured image. Plugin memeriksa gambar di isi post, lampiran milik post, dan URL artikel sumber yang dicatat pada editor. Tidak ada gambar acak atau pencarian gambar internet.

Mode bawaan adalah pratinjau. Pemulihan dan pemulihan disertai penghapusan permanen tersedia sebagai tindakan manual terpisah. Penghapusan memerlukan persetujuan eksplisit. Kesalahan jaringan, sumber yang ditolak, dan kegagalan penyimpanan mempertahankan post agar dapat dicoba lagi.

Artikel sumber harus memakai HTTPS. Gambar metadata artikel hanya diambil dari host sumber atau nama host CDN tepercaya yang ditambahkan administrator. Berkas gambar, batas ukuran, akses jaringan yang aman, dan hasil penyimpanan diperiksa sebelum dipakai.

Tidak ada cron atau tindakan otomatis saat aktivasi. Lampiran media tidak ikut dihapus ketika post dihapus.

== Installation ==

1. Unggah folder plugin ke wp-content/plugins/ atau unggah ZIP melalui halaman Plugin.
2. Aktifkan plugin.
3. Buka Peralatan > Perbaikan Thumbnail sebagai administrator.
4. Jalankan pratinjau dan periksa bukti sebelum memilih mode perubahan.

Memerlukan pemroses gambar WordPress (GD atau Imagick). Cadangkan situs sebelum menggunakan mode penghapusan permanen.

== Frequently Asked Questions ==

= Dari mana gambar diambil? =

Dari featured image yang masih valid, gambar di isi post, lampiran milik post, atau metadata gambar artikel sumber yang dicatat khusus pada post. Artikel sumber mendukung og:image, twitter:image, dan Article.image dalam JSON-LD.

= Apakah post dihapus saat jaringan bermasalah? =

Tidak. Kesalahan jaringan dan sumber yang tidak dapat diverifikasi ditandai untuk dicoba lagi. Hanya hasil pemeriksaan yang memastikan tidak ada gambar layak yang dapat dihapus melalui mode penghapusan dengan persetujuan eksplisit.

= Apakah plugin berjalan otomatis? =

Tidak. Administrator memulai pemindaian dan pemrosesan dari halaman alat.

= Apakah gambar dari CDN didukung? =

Ya. Tambahkan hostname CDN yang tepat pada pengaturan host tepercaya, tanpa URL lengkap atau wildcard.

== Changelog ==

= 1.0.0 =

* Pratinjau, pemulihan berdasarkan sumber, dan penghapusan permanen dengan konfirmasi.
* Pemeriksaan berkas gambar, asal URL, hak akses, nonce, perubahan post, dan pemrosesan berurutan.
