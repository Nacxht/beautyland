# Laporan Analisis & Revisi Desain Fase 3.2 — Midtrans Notification Webhook

**Proyek:** Beautyland (PHP Native 8.1, MySQLi, Midtrans Snap)  
**Dokumen:** Analisis Teknis, Desain Arsitektur Webhook Handler, dan Preflight Migrasi (Final Disempurnakan)  
**Tanggal Revisi:** 26 November 2026  
**File Target Implementasi:** `midtrans_notification.php` (Root Directori)  
**Dokumen Terkait:**
- `docs/ai-generated/audit-checkout-midtrans.md`
- `docs/ai-generated/fase2-migrasi-database.md`
- `docs/ai-generated/fase3-1-create-snap-token.md`
- `docs/ai-generated/review-fase3-1.md`
- `docs/ai-generated/fase3-1-1-hardening.md`
- `docs/ai-generated/final-review-fase3-1-1.md`
- `docs/ai-generated/fix-fase3-1-1-final-review.md`
- `docs/ai-generated/fix-f01-late-snap-response.md`
- `docs/ai-generated/final-concurrency-recovery-audit.md`
- `docs/ai-generated/final-review-fase3-2-webhook.md`
- `docs/ai-generated/final-technical-correction-fase3-2.md`

---

## Ringkasan Eksekutif

Laporan ini merupakan hasil evaluasi teknis mendalam dan finalisasi arsitektur Fase 3.2 — Midtrans Notification Webhook (`midtrans_notification.php`). Endpoint ini bertindak sebagai otoritas penyelesaian siklus pembayaran secara asinkron dari Midtrans ke sistem internal Beautyland.

Revisi ini secara khusus menyempurnakan:
1. **Koreksi Semantik Murni `snap_status`:** Membatasi `snap_status` secara konsisten murni sebagai indikator siklus pembuatan token Snap. Menolak penurunan status menjadi `Gagal` saat refund, dan melarang pengubahan status menjadi `Perlu Rekonsiliasi` saat late settlement. Mengidentifikasi kasus rekonsiliasi murni menggunakan kombinasi data yang telah tersedia (`status_pembayaran` dan `midtrans_status`) tanpa penambahan kolom baru.
2. **Pengetatan Penanganan `capture`:** Mengisolasi penanganan kartu kredit berdasarkan `fraud_status`. Menolak tegas fraud status kosong/tidak dikenal (*fail-safe*) agar tidak pernah dianggap sebagai `accept`.
3. **Revisi Keamanan Nominal:** Menggantikan perbandingan *floating point* dengan normalisasi desimal deterministik berbasis regex dan integer murni.
4. **Late Settlement dan Refund Komprehensif:** Menetapkan aturan ketat penanganan pembayaran sukses yang terlambat tiba pada pesanan yang sudah berstatus `Dibatalkan` dan reservasinya telah dilepas, termasuk penanganan notifikasi duplikat, refund purna-late settlement, dan pending terlambat.
5. **Proteksi Backend Modul Pengiriman:** Merancang validasi backend pada `admin/pengiriman.php` agar pesanan `Dibatalkan` dan `Refund` tidak dapat diproses kirim, serta menjamin data pengiriman historis tidak terhapus otomatis saat refund tiba.
6. **Notification Audit Strategy:** Menjelaskan secara jujur bahwa `notification_payload` hanya menyimpan snapshot payload terakhir dan server log hanya menyimpan ringkasan, tanpa mengklaimnya sebagai *complete immutable audit trail*.
7. **Revised State Machine:** Matriks transisi status komprehensif anti-regresi status terminal.
8. **Database Migration Preflight:** Verifikasi komparatif skema database aktual terhadap file migrasi Fase 3.1.1, mencakup sifat DDL non-transaksional, risiko kegagalan parsial, dan analisis idempotensi migrasi.

---

## 1. Kondisi Implementasi Saat Ini

### 1.1. Arsitektur Hasil Fase 3.1.1
Pada Fase 3.1.1, sistem telah berhasil memitigasi kelemahan transaksional pada checkout Midtrans dengan menerapkan:
1. **Persist-Before-Call Pattern (`pelanggan/create_snap_token.php`):**
   - Transaksi lokal, detail transaksi, data pengiriman awal, dan reservasi stok disimpan dan di-commit terlebih dahulu (`$conn->commit()`) sebelum pemanggilan API eksternal `\Midtrans\Snap::createTransaction()`.
2. **Helper Stock Reservation Terpisah (`includes/stock_reservation.php`):**
   - `reserve_stock()`: Mencatat reservasi sementara dan menambah `produk.stok_direservasi` menggunakan row locking terurut (`id_produk ASC`) untuk mencegah deadlock dan overselling (**AC-18**).
   - `convert_stock_reservation()`: Mengonversi reservasi aktif menjadi penjualan aktual saat pembayaran lunas (**AC-19**), mengurangi `produk.stok` dan `produk.stok_direservasi` secara atomik, serta menandai `reservasi_stok.status_reservasi = 'Dikonversi'`.
   - `release_stock_reservation()`: Melepaskan kuantitas dari `produk.stok_direservasi` dan menandai `reservasi_stok.status_reservasi = 'Dilepas'` jika transaksi batal atau kedaluwarsa.
3. **Mitigasi Concurrency & Late Response:**
   - **Fix B-01:** Penanganan MySQL Error 1062 pada `checkout_idempotency_key` secara elegan mengambil transaksi eksisting tanpa HTTP 500.
   - **Fix W-01 & W-02:** Penanganan transaksi tertahan di status `Menunggu` > 120 detik dialihkan ke `Perlu Rekonsiliasi`. Kegagalan pasca-token tidak melepas reservasi.
   - **Fix F-01:** Proteksi row locking (`SELECT ... FOR UPDATE`) pada Tahap 3A memastikan status pembayaran terminal (`Lunas` atau `Dibatalkan`) tidak pernah tertimpa oleh respons Snap yang terlambat.

