# Laporan Final Review: Desain & Kesiapan Fase 3.2 Midtrans Notification Webhook

**Proyek:** Beautyland (PHP Native 8.1, MariaDB 12.2, Midtrans Snap)  
**Dokumen:** Final Review Arsitektur Webhook dan Kesiapan Migrasi Database  
**Tanggal Review:** 26 November 2026  
**Dokumen Rujukan:**
- `docs/ai-generated/analysis-fase3-2-webhook.md`
- `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql`
- `database/migrations/20261126_add_midtrans_columns.sql`
- `includes/stock_reservation.php`
- `pelanggan/create_snap_token.php`

---

## 1. Daftar Koreksi Desain yang Telah Diselesaikan

Berdasarkan hasil evaluasi kritis terhadap perancangan awal, enam koreksi arsitektural telah diselesaikan dan diintegrasikan ke dalam dokumen analisis utama:

1. **Koreksi Semantik `snap_status`:**
   - Kolom `snap_status` dikembalikan secara murni ke tujuan aslinya: merekam siklus permintaan pembuatan token Snap (`Menunggu`, `Berhasil`, `Gagal`, `Perlu Rekonsiliasi`).
   - Dilarang menurunkan `snap_status` menjadi `'Gagal'` saat pesanan mengalami refund atau kedaluwarsa. Token yang pernah terbit dan digunakan tetap bernilai `'Berhasil'`.
   - Keterbatasan ketiadaan kolom khusus status rekonsiliasi didokumentasikan secara transparan tanpa menambah kolom baru secara terburu-buru.

2. **Pengetatan Otorisasi Transaksi `capture` (Kartu Kredit):**
   - Mengisolasi penanganan status `capture` berdasarkan `fraud_status`:
     - `accept`: Pembayaran sah, ubah `status_pembayaran = 'Lunas'`, panggil `convert_stock_reservation()`.
     - `challenge`: Pembayaran ditahan FDS, pertahankan `status_pembayaran = 'Menunggu Pembayaran'`, reservasi stok tetap aktif.
     - `deny`: Pembayaran ditolak, ubah `status_pembayaran = 'Dibatalkan'`, panggil `release_stock_reservation()`.
   - **Prinsip Fail-Safe:** Jika `fraud_status` kosong, bernilai null, atau tidak dikenal, sistem **dilarang keras menganggapnya sebagai accept**. Sistem wajib memperlakukannya sebagai *challenge/anomaly*, tidak memotong stok, dan menahannya untuk rekonsiliasi.

3. **Revisi Keamanan Nominal Tanpa Floating Point:**
   - Menghapus ketergantungan pada `(int)round((float)...)` yang rentan terhadap ketidakpresisian biner IEEE 754 dan toleransi manipulasi angka pecahan (misal nilai rekayasa `"150000.49"` yang terpotong menjadi 150000).
   - Menerapkan validasi regex ketat `/^[1-9][0-9]{0,11}(\.00)?$/` untuk memastikan format nominal murni integer IDR, menghapus akhiran `".00"` secara manipulasi string deterministik, dan membandingkannya secara identik (*strict integer comparison*) dengan `transaksi.total_harga`.

4. **Aturan Tegas Penanganan Late Settlement:**
   - Menetapkan aturan mutlak jika pesanan lokal telah `Dibatalkan` dan reservasinya telah dilepas, namun kemudian Midtrans mengirimkan notifikasi `settlement`:
     - DILARANG mengonversi reservasi stok yang sudah dilepas.
     - DILARANG memotong stok fisik gudang (mencegah stok bernilai negatif / *overselling* fisik).
     - DILARANG menghidupkan kembali (*un-cancel*) pesanan atau mengubahnya menjadi `Lunas`.
     - Menandai transaksi dengan `snap_status = 'Perlu Rekonsiliasi'` dan mencatat *Critical Alert Log* agar admin memproses refund manual di portal Midtrans atau menghubungi pembeli.
     - Notifikasi duplikat ditangani secara idempotent (No-Op HTTP 200).

