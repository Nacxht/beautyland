# Audit Arsitektur Checkout untuk Integrasi Midtrans Snap

**Proyek:** Beautyland (MAARS Beauty)  
**Tanggal:** 25 November 2026  
**Tujuan:** Menentukan perubahan minimum agar alur pembayaran manual dapat diintegrasikan dengan Midtrans Snap  

---

## 1. Ringkasan Arsitektur Saat Ini

### 1.1 Alur Checkout (End-to-End)

```
[Pelanggan]
    |
    v
check_out.php          POST dari keranjang atau beli langsung
    |                  Menampilkan daftar produk, harga, alamat
    |                  Total dihitung client-side (JavaScript)
    |                  Hidden inputs: id_produk[], jumlah[], harga_satuan[], total_harga
    v
proses_checkout.php    Menerima POST, cek stok (server-side)
    |                  Harga & total TIDAK divalidasi ulang dari DB
    |                  Menyimpan data ke $_SESSION['pending_order']
    |                  Redirect ke pembayaran.php
    v
pembayaran.php         Menampilkan total & kode pembayaran
    |                  Form upload bukti transfer (file gambar)
    |                  Form batal transaksi
    v
proses_pembayaran.php  Menerima file upload bukti
    |                  Cek stok ulang (prepared statements)
    |                  INSERT transaksi (status: 'Menunggu Verifikasi')
    |                  INSERT pengiriman (status: 'Belum Diproses')
    |                  INSERT detail_transaksi per item
    |                  UPDATE stok produk (stok - qty)
    |                  DELETE item dari keranjang
    v
pesanan_user.php       Pelanggan melihat riwayat pesanan
    |
    v
[Admin]
pesanan.php            Admin melihat semua transaksi
    |                  Tombol "Verifikasi" (GET) -> UPDATE status_pembayaran='Lunas'
    v
pengiriman.php         Admin mengatur jasa kirim, resi, status pengiriman
    v
laporan.php            Laporan keuangan (hanya transaksi 'Lunas')
```

### 1.2 Struktur Tabel Database

Sumber: `beauty_care (1).sql`

#### `transaksi`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id_transaksi` | int PK AI | |
| `id_pelanggan` | int FK -> pelanggan | |
| `tgl_transaksi` | timestamp DEFAULT CURRENT_TIMESTAMP | |
| `total_harga` | int | |
| `metode_pembayaran` | varchar(50) | Saat ini: 'Transfer Bank' |
| `status_pembayaran` | varchar(50) | 'Menunggu Verifikasi', 'Lunas', 'Dibatalkan' |
| `kode_pembayaran` | varchar(30) | Format: 'MB-XXXXXXXX' (hex) |
| `bukti_pembayaran` | varchar(255) | Nama file gambar di assets/bukti/ |

#### `detail_transaksi`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id_detail` | int PK AI | |
| `id_transaksi` | int FK -> transaksi | |
| `id_produk` | int FK -> produk | |
| `jumlah` | int | |
| `harga_satuan` | int | |
| `subtotal` | int | |

#### `pengiriman`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id_pengiriman` | int PK AI | |
| `id_transaksi` | int FK -> transaksi | |
| `jasa_kirim` | varchar(50) | JNE, J&T, SiCepat |
| `nomor_resi` | varchar(100) | |
| `alamat_tujuan` | text | |
| `status_pengiriman` | varchar(50) | 'Belum Diproses', 'Diproses', 'Dikirim', 'Selesai' |
| `tanggal_kirim` | date | |

#### `produk`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id_produk` | int PK AI | |
| `nama_produk` | varchar(100) | |
| `deskripsi` | text | |
| `harga` | int | |
| `stok` | int | |
| `gambar` | varchar(255) | |

#### `pelanggan`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id_pelanggan` | int PK AI | |
| `nama` | varchar(100) | |
| `email` | varchar(100) | |
| `no_hp` | varchar(15) | |
| `alamat` | text | |
| `tanggal_daftar` | date | |
| `foto` | varchar(255) | |

#### `keranjang`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id_keranjang` | int PK AI | |
| `id_pelanggan` | int | |
| `id_produk` | int | |
| `jumlah` | int DEFAULT 1 | |

### 1.3 Relasi Antar Tabel

```
users.id_pelanggan -> pelanggan.id_pelanggan
transaksi.id_pelanggan -> pelanggan.id_pelanggan
detail_transaksi.id_transaksi -> transaksi.id_transaksi
detail_transaksi.id_produk -> produk.id_produk
pengiriman.id_transaksi -> transaksi.id_transaksi
booking.id_pelanggan -> pelanggan.id_pelanggan
booking.id_jadwal -> jadwal.id_jadwal
```

### 1.4 Konfigurasi Midtrans yang Sudah Tersedia