### 1.2. Inventarisasi Status Aktual pada Kode Basis

Berdasarkan audit langsung pada seluruh file PHP dalam proyek, berikut adalah nilai status yang benar-benar digunakan:

#### A. Status Pembayaran (`transaksi.status_pembayaran`)
- `'Menunggu Pembayaran'`: Status awal saat pesanan dibuat melalui gateway Midtrans (`create_snap_token.php:362`).
- `'Menunggu Verifikasi'`: Status awal untuk transaksi manual melalui transfer bank dengan unggah bukti (`pelanggan/proses_pembayaran.php:71, 105`).
- `'Lunas'`: Status terminal ketika pembayaran diverifikasi berhasil (manual di `admin/pesanan.php:22`, atau otomatis via Midtrans). Menjadi filter utama laporan pendapatan di `admin/laporan.php:46, 118` dan `admin/dashboard_admin.php:16`.
- `'Dibatalkan'`: Status terminal saat pesanan dibatalkan atau kedaluwarsa sebelum pembayaran berhasil diterima.
- `'Refund'`: Status bisnis purna-jual saat pesanan yang telah lunas dikembalikan dananya secara penuh melalui gateway.

#### B. Semantik Murni Status Snap Token (`transaksi.snap_status`)
Kolom `snap_status` secara khusus dan murni hanya mencerminkan **siklus hidup pembuatan Snap Token**, BUKAN status pembayaran, status pengiriman, status gateway, atau status refund:
- `'Menunggu'`: Ditetapkan saat record lokal pertama kali di-insert ke tabel `transaksi`, menandakan proses panggilan ke API Snap sedang berlangsung.
- `'Berhasil'`: Ditetapkan ketika Snap Token berhasil diterima dari Midtrans dan disimpan ke database. Sekali bernilai `'Berhasil'`, status ini **TIDAK PERNAH DIUBAH** oleh event purna-bayar seperti refund, pembatalan pesanan, kedaluwarsa tagihan, maupun late settlement.
- `'Gagal'`: Ditetapkan HANYA jika panggilan pembuatan Snap Token ke API Midtrans gagal secara definitif (misalnya format parameter ditolak atau otentikasi gagal).
- `'Perlu Rekonsiliasi'`: Ditetapkan HANYA saat terjadi kegagalan transmisi jaringan pembuatan token (cURL 28 timeout) atau pesanan tertahan di status `'Menunggu'` > 120 detik pada saat inisiasi checkout.

#### C. Identifikasi Kasus Rekonsiliasi Menggunakan Data Eksisting (Tanpa Kolom Baru)
Sistem **tidak memerlukan kolom database baru** untuk melacak anomali pembayaran atau kebutuhan rekonsiliasi. Identifikasi dilakukan secara deterministik melalui kombinasi kolom yang sudah ada dari Fase 2:
1. **Kasus Late Settlement (Pembayaran Diterima Setelah Pembatalan):**
   - Query: `WHERE status_pembayaran = 'Dibatalkan' AND midtrans_status = 'settlement'`
   - Kombinasi ini secara unik menandakan bahwa pesanan telah dibatalkan di sistem lokal, namun Midtrans berhasil menyelesaikan pembayaran.
2. **Kasus Late Settlement yang Sudah Di-Refund:**
   - Query: `WHERE status_pembayaran = 'Dibatalkan' AND midtrans_status = 'refund'`
   - Menandakan anomali late settlement telah diselesaikan melalui pengembalian dana di Midtrans MAP.
3. **Kasus Challenge Kartu Kredit:**
   - Query: `WHERE status_pembayaran = 'Menunggu Pembayaran' AND midtrans_status = 'challenge'`
   - Menandakan transaksi ditahan FDS Midtrans dan memerlukan review manual di MAP.

Pendekatan ini mematuhi prinsip kebersihan data: `snap_status` tidak dikotori (*overloaded*) oleh status pasca-token, dan skema database tidak perlu diubah.

#### D. Flag Pengurangan Stok (`transaksi.stok_dikurangi`)
- `0`: Stok aktual belum dipotong (kondisi awal checkout Midtrans; kuantitas produk hanya berstatus direservasi).
- `1`: Stok aktual sudah dipotong permanen dari tabel `produk` via `convert_stock_reservation()` (idempotency guard).

#### E. Status Reservasi Stok (`reservasi_stok.status_reservasi`)
- `'Aktif'`: Reservasi sementara sedang berjalan dan menahan stok dari pembeli lain.
- `'Dikonversi'`: Reservasi berhasil diubah menjadi penjualan resmi setelah pembayaran lunas.
- `'Dilepas'`: Reservasi dibatalkan dan kuantitas dikembalikan ke stok bebas.

---

## 2. Struktur Database yang Dibutuhkan

### 2.1. Audit Skema Aktual Database Aktif (`mariadb-server`)

Pemeriksaan langsung pada kontainer `mariadb-server` (MariaDB 12.2.2, database `beautyland`) menghasilkan temuan berikut:

```text
Table: transaksi
├── id_transaksi (int(11), PK, AI)
├── id_pelanggan (int(11))
├── tgl_transaksi (timestamp)
├── total_harga (int(11))
├── metode_pembayaran (varchar(50))
├── status_pembayaran (varchar(50))
├── kode_pembayaran (varchar(30))
├── bukti_pembayaran (varchar(255))
├── midtrans_order_id (varchar(50), UNIQUE)  <-- Migrasi Fase 2 TERPASANG
├── midtrans_transaction_id (varchar(255))   <-- Migrasi Fase 2 TERPASANG
├── snap_token (varchar(255))                <-- Migrasi Fase 2 TERPASANG
├── midtrans_payment_type (varchar(50))      <-- Migrasi Fase 2 TERPASANG
├── midtrans_status (varchar(50))            <-- Migrasi Fase 2 TERPASANG
├── notification_payload (longtext)          <-- Migrasi Fase 2 TERPASANG
├── updated_at (timestamp)                   <-- Migrasi Fase 2 TERPASANG
└── stok_dikurangi (tinyint(1), default 0)   <-- Migrasi Fase 2 TERPASANG

Table: produk
├── id_produk (int(11), PK, AI)
├── nama_produk (varchar(100))
├── deskripsi (text)
├── harga (int(11))
├── stok (int(11))
└── gambar (varchar(255))
(Catatan: stok_direservasi BELUM ADA)

Tabel Tambahan:
└── reservasi_stok                           <-- BELUM ADA
```

