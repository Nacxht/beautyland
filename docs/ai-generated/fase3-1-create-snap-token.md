# Laporan Fase 3.1: Implementasi Backend Pembuatan Midtrans Snap Token

**Tanggal:** 26 November 2026  
**Referensi:** `docs/ai-generated/audit-checkout-midtrans.md` (Task 3.1)  
**File yang dibuat:** `pelanggan/create_snap_token.php`  
**Status:** Selesai, lolos pengecekan sintaks PHP (`php -l`), siap untuk integrasi frontend pada Fase 4.

---

## 1. File yang Dibuat

- **`pelanggan/create_snap_token.php`** (Endpoint JSON POST untuk inisiasi Snap Token)

Tidak ada file eksisting yang diubah pada fase ini.

---

## 2. Alur Kerja Endpoint (`pelanggan/create_snap_token.php`)

```
[Frontend (Fase 4)]
        |
        | HTTP POST
        v
1. Validasi HTTP Method (harus POST) -> Jika bukan: 405 Method Not Allowed
2. Validasi Autentikasi ($_SESSION['id_pelanggan']) -> Jika belum login: 401 Unauthorized
3. Validasi Session ($_SESSION['pending_order']) -> Jika kosong/invalid: 400 Bad Request
4. Query DB tabel 'produk':
   - Validasi ID produk dan kuantitas (> 0)
   - Cek keberadaan produk di DB (Prepared Statement)
   - Cek stok mencukupi (stok >= qty) -> Jika tidak: 409 Conflict
   - Ambil harga asli dari DB dan hitung ulang subtotal & total
5. Query DB tabel 'pelanggan' untuk data pembeli (nama, email, no_hp, alamat)
6. Mulai MySQL Transaction ($conn->begin_transaction()):
   a. INSERT transaksi (status: 'Menunggu Pembayaran', metode: 'Midtrans', stok_dikurangi: 0)
   b. Generate midtrans_order_id unik ("BL-{id_transaksi}-{timestamp}") & UPDATE transaksi
   c. INSERT detail_transaksi untuk setiap item
   d. INSERT pengiriman (status: 'Belum Diproses')
   e. Request Snap Token via \Midtrans\Snap::createTransaction($params)
   f. UPDATE transaksi SET snap_token = ...
   g. DELETE item terkait dari tabel keranjang
   h. COMMIT transaksi ($conn->commit())
   i. Hapus $_SESSION['pending_order']
7. Kembalikan JSON { success: true, snap_token, order_id, redirect_url }
```

---

## 3. Cara Pengambilan Data Session

Data diambil dari `$_SESSION['pending_order']` yang telah disiapkan pada Fase 1 oleh `proses_checkout.php`:
- `id_pelanggan`: dicocokkan dengan `$_SESSION['id_pelanggan']`.
- `alamat`: diambil dari `$_SESSION['pending_order']['alamat']` sebagai prioritas alamat tujuan pengiriman, dengan fallback alamat profil pelanggan jika kosong.
- `kode_pembayaran`: dipertahankan dari session untuk referensi internal (`MB-XXXXXXXX`).
- `items`: array item produk yang berisi `id_produk` dan `jumlah`.

---

## 4. Cara Validasi Harga dan Stok

- Endpoint **tidak mempercayai** harga satuan atau total yang dikirim client/tersimpan di session.
- Setiap item di-query ulang ke database menggunakan prepared statement:
  ```sql
  SELECT id_produk, nama_produk, harga, stok FROM produk WHERE id_produk = ?
  ```
- **Validasi Kuantitas & ID:** Wajib berupa integer positif (`> 0`).
- **Validasi Ketersediaan:** Jika produk tidak ditemukan di tabel `produk`, kembalikan HTTP 404.
- **Validasi Stok:** Jika `stok < kuantitas`, endpoint menolak pesanan dengan status HTTP 409 dan menyebutkan nama produk serta sisa stok yang ada.
- **Perhitungan Harga:**
  - `subtotal = harga_dari_db * kuantitas`
  - `total_harga = SUM(subtotal)`
  - Nilai ini yang diteruskan ke Midtrans (`gross_amount`) dan dicatat ke tabel `transaksi`.

