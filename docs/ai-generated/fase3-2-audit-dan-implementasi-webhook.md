# Laporan Audit dan Implementasi Fase 3.2 — Midtrans Notification Webhook

**Proyek:** Beautyland (PHP Native 8.1, MariaDB 12.2, Midtrans Snap SDK)  
**Task:** PPL-12 (Fase 3.2)  
**Tanggal:** 26 November 2026  
**Dokumen:** Audit Pra-Implementasi, Dokumentasi Implementasi, dan Verifikasi Pengujian

---

## 1. Ringkasan Audit Pra-Implementasi

Sebelum melakukan modifikasi kode, audit menyeluruh dilakukan terhadap kode basis aktual, database aktif (`mariadb-server`), dan dokumen arsitektur:

### 1.1. Status Skema Database Aktual
- **Tabel `produk`:** Telah memiliki kolom `stok_direservasi INT NOT NULL DEFAULT 0` (berhasil dimigrasi secara manual via DBeaver).
- **Tabel `transaksi`:** Kolom `midtrans_order_id`, `midtrans_transaction_id`, `snap_token`, `midtrans_payment_type`, `midtrans_status`, `notification_payload`, `updated_at`, `stok_dikurangi`, `checkout_idempotency_key`, `snap_status`, dan `snap_expires_at` telah aktif beserta indeks `idx_midtrans_order_id`, `idx_checkout_idempotency_key`, dan `idx_snap_status`.
- **Tabel `reservasi_stok`:** Telah dibuat dengan kolom `status_reservasi ENUM('Aktif', 'Dikonversi', 'Dilepas')`, indeks, dan constraint foreign key ke `transaksi` dan `produk`.

### 1.2. Fungsi Helper dan Tanggung Jawab Batas Transaksi
- Modul `includes/stock_reservation.php` menyediakan fungsi:
  - `convert_stock_reservation(mysqli $conn, int $id_transaksi): array`
  - `release_stock_reservation(mysqli $conn, int $id_transaksi): array`
- **Aturan Batas Transaksi:** Seluruh fungsi helper tidak melakukan `commit` maupun `rollback`. Tanggung jawab transaksi MySQL berada penuh pada endpoint pemanggil (`midtrans_notification.php`) melalui `$conn->begin_transaction()`, `$conn->commit()`, dan `$conn->rollback()`.
- **Idempotensi Helper:** Helper memeriksa baris reservasi berstatus `'Aktif'`. Jika reservasi sudah dikonversi atau dilepas, helper secara aman mengembalikan `converted = 0` / `released = 0` (No-Op).

### 1.3. Audit Kerapuhan pada File Eksisting
1. **`admin/pengiriman.php`:**
   - Handler POST `$_POST['simpan']` sebelumnya hanya mengecek `status_pengiriman !== 'Selesai'`, tanpa memeriksa `status_pembayaran`.
   - Pesanan yang berstatus `Dibatalkan` atau `Refund` rentan diperbarui nomor resinya dan diproses kirim oleh staf gudang.
2. **`pelanggan/pesanan_user.php` & `admin/pesanan.php`:**
   - Hanya memiliki pengondisian badge untuk kata `'lunas'` dan `'batal'`.
   - Status baru `'Refund'` jatuh ke blok default/else yang menampilkan badge kuning **"Menunggu"**.
3. **`admin/laporan.php`:**
   - Kueri `produk_terlaris` sebelumnya menjumlahkan seluruh `detail_transaksi` tanpa memeriksa apakah transaksinya berstatus `'Lunas'`, sehingga pesanan batal dan refund ikut terhitung sebagai barang terjual.

---

## 2. Implementasi `midtrans_notification.php`

Endpoint webhook dibuat di root direktori `/var/www/html/Beautyland/midtrans_notification.php` dengan prinsip-prinsip berikut:

### 2.1. Validasi Request & Keamanan Kriptografis
- **Metode HTTP:** Hanya menerima `POST`. Request selain POST ditolak dengan `HTTP 405 Method Not Allowed`.
- **Parsing JSON:** Membaca stream murni `php://input`. Jika kosong atau bukan JSON valid, ditolak dengan `HTTP 400 Bad Request`.
- **Field Wajib:** Memvalidasi keberadaan `order_id`, `status_code`, `gross_amount`, `signature_key`, dan `transaction_status`.
- **Verifikasi Signature SHA-512:** Menggunakan `hash_equals()` untuk membandingkan signature yang dihitung secara timing-safe dengan rumus:
  `SHA512(order_id + status_code + gross_amount + server_key)`.
  Jika tidak cocok, ditolak dengan `HTTP 401 Unauthorized` tanpa membocorkan Server Key.