### 2.2. Analisis Kebutuhan Struktur Kolom untuk Fase 3.2

1. **Kebutuhan Kolom pada Tabel `transaksi`:**
   - Kolom-kolom penampung data webhook (`midtrans_order_id`, `midtrans_transaction_id`, `midtrans_payment_type`, `midtrans_status`, `notification_payload`, `stok_dikurangi`, `updated_at`) **sudah tersedia lengkap** di database berkat migrasi Fase 2.
   - **Tidak ada penambahan kolom baru** pada tabel `transaksi` khusus untuk Fase 3.2 guna menjaga kesederhanaan skema.
2. **Kebutuhan Relasional (Prasyarat Mutlak):**
   - Agar Webhook dapat memanggil `convert_stock_reservation()` dan `release_stock_reservation()`, skema migrasi Fase 3.1.1 (`20261126_add_stock_reservations_and_checkout_idempotency.sql`) **wajib dijalankan terlebih dahulu**.

---

## 3. Revisi Keamanan Nominal

### 3.1. Bahaya Floating Point dalam Verifikasi Finansial
Penggunaan konversi casting `(int)round((float)...)` mengandung bahaya tersembunyi:
1. **Ketidakpresisian IEEE 754:** Tipe data `float` tidak merepresentasikan pecahan desimal secara eksak pada representasi biner.
2. **Toleransi Longgar yang Membuka Celah Fraud:** Fungsi `round()` secara otomatis membulatkan pecahan. Jika penyerang mengirimkan nilai rekayasa seperti `"150000.49"`, casting float akan memotongnya menjadi `150000`, sehingga sistem menerima transaksi dengan nominal yang sebenarnya tidak cocok dengan nominal otorisasi gateway.
3. **Type Juggling PHP:** Casting `(float)"150000abc"` menghasilkan `150000`, mengabaikan karakter ilegal di belakang angka.

### 3.2. Normalisasi Desimal Aman dan Deterministik
Seluruh harga produk pada Beautyland disimpan dalam mata uang Rupiah (`IDR`) berupa bilangan bulat murni (`INT` pada database). Midtrans API mengirimkan parameter `gross_amount` dalam format string, yang umumnya berupa integer atau memiliki dua angka nol di belakang koma (misalnya `"150000"` atau `"150000.00"`).

Metode validasi yang diterapkan wajib mematuhi aturan:
1. **Validasi Format String Ketat (Regex):**
   Sebelum melakukan pemrosesan apa pun, periksa format string nominal secara ketat. Tolak segala bentuk pecahan bukan nol, notasi ilmiah, nilai negatif, atau karakter non-numerik.
   ```php
   $raw_amount = trim((string)($payload['gross_amount'] ?? ''));

   // Format sah: hanya digit murni atau digit yang diakhiri persis ".00"
   if (!preg_match('/^[1-9][0-9]{0,11}(\.00)?$/', $raw_amount)) {
       error_log("CRITICAL: Format gross_amount tidak valid atau mengandung pecahan non-nol: [{$raw_amount}]");
       http_response_code(400);
       echo json_encode(['status' => 'error', 'message' => 'Format nominal tidak valid.']);
       exit;
   }
   ```
2. **Normalisasi String Deterministik Tanpa Floating Point:**
   Hapus akhiran `".00"` jika ada dengan manipulasi string murni:
   ```php
   $normalized_amount_str = preg_replace('/\.00$/', '', $raw_amount);
   ```
3. **Pencocokan Identik (Strict Integer Comparison):**
   Setelah dipastikan string murni berisi digit integer, lakukan perbandingan nilai terhadap `total_harga` dari database:
   ```php
   $db_total = (int)$trx['total_harga'];
   $incoming_total = (int)$normalized_amount_str;

   if ($incoming_total !== $db_total || $normalized_amount_str !== (string)$db_total) {
       error_log("CRITICAL: Mismatch nominal transaksi BL-{$trx['id_transaksi']}! Payload: {$incoming_total}, DB: {$db_total}");
       $conn->rollback();
       http_response_code(400);
       echo json_encode(['status' => 'error', 'message' => 'Nominal transaksi tidak cocok.']);
       exit;
   }
   ```
Metode ini deterministik, kebal terhadap bug floating point, dan menolak tegas anomali manipulasi harga.

---

## 4. Pengetatan Penanganan Status `capture`

Transaksi kartu kredit pada Midtrans melalui tahap otorisasi dan penangkapan (*capture*) yang dievaluasi oleh *Fraud Detection System* (FDS). Payload notifikasi menyertakan parameter `fraud_status`.

Sistem menerapkan aturan ketat:
1. **`capture` + `fraud_status = 'accept'`:**
   - Pembayaran sah dan lolos FDS.
   - Tindakan: Pembayaran dinyatakan berhasil. Ubah `status_pembayaran = 'Lunas'`, panggil `convert_stock_reservation()`, set `stok_dikurangi = 1`, dan catat `midtrans_status = 'capture'`.
2. **`capture` + `fraud_status = 'challenge'`:**
   - Transaksi terindikasi mencurigakan dan ditahan oleh FDS.
   - Tindakan: Merchant harus melakukan review manual di Midtrans MAP (Accept/Deny). Pesanan **TETAP** berstatus `'Menunggu Pembayaran'`, `midtrans_status = 'challenge'`. Reservasi stok **TETAP AKTIF** (jangan dikonversi, jangan dilepas).