- `.env.example` sudah menyediakan key `MIDTRANS_CLIENT_KEY`, `MIDTRANS_SERVER_KEY`, `MIDTRANS_IS_PRODUCTION` (`.env.example:10-13`)
- `includes/config.php` sudah mem-parse `.env` ke `$_ENV` (`config.php:9-18`)
- `includes/midtrans.php` sudah mengonfigurasi Midtrans PHP SDK (`midtrans.php:1-34`):
  - Memuat Composer autoloader (`midtrans.php:5-11`)
  - Mengonfigurasi `\Midtrans\Config::$serverKey`, `$clientKey`, `$isProduction`, `$isSanitized`, `$is3ds`
- `composer.json` sudah mendefinisikan `midtrans/midtrans-php: ^2.6`
- Direktori `vendor/midtrans/` sudah ada
- **Namun `includes/midtrans.php` belum di-require oleh file manapun** -- SDK belum digunakan

### 1.5 Peta Status Pembayaran

| Nilai | Ditulis Oleh | File:Baris |
|---|---|---|
| `'Menunggu Verifikasi'` | Pelanggan checkout baru | `proses_pembayaran.php:71-73` |
| `'Menunggu Verifikasi'` | Re-upload bukti | `proses_pembayaran.php:105-107` |
| `'Lunas'` | Admin verifikasi manual | `admin/pesanan.php:19-23` |
| `'Dibatalkan'` | Pelanggan batal | `proses_pembayaran.php:11` |

### 1.6 Peta Status Pengiriman

| Nilai | Ditulis Oleh | File:Baris |
|---|---|---|
| `'Belum Diproses'` | Pembuatan transaksi baru | `proses_pembayaran.php:77-79` |
| `'Diproses'` | Admin update | `admin/pengiriman.php:19-26` |
| `'Dikirim'` | Admin update | `admin/pengiriman.php:19-26` |
| `'Selesai'` | Admin update (final) | `admin/pengiriman.php:19-26` |

### 1.7 Dependensi Admin terhadap Nilai Status

| File Admin | Nilai yang Dibaca | Baris | Dampak |
|---|---|---|---|
| `dashboard_admin.php` | `'Lunas'` | 16 | Total penjualan |
| `laporan.php` | `'Lunas'` | 46, 118 | Pendapatan & laporan keuangan |
| `pesanan.php` | `'lunas'`, `'batal'`, `'dibatalkan'` (lowercase compare) | 367-375 | Badge warna & tombol verifikasi |
| `pesanan.php` | Cek `!= 'lunas'` | 444 | Menampilkan tombol Verifikasi |
| `pengiriman.php` | `'Selesai'` | 18, 83 | Proteksi update & disable form |
| `pelanggan/dashboard.php` | `'Selesai'` (pengiriman) | 31, 39 | Statistik pesanan selesai |
| `pelanggan/pesanan_user.php` | `'lunas'`, `'batal'` (strpos) | 90-106 | Badge warna |

---

## 2. Masalah dan Risiko yang Ditemukan

### 2.1 KRITIS: Manipulasi Harga dari Client-Side

**Lokasi:** `pelanggan/check_out.php:477,547` -> `pelanggan/proses_checkout.php:11,37`

Harga satuan (`harga_satuan[]`) dan total (`total_harga`) dikirim sebagai hidden input dari form HTML. Total dihitung di JavaScript client-side (`check_out.php:393-406`). Backend (`proses_checkout.php:11,37`) langsung mempercayai nilai POST ini tanpa memvalidasi ulang dari database.

**Dampak:** Penyerang dapat memodifikasi hidden field via browser DevTools, mengirim `total_harga=0` dan `harga_satuan[]=0`, menghasilkan pembelian gratis. Stok tetap berkurang karena validasi stok berjalan terpisah dari validasi harga.

**Propagasi:** Harga tainted ini masuk ke `$_SESSION['pending_order']` (`proses_checkout.php:47-54`) dan akhirnya di-INSERT ke tabel `transaksi.total_harga` dan `detail_transaksi.harga_satuan` (`proses_pembayaran.php:52,89`).

### 2.2 KRITIS: SQL Injection di Banyak File

| Prioritas | File | Baris | Variabel |
|---|---|---|---|
| KRITIS | `admin/pesanan.php` | 17, 19-23 | `$_GET['verifikasi']` |
| KRITIS | `admin/pesanan.php` | 46-49 | `$_GET['search']` |
| KRITIS | `admin/pengiriman.php` | 9-12, 15, 19-26 | Semua `$_POST` |
| KRITIS | `admin/hapus_produk.php` | 14, 17, 28 | `$_GET['id']` |
| KRITIS | `admin/edit_produk.php` | 13, 16, 27, 56-62 | `$_GET['id']`, `$_POST` |
| TINGGI | `pelanggan/check_out.php` | 33-37, 60-74 | `$_POST['id_produk']` |
| TINGGI | `pelanggan/proses_checkout.php` | 21 | `$_POST['id_produk']` |
| TINGGI | `pelanggan/keranjang.php` | 73, 75-78 | `$_GET` params |
| TINGGI | `pelanggan/produk_user.php` | 15-20 | `$_GET['search']` |