### 2.2. Validasi Nominal Deterministik (Anti-Floating Point)
- Menerapkan regex ketat `/^[1-9][0-9]{0,11}(\.00)?$/` untuk menolak format pecahan desimal bukan nol, notasi ilmiah, nilai negatif, atau karakter non-numerik.
- Menghapus akhiran `".00"` menggunakan `preg_replace('/\.00$/', '', $raw_amount_str)`.
- Melakukan perbandingan integer dan string secara ketat terhadap `transaksi.total_harga`. Jika tidak cocok, transaksi di-rollback dan ditolak dengan `HTTP 400 Bad Request`.

### 2.3. Transaksi Database Atomik & Concurrency Control
- Seluruh evaluasi transaksi dibungkus dalam blok `$conn->begin_transaction()`.
- Menggunakan `SELECT ... FOR UPDATE` pada tabel `transaksi` berdasarkan `midtrans_order_id` untuk mengunci baris dari eksekusi paralel.
- Pemanggilan `convert_stock_reservation()` atau `release_stock_reservation()` dilakukan di dalam transaksi yang sama.
- Kegagalan operasi memicu `$conn->rollback()` dan mengembalikan `HTTP 500` agar Midtrans melakukan retry otomatis.

### 2.4. Penegakan State Machine Pembayaran
1. **Status Menunggu Pembayaran:**
   - `settlement` → ubah `status_pembayaran = 'Lunas'`, panggil `convert_stock_reservation()`, set `stok_dikurangi = 1`.
   - `capture` dengan `fraud_status = accept` → ubah `status_pembayaran = 'Lunas'`, konversi stok.
   - `capture` dengan `fraud_status = challenge` → pertahankan `Menunggu Pembayaran`, simpan `midtrans_status = challenge`, reservasi stok tetap aktif.
   - `capture` dengan `fraud_status = deny` → ubah `status_pembayaran = 'Dibatalkan'`, panggil `release_stock_reservation()`.
   - `capture` dengan fraud status kosong/tidak dikenal (*fail-safe*) → diperlakukan sebagai challenge, stok tidak dipotong.
   - `expire`, `cancel`, `deny` → ubah `status_pembayaran = 'Dibatalkan'`, panggil `release_stock_reservation()`.
   - `pending` → pertahankan `Menunggu Pembayaran`, catat `midtrans_status = pending`.
2. **Status Lunas (Terminal Sukses):**
   - Settlement/capture accept duplikat → No-Op (idempoten).
   - Pending, expire, cancel, deny yang terlambat → diabaikan (out-of-order, tidak boleh menurunkan status Lunas).
   - `refund` → ubah `status_pembayaran = 'Refund'`, `midtrans_status = 'refund'`. **Stok fisik tidak direstock otomatis**.
   - `partial_refund` → pertahankan `status_pembayaran = 'Lunas'`, simpan `midtrans_status = 'partial_refund'`.
3. **Status Dibatalkan (Terminal Batal):**
   - Expire, cancel, deny, pending duplikat/terlambat → No-Op.
   - `settlement` atau `capture accept` terlambat (Late Settlement) → **tetap `Dibatalkan`**. Tidak mengonversi reservasi, tidak memotong stok fisik gudang, simpan `midtrans_status = 'settlement'`, catat alert kritis untuk rekonsiliasi admin.
   - `refund` setelah late settlement → **tetap `Dibatalkan`** (dilarang berubah jadi Lunas atau Refund), simpan `midtrans_status = 'refund'`.
4. **Status Refund:**
   - Refund duplikat → No-Op.
   - Tidak boleh berubah kembali menjadi Lunas atau Dibatalkan.
   - Data pengiriman historis tidak dihapus.

### 2.5. Semantik Murni Kolom
- `snap_status` tidak dimutasi pada webhook; nilainya tetap merepresentasikan siklus inisiasi token.
- `status_pembayaran` murni merepresentasikan status pesanan lokal.
- `midtrans_status` merepresentasikan status transaksi resmi dari gateway Midtrans.

---

## 3. Rincian Patching Modul Lain

### 3.1. Proteksi Backend `admin/pengiriman.php`
- Menambahkan validasi relasi database pada handler POST `$_POST['simpan']`:
  ```php
  $stmt_cek = $conn->prepare("
      SELECT p.status_pengiriman, t.status_pembayaran 
      FROM pengiriman p 
      JOIN transaksi t ON p.id_transaksi = t.id_transaksi 
      WHERE p.id_pengiriman = ?
  ");
  ```
- Menolak pembaruan pengiriman jika `status_pembayaran IN ('Dibatalkan', 'Refund')`.
- Pada UI rendering, form input resi dan jasa kirim dinonaktifkan jika pesanan berstatus batal atau refund, disertai badge peringatan.
- Data pengiriman historis tetap dipertahankan utuh.