3. **`capture` + `fraud_status = 'deny'`:**
   - Transaksi ditolak oleh bank penerbit atau FDS.
   - Tindakan: Pembayaran gagal. Ubah `status_pembayaran = 'Dibatalkan'`, `midtrans_status = 'deny'`, panggil `release_stock_reservation()` untuk mengembalikan kuantitas stok reservasi ke stok bebas.
4. **`capture` dengan `fraud_status` Kosong, Null, atau Tidak Dikenal (*Fail-Safe*):**
   - **Prinsip Keamanan Mutlak:** Sistem **DILARANG MENGANGGAP** fraud status yang kosong/hilang sebagai `'accept'`!
   - Jangan pernah menandai pesanan sebagai `'Lunas'` dan jangan pernah memotong stok fisik.
   - Tindakan: Perlakukan sebagai *challenge/anomaly*. Catat peringatan audit, pertahankan pesanan pada status `'Menunggu Pembayaran'`, simpan `midtrans_status = 'challenge'`, dan tandai log bahwa transaksi memerlukan verifikasi manual administrator.

---

## 5. Late Settlement dan Refund Recovery

### 5.1. Definisi Masalah
Skenario **Late Settlement** terjadi ketika:
1. Pesanan lokal telah melewati batas kedaluwarsa (`snap_expires_at`).
2. Endpoint kedaluwarsa lokal atau notifikasi webhook `expire` telah dijalankan:
   - `status_pembayaran` telah menjadi `'Dibatalkan'`.
   - `release_stock_reservation()` telah dieksekusi: baris pada `reservasi_stok` telah berstatus `'Dilepas'`, dan `produk.stok_direservasi` telah dikurangi.
   - Stok fisik telah kembali bebas dan mungkin **sudah dibeli oleh pelanggan lain** (sehingga sisa stok fisik saat ini 0).
3. Namun, pelanggan ternyata berhasil membayar di detik-detik terakhir melalui saluran perbankan/ATM yang lambat memproses settlement, sehingga Midtrans kemudian mengirimkan notifikasi `settlement` atau `capture accept`.

### 5.2. Invarian dan Aturan Bisnis Wajib
Jika sistem naif menerima notifikasi ini dan langsung mengubah status menjadi `Lunas` serta memotong stok:
- Pemanggilan `convert_stock_reservation()` akan gagal karena tidak ada baris `Aktif`.
- Jika stok dipaksa potong, stok fisik di gudang akan bernilai negatif (*overselling* fisik).
- Toko berkewajiban mengirimkan barang yang fisiknya sudah tidak tersedia.

Oleh karena itu, ditetapkan aturan ketat:
1. **JANGAN mengonversi reservasi yang sudah berstatus 'Dilepas'.**
2. **JANGAN mengurangi stok fisik (`produk.stok`).**
3. **JANGAN otomatis mengubah `status_pembayaran` menjadi 'Lunas'.**
4. **JANGAN menghidupkan kembali (*un-cancel*) transaksi yang telah dibatalkan.**
5. **JANGAN mengubah `snap_status` (tetap 'Berhasil').**

### 5.3. Penanganan Empat Skenario Rantai Late Settlement

Sistem menangani siklus hidup transaksi late settlement secara komprehensif:

#### Skenario 1: Pesanan `Dibatalkan` Menerima `settlement` Pertama Kali
- **Kondisi Awal:** `status_pembayaran = 'Dibatalkan'`, `midtrans_status = 'expire'`, `snap_status = 'Berhasil'`, reservasi telah `Dilepas`, `stok_dikurangi = 0`.
- **Tindakan:**
  - Pertahankan `status_pembayaran = 'Dibatalkan'`.
  - Pertahankan `snap_status = 'Berhasil'`.
  - Perbarui `midtrans_status = 'settlement'`.
  - Simpan seluruh isi payload ke `notification_payload`.
  - JANGAN konversi reservasi, JANGAN potong stok fisik.
  - Catat *Critical Alert Log* untuk penanganan refund/kontak manual oleh admin.
  - Kembalikan `HTTP 200 OK`.

#### Skenario 2: Pesanan Tersebut Menerima `settlement` Duplikat (Retry)
- **Kondisi Awal:** `status_pembayaran = 'Dibatalkan'`, `midtrans_status = 'settlement'`, `snap_status = 'Berhasil'`.
- **Tindakan:**
  - Deteksi idempotensi: Transaksi sudah berstatus `Dibatalkan` dan `midtrans_status` sudah `settlement`.
  - Lakukan No-Op (tanpa pembaruan database, tanpa pemotongan stok, tanpa duplikasi alert).
  - Kembalikan `HTTP 200 OK`.

#### Skenario 3: Pesanan Tersebut Kemudian Menerima Notifikasi `refund`
- **Konteks:** Admin memproses refund melalui Midtrans Merchant Portal (MAP) karena stok fisik tidak dapat dipenuhi.
- **Tindakan:**
  - Deteksi: Pesanan berstatus `Dibatalkan` dengan `midtrans_status = 'settlement'`.
  - **Aturan Ketat:** Status pesanan **DILARANG BERUBAH MENJADI 'Lunas' MAUPUN 'Refund'**! Pesanan ini tidak pernah lunas dan tidak pernah dipenuhi, statusnya tetap `'Dibatalkan'`.
  - Perbarui `midtrans_status = 'refund'`.
  - Simpan payload refund ke `notification_payload`.
  - `snap_status` tetap `'Berhasil'`.
  - JANGAN lakukan restock (karena saat settlement stok fisik tidak pernah dipotong).
  - Catat log notice bahwa anomali late settlement telah selesai di-refund.
  - Kembalikan `HTTP 200 OK`.

