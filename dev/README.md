# Lingkungan pengembangan lokal

Lingkungan ini memakai WordPress 6.8.3, PHP 8.3, dan MariaDB 11.4 melalui Docker Compose. Semua data adalah data pengembangan. Database tidak memiliki port publik; HTTP hanya diikat ke loopback. Jangan memakai konfigurasi database pengembangan ini untuk produksi.

Jalankan dari checkout yang sudah tersedia, tanpa membuat worktree baru:

```sh
cd /workspace/wp
docker compose -f dev/compose.yaml pull
bash dev/start.sh
docker compose -f dev/compose.yaml exec -T --user www-data wordpress php /var/www/html/wp-content/plugins/thumbnail-source-repair/tests/integration.php /var/www/html
python3 dev/package.py
```

`start.sh` dapat diulang. Skrip menyalin kode plugin terbaru ke container, memperbaiki izin berkas salinannya, menginisialisasi WordPress bila belum terpasang, mengaktifkan plugin, dan memeriksa respons halaman situs. Data WordPress dan database berada di volume Docker; proses server harus dijalankan ulang pada mesin baru. Kata sandi administrator uji dihasilkan saat instalasi dan tidak dicetak atau disimpan dalam kode. Tidak diperlukan kredensial situs produksi.

Container memakai proxy sesi yang disediakan platform. Bundle CA sistem dipasang hanya-baca dan dipakai melalui konfigurasi HTTP khusus lingkungan pengembangan; tidak ada sertifikat atau kredensial sesi di dalam ZIP plugin. TLS tetap diverifikasi. Akses artikel nyata juga memerlukan hostname sumber dan gambar yang diizinkan oleh pengaturan jaringan cloud.

Pengujian integrasi membuat fixture lalu membersihkannya, termasuk penghapusan permanen posting fixture. Jalankan hanya pada lingkungan uji. Respons HTTPS sumber dan gambar disimulasikan melalui `pre_http_request`; tidak ada unduhan dari artikel sungguhan selama tes. Entri `extra_hosts` untuk `example.com` dan `example.org` memberi resolusi alamat publik yang deterministik untuk pemeriksaan keamanan URL fixture; entri tersebut bukan layanan sumber nyata. Pemeriksaan TLS dan validasi URL plugin tetap aktif.

ZIP instalasi dihasilkan di `dist/thumbnail-source-repair-1.0.0.zip` dan hanya berisi kode plugin serta dokumentasi. Tes dan alat Docker tidak masuk ZIP.

Untuk menghentikan layanan yang dibuat konfigurasi ini:

```sh
docker compose -f dev/compose.yaml stop
```

Pengujian antarmuka browser opsional memerlukan Playwright yang sudah tersedia di lingkungan dan Chromium. Tidak ada dependensi Node untuk penggunaan plugin di WordPress:

```sh
CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium node thumbnail-source-repair/tests/admin-browser.cjs
```