### 2.3 KRITIS: Race Condition pada Stok

Pengecekan stok (`proses_checkout.php:21-28`) dan pengurangan stok (`proses_pembayaran.php:95-96`) tidak berada dalam satu transaksi database (MySQL transaction). Dua pelanggan yang melakukan checkout produk bersamaan dapat lolos pengecekan stok dan menyebabkan stok negatif (overselling).

Selain itu, ada gap waktu antara pengecekan stok pertama di `proses_checkout.php` (saat redirect ke halaman pembayaran) dan pengecekan stok kedua di `proses_pembayaran.php` (saat upload bukti). Stok bisa berubah di antara kedua titik tersebut.

### 2.4 TINGGI: Tidak Ada Auth Guard di `admin/pengiriman.php`

**Lokasi:** `admin/pengiriman.php:1-3`

File ini hanya memanggil `session_start()` dan `require conn.php` tanpa pengecekan `$_SESSION['role'] === 'admin'`. Pelanggan yang sudah login bisa mengakses dan mengubah data pengiriman.

### 2.5 TINGGI: Verifikasi Pembayaran via GET Request

**Lokasi:** `admin/pesanan.php:15-27`

Verifikasi pembayaran dilakukan via GET request (`?verifikasi=<id>`), rentan CSRF. Admin yang membuka link berbahaya bisa secara tidak sengaja memverifikasi transaksi.

### 2.6 SEDANG: Duplikasi Transaksi

Tidak ada mekanisme untuk mencegah duplikasi. Jika pelanggan melakukan checkout, kembali ke halaman sebelumnya, lalu checkout lagi, `$_SESSION['pending_order']` akan di-overwrite -- tetapi jika bukti sudah diupload dan session belum di-unset, transaksi ganda bisa terjadi. Kode pembayaran `MB-XXXXXXXX` menggunakan `md5(rand())` yang secara teoritis bisa collide.

### 2.7 SEDANG: Mekanisme Upload Bukti Transfer

**File yang terdampak:**
- `pelanggan/pembayaran.php:115-133` - Form upload
- `pelanggan/proses_pembayaran.php:22-120` - Proses upload
- `admin/pesanan.php:415-430` - Tampilkan thumbnail bukti
- Kolom `transaksi.bukti_pembayaran` - Menyimpan nama file

Mekanisme ini perlu **dipertahankan** sebagai fallback sampai integrasi Midtrans stabil. Penghapusan prematur akan memutus alur admin yang sudah berjalan.

### 2.8 RENDAH: Password MD5 Tanpa Salt

**Lokasi:** `login.php:12`

Tidak langsung berhubungan dengan integrasi Midtrans, namun merupakan risiko keamanan umum.

---

## 3. Rekomendasi Perubahan Database

### 3.1 Kolom Baru di Tabel `transaksi`

```sql
-- JANGAN DIJALANKAN -- Hanya proposal

ALTER TABLE transaksi
  ADD COLUMN midtrans_order_id VARCHAR(50) DEFAULT NULL AFTER kode_pembayaran,
  ADD COLUMN midtrans_transaction_id VARCHAR(255) DEFAULT NULL AFTER midtrans_order_id,
  ADD COLUMN snap_token VARCHAR(255) DEFAULT NULL AFTER midtrans_transaction_id,
  ADD COLUMN midtrans_payment_type VARCHAR(50) DEFAULT NULL AFTER snap_token,
  ADD COLUMN midtrans_status VARCHAR(50) DEFAULT NULL AFTER midtrans_payment_type,
  ADD COLUMN notification_payload JSON DEFAULT NULL AFTER midtrans_status,
  ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER notification_payload;

ALTER TABLE transaksi
  ADD UNIQUE INDEX idx_midtrans_order_id (midtrans_order_id);
```

**Penjelasan kolom:**

| Kolom | Tujuan |
|---|---|
| `midtrans_order_id` | Order ID unik yang dikirim ke Midtrans (format: `BL-{id_transaksi}-{timestamp}`). UNIQUE index untuk mencegah duplikasi. |
| `midtrans_transaction_id` | Transaction ID dari Midtrans (untuk referensi di dashboard Midtrans) |
| `snap_token` | Token Snap yang dihasilkan. Bisa di-reuse jika pelanggan belum membayar dan token belum expire. |
| `midtrans_payment_type` | Metode pembayaran aktual yang dipilih pelanggan di Snap (bank_transfer, gopay, dll) |
| `midtrans_status` | Status dari Midtrans (settlement, pending, deny, cancel, expire, refund) |
| `notification_payload` | Payload JSON lengkap dari HTTP Notification, untuk audit trail |
| `updated_at` | Timestamp kapan status terakhir diperbarui |

### 3.2 Kolom Opsional untuk Idempotency