5. **Pemisahan Domain Refund & Audit Dampak Kode:**
   - Memisahkan status pembayaran pesanan (`'Refund'`), status gateway (`'refund'`), dan melarang pemotongan atau pengembalian stok otomatis pada produk fisik skincare.
   - Mengaudit seluruh 6 file PHP yang berinteraksi dengan `status_pembayaran` dan memetakan penyesuaian UI serta filter bisnis yang diperlukan.

6. **Klarifikasi Batasan Jejak Audit (Notification Audit Strategy):**
   - Mengoreksi klaim awal; sistem saat ini **hanya menyimpan snapshot payload terakhir** di `transaksi.notification_payload` dan ringkasan event satu baris di server error log.
   - Menegaskan bahwa retensi log bergantung pada konfigurasi rotasi server, dan tabel riwayat notifikasi *append-only* direncanakan untuk fase lanjutan.

---

## 2. Daftar Risiko yang Masih Tersisa

Meskipun desain logika telah disempurnakan, masih terdapat beberapa risiko teknis dan operasional yang perlu diwaspadai:

### Risiko 1: Karakteristik Non-Transaksional DDL pada MariaDB (Tingkat: SEDANG)
- **Uraian:** MariaDB mengeksekusi perintah DDL (`ALTER TABLE`, `CREATE TABLE`) dengan *implicit commit*. Operasi ini tidak dapat di-rollback secara otomatis jika terjadi interupsi koneksi di tengah eksekusi file migrasi.
- **Dampak:** Kegagalan parsial akan meninggalkan skema setengah jadi.
- **Mitigasi:** File migrasi `20261126_add_stock_reservations_and_checkout_idempotency.sql` tidak boleh dijalankan ulang membabi buta jika sempat gagal di tengah jalan. Administrator wajib memeriksa kolom yang telah terbentuk via `information_schema` sebelum melanjutkan.

### Risiko 2: Tampilan Badge "Menunggu" untuk Status 'Refund' pada UI Eksisting (Tingkat: RENDAH)
- **Uraian:** File `pelanggan/pesanan_user.php` dan `admin/pesanan.php` saat ini hanya memiliki percabangan `if ('lunas')` dan `elseif ('batal')`. Nilai baru `'Refund'` akan jatuh ke blok `else` yang menampilkan badge kuning "Menunggu".
- **Dampak:** Pelanggan atau admin dapat salah mengira bahwa pesanan yang sudah di-refund masih menunggu pembayaran.
- **Mitigasi:** Telah dipetakan rekomendasi penambahan percabangan CSS badge `'status-refund'` saat mengimplementasikan webhook atau pada tahap pemolesan UI.

### Risiko 3: Pesanan Batal/Refund Masih Muncul di Antrean Pengiriman (Tingkat: SEDANG)
- **Uraian:** Kueri di `admin/pengiriman.php` tidak memfilter `status_pembayaran`.
- **Dampak:** Pesanan yang telah dibatalkan atau di-refund tetap memiliki kartu pengiriman, sehingga staf gudang berisiko salah memasukkan nomor resi dan mengirim barang.
- **Mitigasi:** Pada Fase 3.2 atau pembaruan modul pengiriman, form input resi wajib dinonaktifkan jika `status_pembayaran IN ('Dibatalkan', 'Refund')`.

### Risiko 4: Keterbatasan Riwayat Notifikasi Tunggal (Tingkat: RENDAH)
- **Uraian:** Kolom `transaksi.notification_payload` menimpa JSON lama saat notifikasi baru masuk. Kronologi peralihan status dari `pending` ke `settlement` hingga `refund` tidak terekam secara tabular di database.
- **Dampak:** Investigasi forensik perselisihan pembayaran bergantung pada log server PHP atau portal Midtrans MAP.
- **Mitigasi:** Cukup untuk skala operasional saat ini; tabel relasional `riwayat_notifikasi_midtrans` direkomendasikan untuk Fase 4.

---

## 3. Status Kesiapan Implementasi Webhook

