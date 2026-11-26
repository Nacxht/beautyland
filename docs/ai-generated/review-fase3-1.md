# Laporan Review dan Audit Fase 3.1: Midtrans Snap Token

**File yang diaudit:** `pelanggan/create_snap_token.php`  
**Tanggal Audit:** 26 November 2026  
**Referensi:**
- `docs/ai-generated/audit-checkout-midtrans.md`
- `pelanggan/proses_checkout.php`
- `pelanggan/proses_pembayaran.php`
- `includes/midtrans.php`

---

## 1. Ringkasan Hasil Audit

Implementasi `pelanggan/create_snap_token.php` telah berhasil menerapkan validasi harga dari database, pemisahan hak akses, pembuatan transaksi atomik lokal, dan integrasi request Midtrans Snap SDK. 

Namun, dari audit mendalam ditemukan **3 celah arsitektur penting**:
1. **Pemeriksaan stok berada di luar transaksi database** dan tidak menggunakan row lock (`FOR UPDATE`), sehingga **AC-18 belum terpenuhi**.
2. **Koneksi transaksi database dibiarkan terbuka saat melakukan panggilan HTTP eksternal ke Midtrans API**, berisiko hold connection/lock lama dan inkonsistensi status jika terjadi kegagalan jaringan setelah Midtrans menerbitkan token.
3. **Belum ada mekanisme idempotency / debounce** untuk mencegah double-submit atau request paralel dari pelanggan yang sama.

---

## 2. Temuan Berdasarkan Empat Kategori Pemeriksaan

### 2.1. Race Condition Stok

1. **Apakah pemeriksaan stok dilakukan di dalam MySQL transaction?**
   - **Temuan:** **TIDAK.**
   - **Bukti Kode:** Pemeriksaan stok dilakukan pada baris 61–126, sedangkan transaksi database (`$conn->begin_transaction()`) baru dimulai pada baris 151.
2. **Apakah query menggunakan `SELECT ... FOR UPDATE`?**
   - **Temuan:** **TIDAK.**
   - **Bukti Kode:** Baris 66:
     ```php
     $stmt_prod = $conn->prepare("SELECT id_produk, nama_produk, harga, stok FROM produk WHERE id_produk = ?");
     ```
     Query ini adalah read biasa (non-locking consistent read).
3. **Apakah dua checkout bersamaan dapat sama-sama melewati pemeriksaan stok untuk produk terakhir?**
   - **Temuan:** **YA.**
   - **Skenario:** Misalkan produk X memiliki `stok = 1`. Pelanggan A dan Pelanggan B melakukan checkout pada detik yang sama. Keduanya membaca `stok = 1 >= 1` (lolos validasi baris 104). Karena stok tidak dikunci dan belum dipotong di tahap ini, kedua pelanggan sama-sama memperoleh Snap Token dan diarahkan untuk membayar barang yang hanya tersisa 1 unit.
4. **Status Pemenuhan AC-18:**
   - **Status:** **BELUM TERPENUHI di level checkout.**
   - **Catatan:** Kebijakan proyek saat ini menunda pengurangan stok hingga webhook notifikasi pembayaran (`settlement`). Namun tanpa mekanisme *temporary reservation* atau validasi ulang ketat dengan `FOR UPDATE` di webhook Fase 3.2, risiko *overselling* tetap terbuka lebar.

---

### 2.2. Transaksi Database dan Midtrans API

1. **Apakah request `Snap::createTransaction()` dilakukan ketika transaksi database masih terbuka?**
   - **Temuan:** **YA.**
   - **Bukti Kode:**
     - Baris 151: `$conn->begin_transaction();`
     - Baris 160–223: INSERT `transaksi`, UPDATE `midtrans_order_id`, INSERT `detail_transaksi`, INSERT `pengiriman`.
     - Baris 259: `$snapResponse = \Midtrans\Snap::createTransaction($params);` (panggilan HTTP eksternal)
     - Baris 282: `$conn->commit();`
2. **Dampak transaksi DB terbuka selama HTTP call:**
   - Panggilan HTTP ke API Midtrans dapat memakan waktu 1–5+ detik (tergantung latensi jaringan) atau hingga 30 detik saat timeout. Selama waktu tersebut, koneksi MySQL tertahan, konsumsi resource pool meningkat, dan undo log tidak dapat di-purge.