#### Skenario 4: Pesanan Tersebut Menerima Notifikasi `pending` yang Terlambat
- **Konteks:** Notifikasi `pending` mengalami latensi jaringan ekstrem dan tiba setelah pesanan dibatalkan.
- **Tindakan:**
  - Deteksi: Pesanan sudah berada pada status terminal `Dibatalkan`.
  - Abaikan event `pending` sepenuhnya (No-Op).
  - Jangan ubah status pembayaran, jangan ubah status gateway.
  - Kembalikan `HTTP 200 OK`.

---

## 6. Refund State Management & Proteksi Backend Pengiriman

### 6.1. Pemisahan Domain Refund
Status `Dibatalkan` merepresentasikan pesanan yang **gagal sebelum pembayaran terjadi** (tidak pernah ada uang masuk dan barang tidak pernah diproses). Sebaliknya, `refund` normal adalah peristiwa purna-jual (*post-settlement event*) di mana dana yang telah diterima dikembalikan ke pembeli.

Sistem membedakan domain refund normal (pesanan lunas lalu di-refund) ke dalam 4 pilar terpisah:
- **Status Pembayaran:** Ditetapkan menjadi `'Refund'`.
- **Status Midtrans:** Ditetapkan menjadi `'refund'`.
- **Status Snap Token:** **TETAP `'Berhasil'`** (pembuatan token di masa lalu terbukti berhasil).
- **Dampak Stok Inventaris:** Restock manual oleh administrator gudang; Webhook **DILARANG** melakukan restock otomatis pada produk fisik.
- **Laporan Keuangan:** Dikeluarkan dari omset penjualan bersih pada `admin/laporan.php`.

### 6.2. Proteksi Backend Modul Pengiriman (`admin/pengiriman.php`)

Berdasarkan pemeriksaan mendalam pada `admin/pengiriman.php` (baris 8–31):
```php
if(isset($_POST['simpan'])){
    $id_pengiriman      = $_POST['id_pengiriman'];
    $jasa_kirim         = $_POST['jasa_kirim'];
    $nomor_resi         = $_POST['nomor_resi'];
    $status_pengiriman  = $_POST['status_pengiriman'];

    $cek_status = mysqli_query($conn, "SELECT status_pengiriman FROM pengiriman WHERE id_pengiriman = '$id_pengiriman'");
    $data_cek = mysqli_fetch_assoc($cek_status);

    if ($data_cek['status_pengiriman'] !== 'Selesai') {
        mysqli_query($conn, "UPDATE pengiriman SET ... WHERE id_pengiriman='$id_pengiriman'");
    }
}
```

#### Celah Keamanan yang Teridentifikasi:
1. Skrip backend pengiriman saat ini **sama sekali tidak memeriksa `transaksi.status_pembayaran`**.
2. Jika pengguna mengirimkan request HTTP POST langsung dengan `id_pengiriman` dari pesanan yang `Dibatalkan` atau `Refund`, server akan langsung mengeksekusi `UPDATE pengiriman` dan memperbarui `nomor_resi` serta `tanggal_kirim = NOW()`.
3. Validasi hanya di tingkat frontend (misal menyembunyikan form atau tombol) **tidak memadai** karena request POST dapat dimanipulasi dengan mudah.

#### Persyaratan Proteksi Backend yang Wajib Diterapkan:
1. **Validasi Status Pembayaran di Backend Handler:**
   Sebelum memproses update pengiriman, kueri wajib melakukan verifikasi relasional ke tabel `transaksi`:
   ```sql
   SELECT p.status_pengiriman, t.status_pembayaran 
   FROM pengiriman p
   JOIN transaksi t ON p.id_transaksi = t.id_transaksi
   WHERE p.id_pengiriman = ?
   ```
   Jika `t.status_pembayaran IN ('Dibatalkan', 'Refund')`:
   Backend **WAJIB MENOLAK** perubahan data pengiriman dan mengembalikan pesan error (HTTP 422 / redirect dengan alert peringatan bahwa pesanan telah dibatalkan atau di-refund).
2. **Proteksi Integritas Data Pengiriman Historis:**
   Ketika webhook menerima notifikasi `refund`, webhook **DILARANG MENGUBAH SECARA OTOMATIS** data di tabel `pengiriman` (kolom `status_pengiriman`, `nomor_resi`, dan `tanggal_kirim` tidak boleh di-reset atau di-NULL-kan). Data pengiriman historis mencerminkan aktivitas logistik riil yang telah terjadi di masa lalu.
3. **Penyelarasan Tampilan UI:**
   Pada rendering tabel/kartu `admin/pengiriman.php`, jika `status_pembayaran IN ('Dibatalkan', 'Refund')`, elemen `<form>` dinonaktifkan sepenuhnya dan digantikan oleh label badge proteksi: `<span class="badge-disabled">Pesanan Tidak Dapat Dikirim (Dibatalkan / Refund)</span>`.

---

## 7. Audit Keamanan Webhook & Notification Strategy

### 7.1. Checklist Persyaratan Keamanan Implementasi Webhook
Endpoint `midtrans_notification.php` wajib menerapkan 9 lapisan pertahanan ketat secara berurutan:
1. **Validasi Metode HTTP:** Wajib menolak metode selain `POST` dengan respons `HTTP 405 Method Not Allowed`.
2. **Validasi Payload JSON:** Membaca stream murni `php://input` dan melakukan `json_decode()`. Jika format JSON rusak atau kosong, tolak dengan `HTTP 400 Bad Request`.
3. **Validasi Field Wajib:** Memastikan keberadaan parameter `order_id`, `status_code`, `gross_amount`, `signature_key`, dan `transaction_status`. Jika salah satu tidak ada, tolak dengan `HTTP 400 Bad Request`.
4. **Verifikasi Kriptografis SHA-512:**
   Menghitung ulang signature:
   `$computed = hash('sha512', $order_id . $status_code . $gross_amount . $server_key);`
   Membandingkan dengan payload menggunakan `hash_equals($computed, $signature_key)` untuk mencegah timing attack. Jika tidak cocok, tolak dengan `HTTP 401 Unauthorized`.