| Komponen | Status Kesiapan | Catatan Evaluasi |
|---|---|---|
| **Verifikasi Keamanan Kriptografis** | **SIAP** | SHA-512 dengan `hash_equals()` lokal, kebal timing attack. |
| **Validasi Nominal Transaksi** | **SIAP** | Regex ketat dan perbandingan integer deterministik tanpa float. |
| **Penanganan State Machine** | **SIAP** | Matriks transisi anti-regresi dan pengetatan FDS kartu kredit. |
| **Idempotensi & Concurrency** | **SIAP** | Dibungkus transaksi atomik dengan `SELECT ... FOR UPDATE` dan double-guard stok. |
| **Integrasi Helper Stok** | **SIAP SECARA KONSEPTUAL** | Logika pemanggilan `convert_stock_reservation()` dan `release_stock_reservation()` sudah padu, namun **terhalang blocker skema database**. |

**Kesimpulan:** Desain arsitektur webhook **100% SIAP DITULIS**, namun endpoint tidak dapat dieksekusi secara end-to-end sebelum database siap.

---

## 4. Status Kesiapan Migrasi Database

| Pemeriksaan Preflight | Status | Bukti / Catatan Audit |
|---|---|---|
| **Pengecekan Objek Eksisting** | **CLEAR (SIAP)** | Kolom `stok_direservasi`, `snap_status`, `checkout_idempotency_key`, dan tabel `reservasi_stok` terbukti belum ada di DB aktif. Tidak ada konflik duplikasi. |
| **Kompatibilitas Tipe Data & FK** | **CLEAR (SIAP)** | Kolom foreign key `int(11)` cocok persis dengan primary key induk `transaksi` dan `produk`. |
| **Engine, Charset, & Collation** | **CLEAR (SIAP)** | Engine `InnoDB` dan collation `utf8mb4_0900_ai_ci` didukung penuh oleh server MariaDB 12.2. |
| **Status Eksekusi Riil** | **BLOCKER (BELUM DIJALANKAN)** | Skrip migrasi `20261126_add_stock_reservations_and_checkout_idempotency.sql` **belum dieksekusi** ke kontainer `mariadb-server`. |

**Kesimpulan:** File migrasi **SECARA TEKNIS AMAN DAN VALID UNTUK DIEKSEKUSI**, namun saat ini menjadi **SATU-SATUNYA BLOCKER AKTIF** yang menghalangi pengujian runtime.

---

## 5. Urutan Tindakan Berikutnya

Untuk memastikan kelancaran dan keamanan transisi ke tahap penulisan kode, urutan tindakan berikut harus dilakukan:

```text
[Langkah 1: Eksekusi Migrasi Database oleh Pengguna]
  Jalankan perintah berikut di terminal server:
  docker exec -i mariadb-server mariadb -u root -p'ZkyZors-177013__' beautyland < database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql

[Langkah 2: Verifikasi Pasca-Migrasi]
  Jalankan pemeriksaan kueri non-destruktif:
  - SHOW COLUMNS FROM produk LIKE 'stok_direservasi';
  - SHOW COLUMNS FROM transaksi LIKE 'checkout_idempotency_key';
  - SHOW TABLES LIKE 'reservasi_stok';

[Langkah 3: Pembuatan Endpoint midtrans_notification.php]
  Tulis kode endpoint webhook pada root direktori proyek mengacu pada spesifikasi di analysis-fase3-2-webhook.md:
  - Validasi metode POST dan JSON decoding.
  - Verifikasi signature SHA-512.
  - Kunci baris transaksi dengan SELECT ... FOR UPDATE.
  - Validasi nominal via regex dan integer murni.
  - Terapkan state machine, konversi stok, dan response HTTP sesuai rancangan.

[Langkah 4: Penyesuaian UI & Filter Eksisting (Minor Patch)]
  - Tambahkan penanganan badge 'Refund' di pelanggan/pesanan_user.php dan admin/pesanan.php.
  - Tambahkan proteksi peringatan pada admin/pengiriman.php untuk status 'Dibatalkan' dan 'Refund'.

[Langkah 5: Pengujian Simulasi Webhook (Mock Testing)]
  - Lakukan simulasi payload HTTP POST untuk settlement, duplicate settlement, capture challenge/accept/deny, expire, dan late settlement.
```

---

## Kesimpulan Akhir

Desain arsitektur Fase 3.2 telah rampung secara menyeluruh dan telah menyelesaikan seluruh catatan evaluasi kritis. **Implementasi kode dapat segera dimulai setelah administrator menjalankan skrip migrasi database Fase 3.1.1.**