```sql
-- JANGAN DIJALANKAN -- Hanya proposal

ALTER TABLE transaksi
  ADD COLUMN stok_dikurangi TINYINT(1) DEFAULT 0 AFTER updated_at;
```

Flag `stok_dikurangi` mencegah pengurangan stok ganda jika HTTP Notification diterima lebih dari satu kali.

### 3.3 Tidak Perlu Mengubah Tabel Lain

Tabel `detail_transaksi`, `pengiriman`, `pelanggan`, `produk`, dan `keranjang` tidak memerlukan perubahan skema untuk integrasi Midtrans.

---

## 4. Daftar File yang Perlu Dibuat, Diubah, atau Dipertahankan

### 4.1 File Baru

| File | Fungsi |
|---|---|
| `pelanggan/create_snap_token.php` | Endpoint AJAX: validasi data, buat transaksi di DB, request Snap Token via SDK, return JSON `{snap_token, order_id}` |
| `midtrans_notification.php` (di root) | Endpoint publik (tanpa session): menerima HTTP Notification dari Midtrans, verifikasi signature, update status pembayaran secara idempotent |
| `pelanggan/pembayaran_midtrans.php` | Halaman baru yang memuat Snap.js dan menampilkan popup pembayaran Midtrans |

### 4.2 File yang Perlu Diubah

| File | Perubahan | Alasan |
|---|---|---|
| `pelanggan/check_out.php` | Hapus hidden input `harga_satuan[]` dan `total_harga` yang di-generate JS. Ubah form action ke `create_snap_token.php` atau ke `pembayaran_midtrans.php`. | Mencegah manipulasi harga |
| `pelanggan/proses_checkout.php` | Hitung ulang harga dan total dari database, bukan dari `$_POST`. Gunakan prepared statements. | Menutup celah manipulasi harga dan SQL injection |
| `admin/pesanan.php` | Tambahkan penanganan status baru dari Midtrans. Kolom `status_pembayaran` tetap menggunakan nilai yang sama ('Lunas', 'Menunggu Verifikasi', 'Dibatalkan') agar kompatibel. Tambahkan tampilan `midtrans_payment_type`. | Kompatibilitas dengan alur baru |
| `admin/pengiriman.php` | Tambahkan auth guard admin. | Keamanan (tidak terkait langsung Midtrans tapi kritis) |
| `includes/midtrans.php` | Sudah lengkap, tidak perlu diubah. | Sudah siap pakai |

### 4.3 File yang Dipertahankan Tanpa Perubahan

| File | Alasan |
|---|---|
| `includes/config.php` | Sudah benar mem-parse `.env` |
| `includes/conn.php` | Sudah benar membuat koneksi |
| `pelanggan/pembayaran.php` | Dipertahankan sebagai fallback pembayaran manual (upload bukti). Bisa diakses dari `pesanan_user.php` untuk transaksi lama. |
| `pelanggan/proses_pembayaran.php` | Dipertahankan untuk proses upload bukti manual dan pembatalan transaksi |
| `admin/laporan.php` | Tidak perlu diubah, sudah filter berdasarkan `status_pembayaran='Lunas'` |
| `admin/dashboard_admin.php` | Tidak perlu diubah, sudah filter berdasarkan `status_pembayaran='Lunas'` |
| `pelanggan/pesanan_user.php` | Perlu sedikit penyesuaian untuk menampilkan link "Bayar Ulang" ke `pembayaran_midtrans.php` jika status masih pending |
| `pelanggan/keranjang.php` | Tidak perlu diubah |

---

## 5. Rancangan Alur Backend dan Frontend

### 5.1 Alur Baru dengan Midtrans Snap

