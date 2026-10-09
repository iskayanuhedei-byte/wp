# Perbaikan Thumbnail Berdasarkan Sumber

Plugin WordPress untuk memeriksa featured image pada **post yang sudah dipublikasikan**, memulihkan gambar dari sumber milik post tersebut, dan menghapus post yang benar-benar tidak memiliki gambar yang bisa dipulihkan.

Sumber yang diperiksa berurutan: featured image yang masih valid, gambar atau ID media di isi post, lampiran gambar milik post, lalu URL artikel sumber yang dicatat khusus untuk post. Plugin tidak mengambil gambar acak dari pustaka media atau mencari gambar di internet. Gambar harus berupa berkas raster yang valid; SVG, placeholder, URL berbahaya, dan respons yang bukan gambar tidak digunakan.

## Instalasi

Memerlukan WordPress 6.2+, PHP 7.4+, dan pemroses gambar WordPress (GD atau Imagick). Untuk mengambil gambar dari artikel sumber, server harus dapat mengakses HTTPS.

1. Salin folder `thumbnail-source-repair` ke `wp-content/plugins/`, atau unggah ZIP plugin melalui **Plugin → Tambah Baru → Unggah Plugin**.
2. Aktifkan **Thumbnail Source Repair**.
3. Buka **Peralatan → Perbaikan Thumbnail** sebagai administrator.

Tidak ada pemindaian, perubahan post, atau penghapusan otomatis saat aktivasi.

## Penggunaan

Mulai dengan mode **Pratinjau** untuk melihat status dan bukti sumber. Pilih **Perbaiki saja** untuk memasang featured image yang dapat diverifikasi. Mode **Perbaiki + hapus permanen** akan mencoba pemulihan terlebih dahulu, kemudian menghapus permanen hanya post yang dipastikan tidak memiliki kandidat gambar yang dapat digunakan. Mode ini memerlukan kotak persetujuan penghapusan. Penghapusan permanen tidak dapat dibatalkan; siapkan cadangan sebelum menjalankannya.

Kesalahan jaringan, sumber yang ditolak, keterbatasan izin, atau kegagalan penyimpanan ditandai **perlu dicoba lagi** dan mempertahankan post. Post yang berubah setelah dipindai harus dipindai ulang sebelum diproses. Pemrosesan berjalan berurutan dan dapat dihentikan dari halaman alat; operasi yang sedang berjalan diselesaikan terlebih dahulu.

Untuk post yang mengambil gambar dari artikel asal, isi **URL halaman sumber asli (HTTPS)** pada kotak **Sumber asli untuk thumbnail** di editor post dengan URL HTTPS artikel yang benar. Plugin membaca `og:image`, `twitter:image`, atau `Article.image` dari JSON-LD. Secara bawaan gambar dari artikel harus berasal dari host artikel itu sendiri. Jika artikel memakai CDN, tambahkan **nama host CDN yang tepat** di pengaturan host tepercaya, misalnya `images.example.com`; jangan masukkan URL lengkap atau wildcard. Pengaturan ini memberi izin pada host gambar, bukan menentukan bahwa gambar sesuai dengan isi artikel.

Status yang ditampilkan:

| Status | Artinya |
| --- | --- |
| Sehat | Featured image valid dan berkas aslinya tersedia. |
| Dapat diperbaiki | Ada kandidat gambar dengan sumber yang dapat ditelusuri. |
| Diperbaiki | Featured image berhasil dipasang dan diperiksa ulang. |
| Tidak ditemukan | Semua sumber yang tersedia telah diperiksa tanpa kegagalan sementara, tetapi tidak ada gambar yang dapat digunakan. |
| Perlu dicoba lagi | Ada kegagalan atau sumber yang belum dapat dipercaya; post dipertahankan. |
| Dihapus | Penghapusan permanen telah diverifikasi berhasil. |

Lampiran media yang mungkin dipakai post lain tidak dihapus ketika post dihapus. Plugin memerlukan kemampuan administrator untuk menjalankan alat, kemampuan mengedit post untuk pemulihan, dan kemampuan menghapus post untuk penghapusan.

Plugin memasang gambar unggulan dan memperbaiki ukuran gambarnya. Tema situs perlu menampilkan gambar unggulan agar gambar tersebut terlihat pada halaman situs. Tidak ada perubahan otomatis pada templat tema.

## Validasi pengembangan

Gunakan instalasi WordPress uji terpisah dengan plugin terpasang. Pengujian membuat post, lampiran, dan pengguna sementara, menguji HTTP dengan respons lokal, lalu membersihkan fixture tersebut. Jangan menjalankannya pada situs produksi.

```sh
php tests/integration.php /path/to/wordpress
```

Tes mengembalikan kode keluar bukan nol jika ada kegagalan. Pemeriksaan sintaks dapat dijalankan dengan:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Plugin tidak memerlukan Composer, Node.js, atau layanan eksternal untuk menjalankan antarmuka administrasinya.

Pengujian antarmuka opsional tersedia pada `tests/admin-browser.cjs`. Dengan Playwright dan Chromium tersedia, jalankan `node tests/admin-browser.cjs`. Untuk memakai Chromium sistem, tentukan `CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium`. Pengujian ini memeriksa pratinjau, escaping isi post, konfirmasi penghapusan, urutan permintaan, dan tombol berhenti memakai respons HTTP fixture.