3. **Apa yang terjadi jika Midtrans berhasil membuat transaksi tetapi operasi DB berikutnya gagal?**
   - **Temuan:** Terjadi **inkonsistensi transaksi yatim (orphaned transaction)**.
   - **Skenario:** Midtrans berhasil merespons dan mencatat `order_id: BL-XX-TIMESTAMP`. Namun, sebelum `$conn->commit()` selesai (misal koneksi DB putus saat UPDATE `snap_token` di baris 268–271 atau script timeout), blok `catch` mengeksekusi `$conn->rollback()`.
   - **Akibat:** Database lokal membatalkan seluruh record transaksi (`transaksi`, `detail_transaksi`, `pengiriman`), tetapi Midtrans **sudah mencatat order tersebut aktif**. Jika pelanggan entah bagaimana membayar atau Midtrans mengirim webhook notifikasi, webhook tidak akan menemukan `order_id` tersebut di tabel `transaksi`.
4. **Apakah rollback database benar-benar menjamin tidak ada transaksi yatim di Midtrans?**
   - **Temuan:** **TIDAK.** Rollback MySQL hanya berlaku lokal pada database Beautyland, tidak memiliki efek balik (reversal/cancel) ke server Midtrans.
5. **Apakah mekanisme retry aman?**
   - **Temuan:** Relatif aman dari sisi pembuatan order baru, karena jika diulang, sistem akan membuat `id_transaksi` baru dan `midtrans_order_id` baru dengan timestamp berbeda. Namun transaksi lama yang gagal di sisi lokal akan tertinggal sebagai transaksi kedaluwarsa di dashboard Midtrans.

---

### 2.3. Pencegahan Checkout Duplikat

1. **Apakah POST berulang dapat menghasilkan beberapa record transaksi?**
   - **Temuan:** **YA.**
   - Jika pelanggan mengklik tombol "Bayar" berkali-kali secara cepat (double-click) atau koneksi lambat, browser dapat mengirim 2 request POST hampir bersamaan. Karena belum ada locking di session maupun DB, kedua request akan sama-sama membuat 2 baris transaksi di database dengan 2 `midtrans_order_id` yang berbeda.
2. **Apakah ada mekanisme idempotency untuk satu proses checkout?**
   - **Temuan:** **BELUM ADA.** Tidak ada token idempotency, status `in_progress` di session, atau pengecekan apakah ada transaksi pending aktif untuk item yang sama dalam rentang waktu singkat.
3. **Apakah session dibersihkan pada waktu yang tepat?**
   - **Temuan:** **SUDAH TEPAT untuk alur sukses**, yaitu pada baris 285 (`unset($_SESSION['pending_order']);`) setelah `$conn->commit()`.
   - Namun, karena pembersihan baru dilakukan di baris 285, ada jeda waktu beberapa detik di mana session masih ada dan rentan dieksekusi paralel oleh request kedua.
4. **Apakah pelanggan bisa mengulang checkout setelah kegagalan Midtrans?**
   - **Temuan:** **YA.** Karena `unset($_SESSION['pending_order'])` tidak dipanggil di blok `catch`, session tetap utuh sehingga pelanggan tidak kehilangan keranjang/pilihan barang jika terjadi gangguan jaringan Midtrans.

---

### 2.4. Validasi Konsistensi Data

1. **Total `gross_amount` vs `item_details`:**
   - **Temuan:** **KONSISTEN (VALID).**
   - Perhitungan total dilakukan dengan menjumlahkan `harga_satuan * kuantitas` (baris 114–116) yang identik dengan nilai per item pada `item_details` (baris 227–234). SDK Midtrans juga memverifikasi kesamaan jumlah ini.
2. **Sumber Harga:**
   - **Temuan:** **VALID (DARI DATABASE).**
   - Harga selalu di-fetch ulang dari kolom `produk.harga` melalui prepared statement (baris 90–92). Harga dari session diabaikan.
3. **Kesesuaian `id_pelanggan` session dengan `pending_order`:**
   - **Temuan:** **ADA CELAH VALIDASI.**
   - Di baris 33–45, sistem memvalidasi keberadaan `$_SESSION['pending_order']['items']`, tetapi **tidak memvalidasi** apakah `$_SESSION['pending_order']['id_pelanggan'] === $id_pelanggan`.
   - Jika session ditimpa atau terjadi pergantian user pada session aktif, data belanja user sebelumnya dapat terproses di akun user yang baru.
4. **Validasi Alamat dan Data Pelanggan:**
   - **Temuan:** **MENGGUNAKAN FALLBACK, BELUM STRICT.**
   - Jika alamat kosong, sistem menggunakan fallback `'Alamat belum diisi'`. Jika nama, email, atau HP pelanggan kosong, sistem menggunakan data dummy (`'pelanggan@example.com'`, `'08123456789'`).
   - Ini mencegah error pada validasi skema Midtrans, namun berisiko meloloskan order dengan data pengiriman fiktif/kosong.