```
[Pelanggan]
    |
    v
(1) check_out.php           Tampilkan produk dari DB (harga dari DB, bukan POST)
    |                        Form submit ke create_snap_token.php
    v
(2) create_snap_token.php    [Backend - New File]
    |   a. Validasi session pelanggan
    |   b. Ambil harga setiap produk dari tabel produk (bukan dari POST)
    |   c. Validasi stok (SELECT ... FOR UPDATE dalam transaction)
    |   d. Hitung total server-side
    |   e. INSERT transaksi (status: 'Menunggu Pembayaran')
    |   f. INSERT detail_transaksi
    |   g. INSERT pengiriman
    |   h. Generate midtrans_order_id: "BL-{id_transaksi}-{time()}"
    |   i. UPDATE transaksi SET midtrans_order_id = ...
    |   j. Request Snap Token via \Midtrans\Snap::createTransaction()
    |   k. UPDATE transaksi SET snap_token = ...
    |   l. Hapus item dari keranjang
    |   m. Return JSON: {snap_token, order_id, redirect_url}
    |   CATATAN: Stok BELUM dikurangi di tahap ini
    v
(3) pembayaran_midtrans.php  [Frontend - New File]
    |   a. Muat snap.js dari Midtrans CDN (sandbox/production)
    |   b. Panggil snap.pay(snap_token, callbacks)
    |   c. onSuccess/onPending: redirect ke pesanan_user.php
    |   d. onError: tampilkan pesan error
    |   e. onClose: tampilkan opsi "Bayar Nanti"
    |   CATATAN: Callback browser BUKAN bukti pembayaran berhasil
    v
(4) midtrans_notification.php  [Backend - New File, Public Endpoint]
    |   a. Terima POST dari Midtrans
    |   b. Decode JSON body
    |   c. Verifikasi signature_key:
    |      SHA512(order_id + status_code + gross_amount + server_key)
    |   d. Cari transaksi berdasarkan midtrans_order_id
    |   e. Mapping status (idempotent):
    |      - settlement -> status_pembayaran = 'Lunas', kurangi stok
    |      - capture (credit card) -> status_pembayaran = 'Lunas', kurangi stok
    |      - pending -> status_pembayaran = 'Menunggu Pembayaran'
    |      - deny/cancel/expire -> status_pembayaran = 'Dibatalkan'
    |   f. Update kolom midtrans_status, midtrans_transaction_id,
    |      midtrans_payment_type, notification_payload
    |   g. Return HTTP 200
    v
(5) [Admin Panel - Tidak berubah signifikan]
    pesanan.php              Tampilkan status_pembayaran seperti biasa
    pengiriman.php           Proses pengiriman tetap manual
    laporan.php              Tetap filter 'Lunas'
```

### 5.2 Diagram Sequence Detail

```
Pelanggan          Frontend           Backend             Midtrans         Database
   |                  |                  |                   |                |
   |--checkout------->|                  |                   |                |
   |                  |--POST items----->|                   |                |
   |                  |                  |--validate-------->|                |
   |                  |                  |  session, stok    |                |
   |                  |                  |--INSERT trx------>|                |
   |                  |                  |--Snap::create---->|                |
   |                  |                  |<--snap_token------|                |
   |                  |<--JSON response--|                   |                |
   |                  |                  |                   |                |
   |                  |--snap.pay()----->|                   |                |
   |                  |                  |                   |                |
   |<---payment UI----|    (Midtrans popup di browser)       |                |
   |---pilih metode-->|                  |                   |                |
   |---bayar--------->|                  |                   |                |
   |                  |                  |                   |                |
   |                  |<--callback-------|  (hanya UI)       |                |
   |                  |--redirect------->|  pesanan_user     |                |
   |                  |                  |                   |                |
   |                  |                  |<--HTTP notif------|                |
   |                  |                  |--verify sig------>|                |
   |                  |                  |--UPDATE status--->|                |
   |                  |                  |--UPDATE stok----->|                |
   |                  |                  |--200 OK---------->|                |
```

### 5.3 Payload Snap Token Request

```php
$params = [
    'transaction_details' => [
        'order_id'     => $midtrans_order_id,  // "BL-{id_transaksi}-{time()}"
        'gross_amount' => $total_harga_dari_db, // INT, hitung server-side
    ],
    'item_details' => [
        // Per item dari detail_transaksi (harga dari DB)
        [
            'id'       => $id_produk,
            'price'    => $harga_dari_db,
            'quantity' => $jumlah,
            'name'     => substr($nama_produk, 0, 50),
        ],
        // ...
    ],
    'customer_details' => [
        'first_name' => $pelanggan['nama'],
        'email'      => $pelanggan['email'],
        'phone'      => $pelanggan['no_hp'],
        'shipping_address' => [
            'address' => $pelanggan['alamat'],
        ],
    ],
];

$snapToken = \Midtrans\Snap::createTransaction($params)->token;
```

---

## 6. Strategi Verifikasi HTTP Notification

### 6.1 Endpoint

File: `midtrans_notification.php` (di root project, tanpa proteksi session)

URL yang didaftarkan di Midtrans Dashboard:  
`{BASE_URL}/midtrans_notification.php`

### 6.2 Verifikasi Signature

```
signature_key = SHA512(order_id + status_code + gross_amount + server_key)
```

Backend harus menghitung ulang signature dari data yang diterima dan membandingkannya dengan `signature_key` di payload notifikasi. Jika tidak cocok, tolak dengan HTTP 403.

### 6.3 Verifikasi Tambahan (Defense-in-Depth)

1. **Verifikasi amount:** Bandingkan `gross_amount` dari notifikasi dengan `total_harga` di database. Jika berbeda, log sebagai anomali dan tolak.
2. **Verifikasi order_id:** Pastikan `order_id` ada di database dan belum dalam status final (`Lunas` atau `Dibatalkan`).
3. **Opsional - API Status Check:** Setelah menerima notifikasi, panggil `\Midtrans\Transaction::status($order_id)` untuk double-check ke server Midtrans.

### 6.4 Mapping Status Midtrans ke Status Aplikasi