### 3.2. Penyesuaian Tampilan Status Refund pada UI
- **`pelanggan/pesanan_user.php`:** Menambahkan CSS `.status-refund { background: #e0f2fe; color: #0284c7; }` dan percabangan badge untuk status refund.
- **`admin/pesanan.php`:** Menambahkan CSS `.status-refund`, percabangan badge admin, serta proteksi tombol aksi agar transaksi refund tidak menampilkan tombol verifikasi manual.

### 3.3. Penyesuaian Kueri Laporan Produk Terlaris `admin/laporan.php`
- Memperbaiki kueri `produk_terlaris` dengan `LEFT JOIN transaksi t ON dt.id_transaksi = t.id_transaksi` dan agregasi kondisional:
  `COALESCE(SUM(CASE WHEN t.status_pembayaran = 'Lunas' THEN dt.jumlah ELSE 0 END), 0) as total_terjual`
- Menambahkan styling badge `.refund` dan `.batal` pada tabel data transaksi laporan.

### 3.4. Proteksi Transaksi Terminal pada `pelanggan/create_snap_token.php`
- Menambahkan pengecekan status terminal `if ($status_bayar === 'Refund')` pada penanganan transaksi eksisting dan respons terlambat agar tidak membuat token baru untuk pesanan yang telah di-refund.

---

## 4. Daftar File yang Berubah

| No | File | Status | Keterangan Perubahan |
|---|---|---|---|
| 1 | `midtrans_notification.php` | **Dibuat Baru** | Implementasi webhook Midtrans lengkap dengan verifikasi signature SHA-512, row locking `FOR UPDATE`, validasi nominal deterministik, state machine anti-regresi, dan integrasi reservasi stok. |
| 2 | `admin/pengiriman.php` | **Dimodifikasi** | Patch validasi backend POST handler dan UI form disable untuk pesanan `Dibatalkan` dan `Refund`. |
| 3 | `admin/pesanan.php` | **Dimodifikasi** | Patch styling badge status `Refund` dan proteksi tombol aksi admin. |
| 4 | `admin/laporan.php` | **Dimodifikasi** | Penyesuaian kueri produk terlaris (hanya menghitung transaksi `Lunas`) dan penambahan badge CSS `refund` serta `batal`. |
| 5 | `pelanggan/pesanan_user.php` | **Dimodifikasi** | Patch CSS dan percabangan badge status `Refund` pada daftar pesanan pelanggan. |
| 6 | `pelanggan/create_snap_token.php` | **Dimodifikasi** | Penambahan penanganan status terminal `'Refund'` pada pengecekan idempotensi awal dan late snap response. |

---

## 5. Hasil Pemeriksaan Sintaks dan Pengujian

### 5.1. Pemeriksaan Sintaks PHP (`php -l`)
Semua file yang disentuh telah diperiksa dan dinyatakan bebas dari kesalahan sintaks:
- `midtrans_notification.php` → **PASS**
- `admin/pengiriman.php` → **PASS**
- `admin/pesanan.php` → **PASS**
- `admin/laporan.php` → **PASS**
- `pelanggan/pesanan_user.php` → **PASS**
- `pelanggan/create_snap_token.php` → **PASS**
- `includes/stock_reservation.php` → **PASS**
- `includes/midtrans.php` → **PASS**

### 5.2. Pengujian Mock HTTP Terhadap `midtrans_notification.php`
Pengujian simulasi HTTP request dilakukan terhadap server lokal menggunakan PHP cURL:
1. **Request Metode GET:** Menghasilkan `HTTP 405 Method Not Allowed` (`{"status":"error","message":"Method not allowed."}`). → **PASS**
2. **Request Body Kosong:** Menghasilkan `HTTP 400 Bad Request` (`{"status":"error","message":"Empty request body."}`). → **PASS**
3. **Payload JSON Rusak:** Menghasilkan `HTTP 400 Bad Request` (`{"status":"error","message":"Invalid JSON payload."}`). → **PASS**
4. **Parameter Wajib Hilang:** Menghasilkan `HTTP 400 Bad Request` (`{"status":"error","message":"Missing or invalid required notification fields."}`). → **PASS**
5. **Signature Palsu / Tidak Cocok:** Menghasilkan `HTTP 401 Unauthorized` (`{"status":"error","message":"Invalid signature key."}`). → **PASS**
6. **Format Nominal Manipulatif (`150000.49`):** Menghasilkan `HTTP 400 Bad Request` (`{"status":"error","message":"Invalid gross amount format."}`). → **PASS**
7. **Signature Sah, Order ID Tidak Ditemukan:** Menghasilkan `HTTP 404 Not Found` (`{"status":"error","message":"Transaction not found."}`). → **PASS**