5. **Isolasi Penghapusan Item Keranjang:**
   - **Temuan:** **VALID DAN TERISOLASI.**
   - Baris 274: `DELETE FROM keranjang WHERE id_pelanggan = ? AND id_produk = ?`. Penghapusan dibatasi secara ketat hanya untuk item yang dibeli oleh pelanggan tersebut.

---

## 3. Matriks Risiko yang Ditemukan

| No | Risiko | Tingkat | Dampak |
|---|---|---|---|
| R-01 | **Double Submit / Transaksi Ganda** | **TINGGI** | Pelanggan mengklik cepat menghasilkan 2 transaksi dan 2 order ID Midtrans aktif. |
| R-02 | **Inkonsistensi Transaksi Yatim** | **SEDANG** | Jika koneksi putus saat/setelah Midtrans menerbitkan token, DB lokal di-rollback tapi Midtrans sudah membuat order. |
| R-03 | **Long-lived DB Transaction** | **SEDANG** | Koneksi MySQL ditahan selama panggilan HTTP Midtrans berlangsung (potensi bottleneck koneksi DB). |
| R-04 | **Race Condition Stok (Overselling)** | **TINGGI** | Dua pelanggan bisa sama-sama mendapatkan Snap Token untuk stok barang terakhir (AC-18 belum terpenuhi). |
| R-05 | **Ownership Pending Order Tidak Divalidasi** | **RENDAH** | Tidak ada pengecekan kecocokan `id_pelanggan` pada isi `$_SESSION['pending_order']`. |

---

## 4. Rekomendasi Perbaikan

### Rekomendasi 1: Cegah Double Submit di Backend (Idempotency Guard)
Tambahkan flag status pemrosesan di session atau gunakan mutex sederhana:
```php
if (!empty($_SESSION['checkout_in_progress'])) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Pesanan sedang diproses. Mohon tunggu.']);
    exit;
}
$_SESSION['checkout_in_progress'] = true;
```
Pastikan flag ini dihapus baik di blok sukses maupun di blok `catch`.

### Rekomendasi 2: Validasi Kepemilikan `pending_order`
Tambahkan pengecekan eksplisit di awal:
```php
if ((int)($_SESSION['pending_order']['id_pelanggan'] ?? 0) !== $id_pelanggan) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sesi pesanan tidak valid untuk akun ini.']);
    exit;
}
```

### Rekomendasi 3: Pisahkan Transaksi Database Lokal dari HTTP Call (Two-Phase Commit Pattern Sederhana)
Alur yang lebih aman dari transaksi yatim:
1. Simpan record transaksi ke DB lokal terlebih dahulu dengan status `'Draft'` atau `'Menunggu Pembayaran'` dan **COMMIT** transaksi DB.
2. Panggil Midtrans Snap API.
3. Jika Snap berhasil: UPDATE transaksi dengan `snap_token`, bersihkan keranjang & session.
4. Jika Snap gagal: UPDATE transaksi lokal menjadi status `'Gagal'` atau `'Dibatalkan'` (jangan dibiarkan tanpa jejak). Dengan begitu, jika webhook masuk atau audit dilakukan, `midtrans_order_id` tetap dapat dilacak di database.

### Rekomendasi 4: Strategi Pemenuhan AC-18 pada Webhook (Fase 3.2)
Karena pemotongan stok dilakukan di Webhook (Fase 3.2):
- Webhook wajib menggunakan `SELECT stok FROM produk WHERE id_produk = ? FOR UPDATE` di dalam transaksi MySQL sebelum mengurangi stok.
- Jika stok ternyata sudah habis (karena pelanggan lain settlement lebih dulu), webhook harus mencatat log penolakan dan menandai transaksi untuk proses pengembalian dana (*refund* manual/otomatis), bukan membiarkan stok bernilai minus.

---

## 5. Daftar Masalah yang Wajib Diselesaikan Sebelum/Saat Fase 3.2

- [ ] **Validasi Kepemilikan Session:** Tambahkan validasi kecocokan `$_SESSION['pending_order']['id_pelanggan'] === $id_pelanggan`.
- [ ] **Proteksi Double-Submit:** Pasang debounce/guard status pemrosesan agar 1 checkout tidak menghasilkan multi-order.
- [ ] **Pencegahan Error Notifikasi pada Order Yatim:** Pastikan Webhook (Fase 3.2) menangani dengan aman kondisi di mana `order_id` yang diterima dari Midtrans tidak ditemukan di tabel `transaksi` (kembalikan HTTP 200 dengan log peringatan, jangan crash).
- [ ] **Penanganan Overselling di Webhook (Fase 3.2):** Terapkan pengecekan stok dengan lock `FOR UPDATE` saat webhook menerima status `settlement`/`capture`.