| `transaction_status` Midtrans | `fraud_status` | `status_pembayaran` Aplikasi | Aksi Stok |
|---|---|---|---|
| `capture` | `accept` | `Lunas` | Kurangi stok |
| `settlement` | - | `Lunas` | Kurangi stok |
| `pending` | - | `Menunggu Pembayaran` | Tidak ada |
| `deny` | - | `Dibatalkan` | Tidak ada |
| `cancel` | - | `Dibatalkan` | Kembalikan stok (jika sudah dikurangi) |
| `expire` | - | `Dibatalkan` | Kembalikan stok (jika sudah dikurangi) |
| `refund` | - | `Dibatalkan` | Kembalikan stok (jika sudah dikurangi) |

### 6.5 Keamanan Endpoint

- Tidak memerlukan session (Midtrans mengirim dari server mereka)
- Harus memverifikasi signature sebelum melakukan apapun
- Harus mengembalikan HTTP 200 meskipun order_id tidak ditemukan (agar Midtrans tidak retry terus)
- Log semua notifikasi untuk audit trail (simpan di `notification_payload`)

---

## 7. Strategi Pengelolaan Stok dan Transaksi Duplikat

### 7.1 Stok: Kapan Dikurangi?

**Rekomendasi: Kurangi stok hanya setelah pembayaran berhasil (di HTTP Notification handler).**

Alasan:
- Jika stok dikurangi saat checkout (sebelum bayar), pelanggan yang tidak membayar akan "menahan" stok. Memerlukan mekanisme pengembalian stok otomatis yang kompleks.
- Jika stok dikurangi saat pembayaran berhasil, risiko overselling ada tetapi minimal untuk volume transaksi kecil (akademik).

### 7.2 Pencegahan Overselling

```php
// Di dalam midtrans_notification.php, gunakan MySQL transaction + row lock

$conn->begin_transaction();

try {
    // Lock baris stok
    $stmt = $conn->prepare("SELECT stok FROM produk WHERE id_produk = ? FOR UPDATE");
    // ... untuk setiap item di detail_transaksi

    // Validasi stok cukup
    // Jika tidak cukup: log warning, tetap set Lunas (uang sudah masuk),
    // tapi flag untuk admin review

    // Kurangi stok
    $stmt = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?");
    // Check affected_rows == 1

    // Update status transaksi
    // ...

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    // Log error
}
```

### 7.3 Flag `stok_dikurangi`

Kolom `stok_dikurangi` (TINYINT, default 0) di tabel `transaksi` memastikan idempotency:

```php
// Sebelum kurangi stok
if ($transaksi['stok_dikurangi'] == 0) {
    // Kurangi stok
    // ...
    // Set flag
    $stmt = $conn->prepare("UPDATE transaksi SET stok_dikurangi = 1 WHERE id_transaksi = ?");
}
// Jika stok_dikurangi sudah 1, skip pengurangan stok (notifikasi duplikat)
```

### 7.4 Pencegahan Transaksi Duplikat

1. **Unique `midtrans_order_id`:** Index UNIQUE di kolom ini mencegah dua transaksi dengan order ID yang sama.
2. **Format order ID:** `BL-{id_transaksi}-{time()}` -- karena `id_transaksi` adalah auto-increment, dua checkout berbeda akan selalu menghasilkan order ID berbeda.
3. **Tombol checkout disable:** Setelah form submit, disable tombol via JavaScript untuk mencegah double-click.
4. **Session cleanup:** Setelah transaksi dibuat di DB, hapus `$_SESSION['pending_order']` agar tidak bisa diproses ulang.

### 7.5 Penanganan Snap Token Expired

Snap Token berlaku 24 jam. Jika pelanggan belum membayar dan token expired:
- Halaman `pesanan_user.php` menampilkan tombol "Bayar Ulang"
- Backend memeriksa apakah `snap_token` masih valid, jika tidak, generate yang baru dengan order ID yang sama
- Transaksi di DB tetap satu record (status masih 'Menunggu Pembayaran')

---

## 8. Acceptance Criteria untuk Pengujian

### 8.1 Alur Sukses (Happy Path)

- [ ] AC-01: Pelanggan checkout dari keranjang -> transaksi tersimpan di DB dengan status 'Menunggu Pembayaran' dan `midtrans_order_id` terisi
- [ ] AC-02: Snap Token berhasil di-generate dan dikembalikan ke frontend
- [ ] AC-03: Popup Midtrans Snap muncul di browser pelanggan
- [ ] AC-04: Setelah pembayaran berhasil di Midtrans (sandbox), HTTP Notification diterima
- [ ] AC-05: Signature key terverifikasi benar
- [ ] AC-06: Status transaksi berubah menjadi 'Lunas' di database
- [ ] AC-07: Stok produk berkurang sesuai jumlah pembelian
- [ ] AC-08: Item yang dibeli dihapus dari keranjang
- [ ] AC-09: Pelanggan melihat status 'Lunas' di halaman pesanan
- [ ] AC-10: Admin melihat transaksi dengan status 'Lunas' di halaman pesanan
- [ ] AC-11: Dashboard admin menghitung total penjualan dengan benar
- [ ] AC-12: Laporan keuangan memasukkan transaksi Midtrans yang Lunas