5. **Validasi Nominal Tanpa Float:** Menerapkan regex format dan pencocokan integer murni (Bab 3).
6. **Pencarian Transaksi & Row Locking:** Menjalankan `SELECT ... FROM transaksi WHERE midtrans_order_id = ? FOR UPDATE` di dalam blok transaksi MySQL. Jika tidak ditemukan, rollback dan kembalikan `HTTP 404 Not Found`.
7. **Transaksi Database Atomik:** Menggunakan `$conn->begin_transaction()`, `$conn->commit()`, dan `$conn->rollback()` untuk menjamin seluruh pembaruan status dan reservasi stok bersifat *all-or-nothing*.
8. **Idempotensi Bertingkat:** Memeriksa `stok_dikurangi` dan status `reservasi_stok` untuk mencegah efek samping ganda.
9. **Penanganan Kegagalan Database & Strategi Retry:**
   - Jika terjadi kegagalan database transient (misal connection loss atau deadlock timeout): lakukan `$conn->rollback()` dan kembalikan `HTTP 500 Internal Server Error` agar Midtrans memicu mekanisme retry otomatis sesuai jadwalnya.
   - Jika kegagalan bersifat permanen atau data invalid: tolak dengan `HTTP 400/401/404` sesuai jenis kesalahan.

*Pernyataan Keterbatasan:* Meskipun desain arsitektur keamanan di atas telah dirancang sesuai standar industri (*best practice*), **tingkat keamanan aktual belum dapat diklaim sebagai teruji** sebelum pengujian penetrasi runtime dengan berbagai variasi mock payload berhasil dieksekusi.

### 7.2. Batasan Notification Audit Strategy
- `transaksi.notification_payload` hanya menyimpan snapshot JSON notifikasi valid terakhir.
- Server error log (`error_log`) hanya menyimpan ringkasan satu baris per event, dan retensinya bergantung pada konfigurasi server (rotasi log, kapasitas disk).
- Desain saat ini tidak menyediakan *complete immutable audit trail*. Tabel riwayat *append-only* dicatat sebagai rencana Fase 4.

---

## 8. Revised State Machine

Berikut adalah matriks transisi status yang telah disempurnakan dengan penegakan semantik murni `snap_status` dan aturan rantai Late Settlement:

| Status Saat Ini (`status_pembayaran`) | Inbound Event Midtrans | Kondisi / Fraud Status | Status Baru (`status_pembayaran`) | `midtrans_status` Baru | `snap_status` Baru | Tindakan Inventaris & Reservasi | Respons HTTP | Catatan State Machine |
|---|---|---|---|---|---|---|---|---|
| **`Menunggu Pembayaran`** | `pending` | - | `Menunggu Pembayaran` | `pending` | Tetap | Tidak ada aksi (reservasi tetap Aktif) | `200 OK` | Sesi aktif menunggu pembeli bayar. |
| **`Menunggu Pembayaran`** | `settlement` | - | `Lunas` | `settlement` | `Berhasil` | `convert_stock_reservation()` + `stok_dikurangi = 1` | `200 OK` | **Happy Path.** Reservasi dikonversi ke penjualan. |
| **`Menunggu Pembayaran`** | `capture` | `accept` | `Lunas` | `capture` | `Berhasil` | `convert_stock_reservation()` + `stok_dikurangi = 1` | `200 OK` | Kartu kredit berhasil dan lolos FDS. |
| **`Menunggu Pembayaran`** | `capture` | `challenge` | `Menunggu Pembayaran` | `challenge` | Tetap | Tidak ada aksi (reservasi tetap Aktif) | `200 OK` | Transaksi ditahan untuk review MAP. |
| **`Menunggu Pembayaran`** | `capture` | `deny` | `Dibatalkan` | `deny` | Tetap | `release_stock_reservation()` | `200 OK` | Ditolak FDS. Stok kembali bebas. `snap_status` tetap 'Berhasil'. |
| **`Menunggu Pembayaran`** | `capture` | *Kosong / Tidak Dikenal* | `Menunggu Pembayaran` | `challenge` | Tetap | **FAIL-SAFE:** Jangan potong stok! Reservasi tetap Aktif. | `200 OK` | Anomali FDS ditahan untuk rekonsiliasi. |
| **`Menunggu Pembayaran`** | `expire` | - | `Dibatalkan` | `expire` | Tetap | `release_stock_reservation()` | `200 OK` | Waktu bayar habis. Reservasi dilepas. |
| **`Menunggu Pembayaran`** | `cancel` | - | `Dibatalkan` | `cancel` | Tetap | `release_stock_reservation()` | `200 OK` | Dibatalkan sebelum bayar. Stok dilepas. |
| **`Menunggu Pembayaran`** | `deny` | - | `Dibatalkan` | `deny` | Tetap | `release_stock_reservation()` | `200 OK` | Ditolak provider. Stok dilepas. |
| **`Menunggu Pembayaran`** | `refund` / `partial_refund` | - | Tidak berubah | Tidak berubah | Tetap | Tidak ada aksi | `400 Bad Request` | Anomali: refund tiba pada transaksi belum lunas. |
| **`Lunas`** | `settlement` / `capture accept`| - | `Lunas` (Tetap) | `settlement` | Tetap (`Berhasil`)| No-Op (`stok_dikurangi` sudah 1) | `200 OK` | **Idempotent.** Abaikan notifikasi duplikat. |
| **`Lunas`** | `pending` | - | `Lunas` (Tetap) | Tetap | Tetap | No-Op | `200 OK` | **Out-of-Order.** Lindungi status lunas dari regresi. |
| **`Lunas`** | `expire` / `cancel` / `deny` | - | `Lunas` (Tetap) | Tetap | Tetap | No-Op | `200 OK` | **Out-of-Order.** Tolak pembatalan setelah settlement. |
| **`Lunas`** | `refund` | - | `Refund` | `refund` | Tetap (`Berhasil`)| Stok fisik tidak berubah otomatis | `200 OK` | Refund penuh. `snap_status` DILARANG berubah jadi Gagal! |
| **`Lunas`** | `partial_refund` | - | `Lunas` (Tetap) | `partial_refund`| Tetap (`Berhasil`)| Tidak ada aksi stok | `200 OK` | Refund parsial dicatat pada payload. |
| **`Dibatalkan`** | `expire` / `cancel` / `deny` | - | `Dibatalkan` (Tetap) | Tetap | Tetap | No-Op (reservasi sudah dilepas) | `200 OK` | **Idempotent.** Abaikan pembatalan berulang. |
| **`Dibatalkan`** | `pending` | - | `Dibatalkan` (Tetap) | Tetap | Tetap | No-Op | `200 OK` | **Out-of-Order.** Tolak pengaktifan kembali. |
| **`Dibatalkan`** | `settlement` / `capture accept`| - | `Dibatalkan` (Tetap) | `settlement` | Tetap (`Berhasil`)| **JANGAN konversi stok! JANGAN potong stok!** | `200 OK` | **Late Settlement.** `snap_status` DILARANG berubah jadi Perlu Rekonsiliasi! |
| **`Dibatalkan`** (Late Settlement) | `settlement` (Duplikat) | - | `Dibatalkan` (Tetap) | `settlement` | Tetap (`Berhasil`)| No-Op | `200 OK` | **Idempotent Late Settlement.** |
| **`Dibatalkan`** (Late Settlement) | `refund` | - | `Dibatalkan` (Tetap) | `refund` | Tetap (`Berhasil`)| No-Op (stok fisik memang belum terpotong) | `200 OK` | **Late Settlement Refund.** Status DILARANG berubah ke 'Lunas'/'Refund'! |
| **`Dibatalkan`** (Late Settlement) | `pending` (Terlambat) | - | `Dibatalkan` (Tetap) | Tetap | Tetap (`Berhasil`)| No-Op | `200 OK` | **Out-of-Order.** Abaikan event pending terlambat. |
| **Semua Status** | Status Asing / Baru | - | Tidak berubah | Nilai mentah | Tidak berubah | Tidak ada aksi | `200 OK` | Toleransi status masa depan Midtrans. |

