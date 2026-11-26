# Fase 1: Perbaikan Validasi Checkout

**Tanggal:** 26 November 2026
**Referensi:** `docs/ai-generated/audit-checkout-midtrans.md` bagian 9, Fase 1

---

## Ringkasan

Seluruh harga transaksi kini dihitung dan divalidasi oleh backend menggunakan data dari tabel `produk`. Hidden input `harga_satuan[]` dan `total_harga` yang sebelumnya dikirim dari browser telah dihapus dari form. Manipulasi form via DevTools tidak lagi memengaruhi harga atau total transaksi.

---

## File yang Dimodifikasi

| File | Jenis Perubahan |
|---|---|
| `pelanggan/proses_checkout.php` | Rewrite logika validasi dan perhitungan harga |
| `pelanggan/check_out.php` | Hapus hidden input berbahaya, perbaiki SQL injection |

---

## Perubahan Detail

### `pelanggan/proses_checkout.php`

| Aspek | Sebelum | Sesudah |
|---|---|---|
| Sumber harga | `$_POST['harga_satuan']` (dari browser) | `SELECT harga FROM produk` via prepared statement |
| Sumber total | `$_POST['total_harga']` (dari browser) | `SUM(harga_db * jumlah)` dihitung di backend |
| Validasi jumlah | Tidak ada | Wajib bilangan bulat > 0 |
| Validasi ID produk | Tidak ada | Wajib bilangan bulat > 0 dan ada di database |
| Validasi stok | String interpolation, tanpa validasi jumlah | Prepared statement, cek `stok < qty` |
| Query SQL | `mysqli_query()` dengan interpolasi variabel | `$conn->prepare()` dengan `bind_param()` |
| Pesan error stok | Tanpa info sisa stok | Menyertakan sisa stok dalam pesan |

### `pelanggan/check_out.php`

| Aspek | Sebelum | Sesudah |
|---|---|---|
| Hidden input `harga_satuan[]` | Ada (dikirim ke backend) | Dihapus |
| Hidden input `total_harga` | Ada (dikirim ke backend) | Dihapus |
| JS `updateTotal()` | Menulis ke `#totalHargaInput` | Baris dihapus (elemen tidak ada lagi) |
| Query pelanggan | `DB_CONN` + string interpolation | `$conn->prepare()` dengan `bind_param()` |
| Query produk (beli langsung) | String interpolation | Prepared statement |
| Query keranjang | String interpolation | Prepared statement |

---

## Bagaimana Harga Dihitung

1. Frontend mengirim hanya `id_produk[]` dan `jumlah[]` ke `proses_checkout.php`.
2. Backend mem-fetch semua produk dalam satu batch query: `SELECT id_produk, nama_produk, harga, stok FROM produk WHERE id_produk IN (?, ?, ...)`.
3. Untuk setiap item: `subtotal = harga_dari_db * jumlah`.
4. Total: `total_harga = SUM(semua subtotal)`.
5. Nilai-nilai ini disimpan ke `$_SESSION['pending_order']` dan akhirnya ke tabel `transaksi` dan `detail_transaksi` oleh `proses_pembayaran.php`.

---

## Bagaimana Validasi Stok Dilakukan

1. Semua produk di-fetch beserta kolom `stok` dalam satu query batch.
2. Untuk setiap item, dicek: `if ($produk['stok'] < $qty)`.
3. Jika stok tidak mencukupi, ditampilkan alert JavaScript dengan nama produk dan sisa stok, lalu `exit`.
4. Produk yang tidak ditemukan di database langsung ditolak dengan `die()`.

---

## Alur yang Tetap Berjalan

- **Beli langsung:** `check_out.php` menerima `$_POST['id_produk']` (single) -> tampilkan form -> submit `id_produk[]` + `jumlah[]` ke `proses_checkout.php` -> harga dari DB -> `$_SESSION['pending_order']` -> redirect ke `pembayaran.php`.
- **Dari keranjang:** `check_out.php` menerima `$_POST['pilih_produk']` (array) -> tampilkan form -> alur sama.
- **Pembayaran manual:** `proses_pembayaran.php` tidak diubah. Membaca `$_SESSION['pending_order']` dengan struktur yang identik (`id_pelanggan`, `total_harga`, `metode_pembayaran`, `kode_pembayaran`, `alamat`, `items[]`).

---

## Acceptance Criteria

- [x] Harga transaksi selalu berasal dari database
- [x] Manipulasi hidden input `harga_satuan[]` tidak memengaruhi total (input dihapus, backend tidak membacanya)
- [x] Manipulasi hidden input `total_harga` tidak memengaruhi total (input dihapus, backend tidak membacanya)
- [x] Jumlah produk tidak boleh nol atau negatif
- [x] Produk yang tidak ditemukan ditolak
- [x] Produk dengan stok tidak mencukupi ditolak
- [x] Query yang menggunakan input pelanggan menggunakan prepared statements
- [x] Mekanisme pembayaran manual yang sudah ada tetap berfungsi

---

## Catatan

- Tampilan harga di frontend (JavaScript `updateTotal()`, `updateSubtotal()`) tetap dipertahankan sebagai informasi visual bagi pelanggan. Nilai-nilai ini tidak dikirim ke backend.
- Struktur `$_SESSION['pending_order']` tidak berubah, sehingga `pembayaran.php` dan `proses_pembayaran.php` tetap kompatibel.
- Task 1.3 dari audit (auth guard `admin/pengiriman.php`) tidak termasuk dalam scope pekerjaan ini.