### 8.2 Validasi Harga

- [ ] AC-13: Harga yang dikirim ke Midtrans selalu diambil dari tabel `produk`, bukan dari input pelanggan
- [ ] AC-14: Modifikasi hidden input `total_harga` via DevTools TIDAK mempengaruhi harga di Midtrans
- [ ] AC-15: `gross_amount` di Snap Token sama dengan SUM dari `item_details.price * quantity`

### 8.3 Validasi Stok

- [ ] AC-16: Produk dengan stok 0 tidak bisa di-checkout
- [ ] AC-17: Checkout dengan jumlah melebihi stok ditolak
- [ ] AC-18: Dua pelanggan checkout produk terakhir secara bersamaan: hanya satu yang berhasil mendapatkan stok (tidak ada stok negatif)
- [ ] AC-19: Stok dikurangi hanya setelah HTTP Notification `settlement`/`capture` (bukan saat checkout)

### 8.4 Idempotency

- [ ] AC-20: HTTP Notification yang sama dikirim 2 kali: stok hanya dikurangi 1 kali
- [ ] AC-21: Transaksi yang sudah 'Lunas' tidak berubah jika menerima notifikasi 'pending'
- [ ] AC-22: Transaksi yang sudah 'Dibatalkan' tidak berubah jika menerima notifikasi 'pending'

### 8.5 Pembatalan dan Expire

- [ ] AC-23: Notifikasi `cancel` mengubah status menjadi 'Dibatalkan'
- [ ] AC-24: Notifikasi `expire` mengubah status menjadi 'Dibatalkan'
- [ ] AC-25: Stok yang sudah dikurangi dikembalikan jika terjadi cancel/expire (jika berlaku)

### 8.6 Keamanan

- [ ] AC-26: Endpoint `midtrans_notification.php` menolak request dengan signature tidak valid (HTTP 403)
- [ ] AC-27: Endpoint `midtrans_notification.php` tidak memerlukan session login
- [ ] AC-28: Endpoint `midtrans_notification.php` mengembalikan HTTP 200 untuk order_id yang tidak ditemukan (tidak leak info)
- [ ] AC-29: `create_snap_token.php` hanya bisa diakses oleh pelanggan yang sudah login
- [ ] AC-30: Server Key Midtrans tidak pernah dikirim ke frontend

### 8.7 Kompatibilitas dengan Alur Lama

- [ ] AC-31: Admin masih bisa melihat dan memverifikasi transaksi lama (Transfer Bank) di `admin/pesanan.php`
- [ ] AC-32: Transaksi lama dengan `bukti_pembayaran` masih menampilkan thumbnail di halaman admin
- [ ] AC-33: Laporan keuangan menggabungkan transaksi Midtrans dan Transfer Bank yang berstatus 'Lunas'

### 8.8 Frontend

- [ ] AC-34: Jika Snap Token gagal di-generate, tampilkan pesan error yang jelas
- [ ] AC-35: Jika pelanggan menutup popup Snap tanpa bayar, ada opsi untuk bayar nanti dari halaman pesanan
- [ ] AC-36: Browser callback (`onSuccess`) hanya redirect, tidak dianggap bukti pembayaran

---

## 9. Urutan Implementasi Bertahap

### Fase 1: Perbaikan Keamanan Dasar (Prasyarat)
**Risiko jika dilewati:** Manipulasi harga tetap bisa terjadi bahkan dengan Midtrans  
**Estimasi:** 1-2 hari

| # | Task | File | Risiko |
|---|---|---|---|
| 1.1 | Validasi ulang harga dari DB di `proses_checkout.php` (ganti `$_POST['harga_satuan']` dan `$_POST['total_harga']` dengan query ke tabel `produk`) | `proses_checkout.php` | Rendah - perubahan logika backend |
| 1.2 | Ganti string interpolation SQL di `proses_checkout.php` dengan prepared statements | `proses_checkout.php` | Rendah |
| 1.3 | Tambahkan auth guard di `admin/pengiriman.php` | `admin/pengiriman.php` | Rendah |
| 1.4 | Hapus hidden input `harga_satuan[]` dari `check_out.php` (harga akan di-fetch dari DB) | `check_out.php` | Rendah - UI tetap sama |

### Fase 2: Migrasi Database
**Risiko jika dilewati:** Tidak ada tempat menyimpan data Midtrans  
**Estimasi:** < 1 hari

| # | Task | Risiko |
|---|---|---|
| 2.1 | Jalankan ALTER TABLE untuk menambah kolom Midtrans di tabel `transaksi` | Rendah - hanya menambah kolom dengan DEFAULT NULL |
| 2.2 | Tambah kolom `stok_dikurangi` | Rendah |
| 2.3 | Update kolom `stok_dikurangi = 1` untuk semua transaksi existing yang berstatus 'Lunas' (karena stok sudah dikurangi) | Rendah - data historis |

