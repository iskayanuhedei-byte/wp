# Thumbnail Source Repair

Plugin WordPress untuk memeriksa posting terbit tanpa thumbnail atau dengan thumbnail rusak, memulihkannya dari sumber terkait yang terverifikasi, dan menghapus permanen posting tanpa gambar melalui tindakan administrator yang dikonfirmasi.

## Unduh dan pasang

[Unduh ZIP plugin versi 1.0.0](https://github.com/iskayanuhedei-byte/wp/raw/refs/heads/main/dist/thumbnail-source-repair-1.0.0.zip)

1. Unduh ZIP di atas. Gunakan ZIP plugin di folder `dist`, bukan arsip seluruh repositori.
2. Di WordPress, buka **Plugin → Tambah Baru → Unggah Plugin**, unggah ZIP, lalu aktifkan **Thumbnail Source Repair**.
3. Buka **Tools → Perbaikan Thumbnail** dan mulai dengan **Pratinjau**.

Pemulihan memakai gambar dalam posting, galeri, lampiran terkait, atau halaman sumber asli yang Anda catat. Jika pemeriksaan gagal karena jaringan atau sumber ambigu, posting dipertahankan untuk dicoba ulang. Mode **Perbaiki + hapus permanen** hanya menghapus posting ketika pemeriksaan tuntas dan tidak ada gambar yang dapat dipulihkan; penghapusan tidak masuk Sampah.

[Panduan penggunaan lengkap](thumbnail-source-repair/README.md) · [Lingkungan pengembangan dan pengujian](dev/README.md)

Versi ini telah diuji pada WordPress 6.8.3 dan PHP 8.3: 56 pengujian integrasi dan 3 pengujian antarmuka lulus. Respons gambar/sumber jarak jauh pada tes integrasi memakai fixture terkontrol.