---

## 6. Daftar Test Case Runtime yang Belum Dijalankan

Karena pengujian di atas baru mencakup pengujian statis dan pengujian edge-case HTTP non-destruktif, skenario runtime end-to-end berikut dicatat untuk pengujian integrasi selanjutnya menggunakan Midtrans Simulator:

- [ ] **TC-01: Settlement Normal (Bank Transfer / VA)**
  - Simulasi pembayaran lunas melalui simulator Midtrans.
  - Verifikasi `status_pembayaran` berubah menjadi `'Lunas'`.
  - Verifikasi baris `reservasi_stok` berubah menjadi `'Dikonversi'`.
  - Verifikasi `produk.stok` berkurang dan `produk.stok_direservasi` berkurang secara sinkron.
  - Verifikasi `stok_dikurangi = 1`.
- [ ] **TC-02: Credit Card Capture Accept**
  - Simulasi kartu kredit dengan `fraud_status = accept`.
  - Verifikasi konversi stok dan status lunas.
- [ ] **TC-03: Credit Card Capture Challenge**
  - Simulasi kartu kredit dengan `fraud_status = challenge`.
  - Verifikasi pesanan tetap `Menunggu Pembayaran` dan stok tetap direservasi.
- [ ] **TC-04: Credit Card Capture Deny**
  - Simulasi kartu kredit ditolak FDS.
  - Verifikasi status menjadi `Dibatalkan` dan reservasi stok dilepas.
- [ ] **TC-05: Expire Notification**
  - Simulasi pesanan kedaluwarsa dari Midtrans.
  - Verifikasi status menjadi `Dibatalkan` dan `release_stock_reservation()` mengembalikan kuantitas reservasi ke stok bebas.
- [ ] **TC-06: Duplikat Settlement Simultan**
  - Mengirimkan dua webhook settlement berturut-turut untuk pesanan yang sama.
  - Verifikasi stok tidak berkurang dua kali (`stok_dikurangi` guard bekerja).
- [ ] **TC-07: Late Settlement Setelah Pesanan Dibatalkan**
  - Mensimulasikan webhook settlement masuk setelah pesanan kedaluwarsa lokal.
  - Verifikasi status tetap `Dibatalkan`, stok fisik tidak berkurang, dan alert kritis tercatat di log server.
- [ ] **TC-08: Refund Penuh Pasca Settlement**
  - Mensimulasikan notifikasi refund untuk pesanan yang sudah lunas.
  - Verifikasi status pesanan berubah menjadi `Refund`.
  - Verifikasi stok fisik tidak bertambah otomatis (menunggu inspeksi manual gudang).
- [ ] **TC-09: Refund Setelah Late Settlement**
  - Mensimulasikan refund untuk transaksi late settlement.
  - Verifikasi status pesanan tetap `Dibatalkan` dan `midtrans_status = refund`.

---

## 7. Daftar Risiko dan Keterbatasan Tersisa

1. **Retensi Audit Trail:** Kolom `transaksi.notification_payload` hanya menyimpan snapshot notifikasi valid terakhir. Jika terjadi siklus `pending` → `settlement` → `refund`, payload lama tertimpa. Investigasi forensik perselisihan bergantung pada server error log PHP dan Midtrans Merchant Administration Portal (MAP).
2. **Restock Fisik Produk Skincare:** Restock produk yang di-refund tidak dilakukan secara otomatis untuk mencegah produk cacat/rusak kembali ke stok aktif. Hal ini menuntut prosedur operasional standar (SOP) pengecekan manual oleh staf gudang.
3. **Penyelarasan Kolom Rekonsiliasi:** Identifikasi transaksi rekonsiliasi (late settlement) mengandalkan kueri kombinasi `status_pembayaran = 'Dibatalkan' AND midtrans_status = 'settlement'`. Ketiadaan kolom khusus `reconciliation_status` membatasi otomatisasi filter pelaporan di dashboard jika volume transaksi sangat masif.

---

## 8. Kesimpulan

Fase 3.2 telah selesai diimplementasikan secara komprehensif:
1. Endpoint `midtrans_notification.php` telah aktif dengan pengamanan kriptografis SHA-512, normalisasi nominal deterministik, transaksi database atomik, dan kepatuhan penuh terhadap helper reservasi stok.
2. Modul pengiriman `admin/pengiriman.php` telah diproteksi di tingkat backend dan antarmuka pengguna terhadap pesanan batal dan refund.
3. Tampilan status dan kueri pelaporan telah diselaraskan untuk memperlakukan transaksi refund secara akurat.
4. Seluruh pengujian statis dan pengujian unit edge-case HTTP telah lolos dengan status **PASS**.