---

## 9. Database Migration Preflight

Pemeriksaan komparatif langsung terhadap file migrasi `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql` dan struktur riil database `beautyland`:

### 9.1. Tinjauan Kode SQL Migrasi Aktual
```sql
-- Operasi 1: Penambahan kolom agregat reservasi pada produk
ALTER TABLE `produk`
  ADD COLUMN `stok_direservasi` INT NOT NULL DEFAULT 0 AFTER `stok`;

-- Operasi 2: Penambahan kolom idempotensi dan snapshot pada transaksi
ALTER TABLE `transaksi`
  ADD COLUMN `checkout_idempotency_key` VARCHAR(64) DEFAULT NULL AFTER `stok_dikurangi`,
  ADD COLUMN `snap_status` VARCHAR(30) DEFAULT NULL AFTER `checkout_idempotency_key`,
  ADD COLUMN `snap_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `snap_status`;

-- Operasi 3: Pembuatan indeks unik dan indeks status
ALTER TABLE `transaksi`
  ADD UNIQUE INDEX `idx_checkout_idempotency_key` (`checkout_idempotency_key`),
  ADD INDEX `idx_snap_status` (`snap_status`);

-- Operasi 4: Pembuatan tabel reservasi stok terpisah
CREATE TABLE IF NOT EXISTS `reservasi_stok` (
  `id_reservasi` INT NOT NULL AUTO_INCREMENT,
  `id_transaksi` INT NOT NULL,
  `id_produk` INT NOT NULL,
  `jumlah` INT NOT NULL,
  `status_reservasi` ENUM('Aktif', 'Dikonversi', 'Dilepas') NOT NULL DEFAULT 'Aktif',
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_reservasi`),
  KEY `idx_res_transaksi` (`id_transaksi`),
  KEY `idx_res_produk` (`id_produk`),
  KEY `idx_res_status` (`status_reservasi`),
  KEY `idx_res_expires` (`expires_at`),
  UNIQUE KEY `uq_transaksi_produk` (`id_transaksi`, `id_produk`),
  CONSTRAINT `fk_res_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_res_produk` FOREIGN KEY (`id_produk`) REFERENCES `produk` (`id_produk`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

### 9.2. Pemeriksaan Read-Only Database Aktual
Berdasarkan kueri `SHOW COLUMNS` dan `SHOW TABLES` pada kontainer MariaDB:
- `produk.stok` bertipe `int(11)`. Kolom `stok_direservasi` **belum ada**.
- `transaksi.stok_dikurangi` bertipe `tinyint(1)`. Kolom `checkout_idempotency_key`, `snap_status`, dan `snap_expires_at` **belum ada**.
- Indeks `idx_checkout_idempotency_key` dan `idx_snap_status` **belum ada**.
- Tabel `reservasi_stok` **belum ada**.
- Seluruh tipe data parent (`id_transaksi` dan `id_produk`) adalah `int(11) NOT NULL PRIMARY KEY`, sepenuhnya cocok dengan definisi foreign key child `INT NOT NULL`.
- Charset dan collation induk `utf8mb4_0900_ai_ci` didukung penuh pada MariaDB 12.2.

### 9.3. Analisis Idempotensi dan Risiko Non-Transactional DDL
1. **Tidak Dapat Dijalankan Ulang Secara Aman (Not Re-runnable):**
   Perintah `ALTER TABLE ... ADD COLUMN` tidak memiliki klausa conditional `IF NOT EXISTS` portabel. Jika migrasi dieksekusi untuk kedua kalinya, eksekusi akan gagal seketika pada baris pertama dengan `Error 1060 (Duplicate column name 'stok_direservasi')`.
2. **Implicit Commit DDL:**
   Setiap baris `ALTER TABLE` memicu commit otomatis di MariaDB. Jika eksekusi terputus di tengah jalan (misal setelah Operasi 2 berhasil namun sebelum Operasi 3), perubahan Operasi 1 dan 2 **tidak akan ter-rollback**.
3. **Langkah Pemulihan Jika Terhenti di Tengah Jalan:**
   Jika terjadi kegagalan parsial:
   - Administrator wajib memeriksa kolom mana yang sudah terbentuk via `SHOW COLUMNS FROM transaksi` dan `SHOW COLUMNS FROM produk`.
   - Jalankan perintah *drop column* manual untuk mengembalikan database ke kondisi bersih:
     ```sql
     ALTER TABLE produk DROP COLUMN IF EXISTS stok_direservasi;
     ALTER TABLE transaksi DROP COLUMN IF EXISTS snap_expires_at, DROP COLUMN IF EXISTS snap_status, DROP COLUMN IF EXISTS checkout_idempotency_key;
     DROP TABLE IF EXISTS reservasi_stok;
     ```
   - Setelah bersih, jalankan kembali skrip migrasi secara utuh dari awal.

---

## 10. Rekomendasi Urutan Implementasi

Pelaksanaan Fase 3.2 disarankan mengikuti urutan terstruktur berikut:

```text
[Langkah 1: Prasyarat Database]
  └── Eksekusi migrasi Fase 3.1.1 (20261126_add_stock_reservations_and_checkout_idempotency.sql).
  └── Verifikasi keberadaan tabel reservasi_stok dan kolom stok_direservasi di DB aktif.

[Langkah 2: Konstruksi Endpoint midtrans_notification.php]
  ├── Inisialisasi konfigurasi Midtrans via includes/midtrans.php & includes/conn.php.
  ├── Pembacaan stream php://input dan parsing JSON murni.
  └── Penegakan keamanan signature SHA-512 dengan hash_equals().

[Langkah 3: Integrasi Data & Transaksi Atomik]
  ├── Penguncian baris transaksi via SELECT ... FOR UPDATE (berdasarkan midtrans_order_id).
  ├── Validasi keamanan nominal: regex format dan perbandingan integer deterministik (Bab 3).
  └── Penyimpanan notification_payload (JSON snapshot).

[Langkah 4: Penegakan State Machine & Aksi Stok]
  ├── Settlement & Capture Accept:
  │     ├── Jika status == 'Menunggu Pembayaran' -> convert_stock_reservation() + stok_dikurangi = 1 + Lunas.
  │     ├── Jika status == 'Dibatalkan' -> LATE SETTLEMENT RULE (Bab 5). JANGAN potong stok, snap_status tetap Berhasil.
  │     └── Jika status == 'Lunas' -> Idempotent No-Op.
  ├── Capture Challenge & Fail-Safe Fraud Status:
  │     └── Jika fraud_status == 'challenge' atau kosong/tidak dikenal -> Pertahankan Menunggu Pembayaran (Bab 4).
  ├── Expire, Cancel, Deny:
  │     └── Jika status == 'Menunggu Pembayaran' -> release_stock_reservation() + Dibatalkan.
  └── Resolusi otomatis: snap_status tetap 'Berhasil' murni.

[Langkah 5: Penanganan Kasus Refund]
  ├── Event full refund -> status_pembayaran = 'Refund', snap_status TETAP 'Berhasil', stok tidak restock otomatis (Bab 6).
  ├── Event partial refund -> status_pembayaran tetap 'Lunas', midtrans_status = 'partial_refund'.
  └── Event refund pasca-late settlement -> status_pembayaran tetap 'Dibatalkan', midtrans_status = 'refund' (Bab 5.3).

[Langkah 6: Proteksi Backend Pengiriman]
  └── Terapkan proteksi validasi backend pada admin/pengiriman.php untuk status Dibatalkan & Refund (Bab 6.2).

[Langkah 7: Pengujian Simulasi Webhook (Mock Payload)]
  ├── Uji notifikasi settlement berhasil -> status 'Lunas' & stok berkurang.
  ├── Uji notifikasi duplikat -> stok tidak terpotong ganda.
  ├── Uji capture dengan fraud_status kosong -> diposisikan sebagai challenge tanpa potong stok.
  ├── Uji late settlement pada pesanan dibatalkan -> status tetap Dibatalkan, stok aman.
  ├── Uji manipulasi nominal (misal 150000.49) -> HTTP 400 ditolak.
  └── Uji signature palsu -> HTTP 401 ditolak.
```

---

## Kesimpulan Kesiapan

### 1. Status Kesiapan Desain Webhook
**DESAIN TELAH LENGKAP, KONSISTEN, DAN TERVERIFIKASI SECARA KONSEPTUAL.** Seluruh koreksi semantik `snap_status`, pengetatan FDS capture, validasi nominal deterministik, rantai skenario late settlement & refund, serta proteksi modul pengiriman telah terintegrasi tanpa inkonsistensi logika.

### 2. Status Kesiapan Migrasi Database
**TERVERIFIKASI AMAN UNTUK DIEKSEKUSI, NAMUN BELUM DIJALANKAN (AKTIF BLOCKER).**
- Struktur skema database MariaDB aktif saat ini telah diverifikasi langsung: tidak ada benturan nama kolom atau indeks.
- Namun, karena perintah DDL belum dijalankan ke database aktif, kode implementasi Fase 3.2 **belum dapat dijalankan secara runtime** sebelum migrasi dieksekusi oleh administrator database.

Setelah migrasi Fase 3.1.1 dijalankan dan diverifikasi, implementasi `midtrans_notification.php` dapat segera dikerjakan.