### Fase 3: Backend Midtrans
**Risiko:** Jika Snap Token gagal dibuat, pelanggan tidak bisa bayar  
**Estimasi:** 2-3 hari

| # | Task | File Baru | Risiko |
|---|---|---|---|
| 3.1 | Buat `create_snap_token.php`: validasi session, hitung harga dari DB, INSERT transaksi, request Snap Token, return JSON | `pelanggan/create_snap_token.php` | Sedang - integrasi API external |
| 3.2 | Buat `midtrans_notification.php`: terima notifikasi, verifikasi signature, update status idempotent, kurangi stok | `midtrans_notification.php` | Tinggi - critical path, harus idempotent |
| 3.3 | Pindahkan logika pengurangan stok dari `proses_pembayaran.php` ke `midtrans_notification.php` (hanya untuk alur Midtrans) | - | Sedang - perubahan alur stok |

### Fase 4: Frontend Midtrans
**Risiko:** Jika JavaScript error, popup tidak muncul  
**Estimasi:** 1-2 hari

| # | Task | File Baru | Risiko |
|---|---|---|---|
| 4.1 | Buat `pembayaran_midtrans.php`: halaman yang memuat `snap.js` dan memanggil `snap.pay()` | `pelanggan/pembayaran_midtrans.php` | Rendah - UI baru |
| 4.2 | Update `check_out.php`: tambah opsi pembayaran Midtrans (bisa berupa form action baru atau JavaScript fetch ke `create_snap_token.php`) | `pelanggan/check_out.php` | Sedang - modifikasi form existing |
| 4.3 | Update `pesanan_user.php`: tambah tombol "Bayar" untuk transaksi yang masih pending dan punya snap_token | `pelanggan/pesanan_user.php` | Rendah |

### Fase 5: Testing dan Polish
**Risiko:** Bug di production  
**Estimasi:** 1-2 hari

| # | Task | Risiko |
|---|---|---|
| 5.1 | Test dengan Midtrans Sandbox (semua scenario di acceptance criteria) | - |
| 5.2 | Test race condition stok dengan 2 browser bersamaan | Sedang |
| 5.3 | Test notifikasi duplikat (kirim notifikasi manual via Midtrans Dashboard) | Sedang |
| 5.4 | Verifikasi laporan admin dan dashboard masih akurat | Rendah |
| 5.5 | Test alur lama (Transfer Bank) masih berfungsi untuk transaksi existing | Rendah |

### Fase 6 (Opsional): Pembersihan Alur Lama
**Risiko:** Menghapus fallback terlalu cepat  
**Jangan dilakukan sampai integrasi Midtrans terbukti stabil**

| # | Task | Risiko |
|---|---|---|
| 6.1 | Sembunyikan opsi "Transfer Bank" dari UI checkout (tetapi pertahankan backend) | Rendah |
| 6.2 | Arsipkan `pembayaran.php` dan logika upload bukti di `proses_pembayaran.php` | Tinggi jika Midtrans bermasalah |

---

## Lampiran: Referensi File Lengkap

| File | Peran | Baris Penting |
|---|---|---|
| `pelanggan/check_out.php` | Form checkout | 29-85 (data items), 368-426 (JS harga), 439 (form action), 476-477 (hidden inputs), 547 (total hidden) |
| `pelanggan/proses_checkout.php` | Proses checkout | 11 (total dari POST), 18-29 (cek stok), 31 (kode pembayaran), 37 (harga dari POST), 47-54 (session) |
| `pelanggan/pembayaran.php` | Halaman upload bukti | 10-28 (cek session/GET), 115-133 (form upload) |
| `pelanggan/proses_pembayaran.php` | Proses upload & simpan transaksi | 5-14 (batal), 49-100 (insert transaksi), 58-69 (cek stok ulang), 71-74 (INSERT transaksi), 82-99 (detail + stok + cart) |
| `includes/config.php` | Parse .env | 9-18 (parse), 20-24 (constants) |
| `includes/conn.php` | Koneksi DB | 5-7 (mysqli) |
| `includes/midtrans.php` | Konfigurasi SDK | 5-11 (autoload), 14-15 (keys), 28-34 (config) |
| `admin/pesanan.php` | Kelola pesanan | 15-27 (verifikasi GET), 367-375 (badge status) |
| `admin/pengiriman.php` | Kelola pengiriman | 1-3 (NO auth guard!), 8-31 (update) |
| `admin/laporan.php` | Laporan | 44-47 (pendapatan Lunas), 115-120 (keuangan Lunas) |
| `admin/dashboard_admin.php` | Dashboard | 13-17 (penjualan Lunas) |
| `beauty_care (1).sql` | Schema dump | 60-67 (detail_transaksi), 144-149 (keranjang), 164-172 (pelanggan), 188-196 (pengiriman), 234-241 (produk), 261-270 (transaksi) |