---

## 5. Cara Penyimpanan Transaksi Database

Seluruh operasi penulisan menggunakan MySQL Transaction (`InnoDB`):
1. **Tabel `transaksi`**:
   - `status_pembayaran` = `'Menunggu Pembayaran'`
   - `metode_pembayaran` = `'Midtrans'`
   - `stok_dikurangi` = `0` (stok **tidak** dipotong di endpoint ini, akan dipotong pada handler notifikasi webhook di Fase 3.2)
2. **`midtrans_order_id`**:
   - Dibuat dengan pola `BL-{id_transaksi}-{timestamp}` setelah mendapatkan `insert_id` dari database.
   - Pola ini menjamin keunikan dan sesuai dengan index `idx_midtrans_order_id`.
3. **Tabel `detail_transaksi`**:
   - Menyimpan setiap produk pesanan dengan `harga_satuan` dari DB dan `subtotal`.
4. **Tabel `pengiriman`**:
   - Menyimpan `alamat_tujuan` dan status `'Belum Diproses'`.

---

## 6. Request dan Penyimpanan Snap Token

- Menggunakan library resmi `\Midtrans\Snap::createTransaction($params)`.
- Mengirimkan struktur payload:
  - `transaction_details`: `order_id` dan `gross_amount`.
  - `item_details`: daftar produk (id, price, quantity, name).
  - `customer_details`: name, email, phone, shipping_address.
- Menyimpan nilai `token` ke kolom `snap_token` di tabel `transaksi`.
- Server Key tersimpan di file internal `.env` melalui `includes/midtrans.php` dan **tidak pernah diteruskan ke respons JSON**.

---

## 7. Penanganan Kegagalan Midtrans

- Blok pembuatan transaksi dan pemanggilan API Midtrans berada dalam blok `try { ... } catch (\Throwable $e)`.
- Jika API Midtrans melempar exception (misalnya koneksi putus, kredensial salah):
  - `$conn->rollback()` dipanggil, sehingga database kembali bersih tanpa record `transaksi`, `detail_transaksi`, atau `pengiriman` yang setengah tersimpan.
  - Item keranjang **tidak dihapus**.
  - `$_SESSION['pending_order']` **tidak dihapus**, sehingga pelanggan dapat mengulang checkout.
  - Pesan error internal dan stack trace dicatat ke `error_log()`, tidak ditampilkan ke client.
  - Endpoint mengembalikan status HTTP 500 dengan pesan JSON umum yang aman: `"Pesanan tidak dapat diproses. Terjadi gangguan pada gateway pembayaran."`.

---

## 8. Hasil Uji Sintaks (`php -l`)

```bash
php -l /var/www/html/Beautyland/pelanggan/create_snap_token.php
# Output: No syntax errors detected in /var/www/html/Beautyland/pelanggan/create_snap_token.php
```

---

## 9. Risiko dan Hal yang Belum Terselesaikan

1. **Migrasi Database Belum Dijalankan:**
   Endpoint ini mengandalkan kolom-kolom baru (`midtrans_order_id`, `snap_token`, `stok_dikurangi`) yang didefinisikan pada migrasi Fase 2 (`database/migrations/20261126_add_midtrans_columns.sql`). Migrasi perlu dijalankan sebelum endpoint ini dites secara end-to-end.
2. **Kredensial Midtrans di `.env`:**
   Pastikan file `.env` memiliki nilai `MIDTRANS_SERVER_KEY` dan `MIDTRANS_CLIENT_KEY` Sandbox yang valid agar `createTransaction` tidak menolak request.
3. **Frontend Belum Terhubung:**
   Endpoint ini belum diakses oleh antarmuka checkout (`check_out.php`). Integrasi form dan Snap JS akan dikerjakan pada Fase 4.
4. **Pengurangan Stok (Webhook):**
   Karena stok sengaja tidak dikurangi di endpoint ini, penanganan pengurangan stok saat pembayaran berhasil (`settlement`) wajib diimplementasikan pada Fase 3.2 (`midtrans_notification.php`).
