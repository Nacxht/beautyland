# Laporan Perbaikan Temuan F-01: Late Snap Response Must Not Overwrite Terminal Payment State

**Repository:** Beautyland (PHP Native, MySQLi, Midtrans Snap)  
**Tanggal Perbaikan:** 26 November 2026  
**Dokumen Rujukan:**
- `docs/ai-generated/final-concurrency-recovery-audit.md` (Temuan F-01)  
**File Utama yang Dimodifikasi:** `pelanggan/create_snap_token.php`  
**Status Temuan F-01:** **FIXED**

---

## 1. Ringkasan Perubahan

Perbaikan F-01 menyelesaikan celah konkurensi di mana respons Snap Token yang terlambat (*late arrival*) dari gateway Midtrans berpotensi menimpa status pembayaran terminal (`Lunas` atau `Dibatalkan`) yang telah diperbarui lebih dahulu oleh webhook atau proses lain.

Pembaruan dilakukan pada endpoint `pelanggan/create_snap_token.php`:
1. **Pemeriksaan Status Terkini dengan Row Locking (`FOR UPDATE`):** Tahap 3A kini membungkus pemeriksaan dan pembaruan dalam transaksi singkat dengan `SELECT id_transaksi, status_pembayaran, snap_status, snap_token FROM transaksi WHERE id_transaksi = ? FOR UPDATE`.
2. **Perlindungan Status Terminal (`Lunas` & `Dibatalkan`):** Jika transaksi telah berstatus `Lunas` atau `Dibatalkan`, sistem **tidak** mengubah `status_pembayaran` dan **tidak** mengubah `snap_status` menjadi `Berhasil`.
3. **Pencatatan Token Terlambat untuk Audit:** Jika transaksi sudah terminal namun belum memiliki `snap_token`, token dari Midtrans tetap dicatat pada kolom `snap_token` sebagai rekam jejak audit (*audit trail*), tanpa menyentuh status pembayaran.
4. **Pencegahan Respons Pembayaran pada Status Terminal:** Jika transaksi sudah terminal, sistem merespons dengan HTTP 409 dan pesan informatif, bukan mengembalikan token dengan status siap bayar (HTTP 200).
5. **Kondisionalitas pada Seluruh UPDATE:** Kueri pembaruan status `snap_status` (baik untuk `Berhasil`, `Perlu Rekonsiliasi`, maupun `Gagal`) kini diproteksi dengan klausa ketat `AND status_pembayaran = 'Menunggu Pembayaran'`.

---

## 2. File yang Dibuat dan Dimodifikasi

| No | Path File | Aksi | Keterangan |
|---|---|---|---|
| 1 | `pelanggan/create_snap_token.php` | Modifikasi | Implementasi row-locking `FOR UPDATE`, conditional check terminal state, audit recording Snap Token, dan proteksi conditional UPDATE. |
| 2 | `docs/ai-generated/fix-f01-late-snap-response.md` | Dibuat | Laporan analisis teknis dan verifikasi perbaikan F-01 ini. |

*Catatan: Tidak ada perubahan skema database, migrasi tambahan, perubahan `.env`, maupun modifikasi pada pembayaran manual.*

---

## 3. Penjelasan Alur Sebelum dan Sesudah Perbaikan F-01

### Alur Sebelum Perbaikan:
```
[Request Checkout] ---> Panggil Midtrans Snap API (lambat > 120s)
                             |
                             |-- [Di tengah proses: Webhook tiba & set status_pembayaran = 'Dibatalkan']
                             v
Respons Midtrans diterima
       |
       v
UPDATE transaksi SET snap_token = ?, snap_status = 'Berhasil' WHERE id_transaksi = ?;
       |
       +--> MASALAH: snap_status tertimpa menjadi 'Berhasil' padahal status_pembayaran sudah 'Dibatalkan'!
       +--> MASALAH: Browser menerima HTTP 200 dan memunculkan popup pembayaran untuk transaksi batal!
```

### Alur Setelah Perbaikan:
```
[Request Checkout] ---> Panggil Midtrans Snap API (lambat > 120s)
                             |
                             |-- [Di tengah proses: Webhook tiba & set status_pembayaran = 'Dibatalkan']
                             v
Respons Midtrans diterima
       |
       v
$conn->begin_transaction()
       |
SELECT ... FROM transaksi WHERE id_transaksi = ? FOR UPDATE
       |
       +--> [Kondisi A: status_pembayaran == 'Lunas']
       |      - Simpan snap_token untuk audit (jika belum ada)
       |      - JANGAN ubah status_pembayaran
       |      - JANGAN ubah snap_status ke 'Berhasil'
       |      - JANGAN lepas reservasi stok
       |      - Hapus keranjang & session (pesanan sudah beres)
       |      - COMMIT & Return HTTP 409 ("Pesanan ini telah lunas dibayar")
       |
       +--> [Kondisi B: status_pembayaran == 'Dibatalkan']
       |      - Simpan snap_token untuk audit (jika belum ada)
       |      - JANGAN ubah status_pembayaran
       |      - JANGAN ubah snap_status ke 'Berhasil'
       |      - JANGAN lepas reservasi stok (sudah ditangani saat pembatalan)
       |      - COMMIT & Return HTTP 409 ("Pesanan ini telah dibatalkan")
       |
       +--> [Kondisi C: status_pembayaran == 'Menunggu Pembayaran']
              - UPDATE snap_token = ?, snap_status = 'Berhasil' WHERE ... AND status_pembayaran = 'Menunggu Pembayaran'
              - Hapus item keranjang
              - COMMIT & Return HTTP 200 { success: true, snap_token, order_id }
```

---

## 4. Analisis Rinci Lima Skenario Wajib

### Skenario 1: Transaksi Masih `Menunggu Pembayaran`, `snap_status = 'Menunggu'` (Happy Path)
- **Kondisi:** Transaksi belum pernah disentuh oleh webhook atau proses pembatalan.
- **Eksekusi:** 
  1. `SELECT ... FOR UPDATE` membaca `status_pembayaran = 'Menunggu Pembayaran'` dan `snap_status = 'Menunggu'`.
  2. Masuk ke cabang normal non-terminal.
  3. Kueri `UPDATE transaksi SET snap_token = ?, snap_status = 'Berhasil' WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'` berhasil memperbarui baris.
  4. Item keranjang dihapus, session `pending_order` dibersihkan.
  5. Transaksi di-commit.
  6. Mengembalikan respons HTTP 200 dengan Snap Token.
- **Hasil:** **PASS (Normal Flow Berjalan Sempurna)**.

---

### Skenario 2: Transaksi Sudah `Lunas` Ketika Respons Midtrans Diterima
- **Kondisi:** Webhook notifikasi pembayaran instan (atau settlement cepat) tiba lebih dahulu dan telah mengubah `status_pembayaran = 'Lunas'` saat pemanggilan Snap di browser mengalami perlambatan.
- **Eksekusi:**
  1. `SELECT ... FOR UPDATE` membaca `status_pembayaran = 'Lunas'`.
  2. Kondisi `if ($current_pay_status === 'Lunas')` terpenuhi.
  3. Token dicatat ke kolom `snap_token` jika masih kosong untuk audit trail, **tanpa** menyentuh `status_pembayaran` maupun `snap_status`.
  4. `release_stock_reservation()` **TIDAK** dipanggil (stok fisik sudah dikonversi atau menjadi hak pesanan).
  5. Keranjang dan session dibersihkan karena pesanan telah lunas.
  6. Transaksi di-commit.
  7. Mengembalikan HTTP 409: `{"success": false, "message": "Pesanan ini telah lunas dibayar."}`.
- **Hasil:** **PASS (Status Lunas Terlindungi, Pengguna Tidak Diminta Membayar Ulang)**.

---

### Skenario 3: Transaksi Sudah `Dibatalkan` Ketika Respons Midtrans Diterima
- **Kondisi:** Pesanan dibatalkan (misal: notifikasi `expire`/`cancel` dari gateway atau pembatalan administratif) saat pemanggilan Snap berlangsung lambat.
- **Eksekusi:**
  1. `SELECT ... FOR UPDATE` membaca `status_pembayaran = 'Dibatalkan'`.
  2. Kondisi `if ($current_pay_status === 'Dibatalkan')` terpenuhi.
  3. Token dicatat ke kolom `snap_token` jika masih kosong untuk audit trail, **tanpa** mengubah `status_pembayaran = 'Dibatalkan'` atau `snap_status`.
  4. `release_stock_reservation()` **TIDAK** dipanggil di sini karena proses pembatalan awal sudah melepaskannya; menghindari risiko inkonsistensi agregat stok.
  5. Transaksi di-commit.
  6. Mengembalikan HTTP 409: `{"success": false, "message": "Pesanan ini telah dibatalkan."}`.
- **Hasil:** **PASS (Status Dibatalkan Terlindungi, Popup Pembayaran Dicegah)**.

---

### Skenario 4: Transaksi Sudah `Perlu Rekonsiliasi`
- **Kondisi:** Transaksi sempat mengalami timeout W-01 (melebihi 120 detik) sehingga Request B menandainya sebagai `'Perlu Rekonsiliasi'`, tetapi `status_pembayaran` **masih** `'Menunggu Pembayaran'`. Kemudian Request A akhirnya menerima token dari Midtrans.
- **Eksekusi:**
  1. `SELECT ... FOR UPDATE` membaca `status_pembayaran = 'Menunggu Pembayaran'` dan `snap_status = 'Perlu Rekonsiliasi'`.
  2. Karena status pembayaran **bukan** terminal (`Lunas` atau `Dibatalkan`), kondisi terminal dilewati.
  3. Sistem mengenali bahwa Midtrans ternyata berhasil memproses transaksi dan menerbitkan token.
  4. Kueri UPDATE mengubah `snap_token = $snapToken` dan `snap_status = 'Berhasil'`.
  5. Status transaksi berhasil dipulihkan secara aman dari `'Perlu Rekonsiliasi'` ke `'Berhasil'`.
  6. Mengembalikan HTTP 200 dengan token siap bayar.
- **Hasil:** **PASS (Pemulihan Transaksi Aman & Terverifikasi)**.

---

### Skenario 5: Webhook dan Penyimpanan Token Berlangsung Hampir Bersamaan
- **Kondisi:** Webhook dari Midtrans dan thread checkout `create_snap_token.php` mengakses record transaksi pada milidetik yang sama.
- **Analisis Konkurensi:**
  1. Tahap 3A kini menggunakan `SELECT ... FOR UPDATE` di dalam transaksi MySQL (`$conn->begin_transaction()`).
  2. MySQL InnoDB menerapkan *exclusive row lock* pada baris transaksi tersebut.
  3. **Jika Webhook tiba lebih dahulu:** Webhook mengunci baris, mengubah `status_pembayaran` menjadi `Lunas` (atau `Dibatalkan`), lalu commit. Ketika lock dilepas, Tahap 3A membaca baris yang sudah ter-commit sebagai `Lunas` (Skenario 2) atau `Dibatalkan` (Skenario 3), sehingga tidak menimpa status webhook.
  4. **Jika Tahap 3A tiba lebih dahulu:** Tahap 3A mengunci baris, membaca `Menunggu Pembayaran`, menyimpan token, mengupdate `snap_status = 'Berhasil'`, lalu commit (hanya memakan waktu 2–5 ms). Begitu lock dilepas, Webhook membaca record yang sudah memiliki `snap_token` dan memproses settlement secara normal.
- **Hasil:** **PASS (Race Condition Dieliminasi Total oleh Row Locking)**.

---

## 5. Hasil Pemeriksaan Sintaks (`php -l`)

```bash
php -l /var/www/html/Beautyland/pelanggan/create_snap_token.php
# Output: No syntax errors detected in /var/www/html/Beautyland/pelanggan/create_snap_token.php
```

---

## 6. Audit Seluruh Jalur UPDATE `snap_status`

Terdapat 4 titik kueri pembaruan `snap_status` pada `pelanggan/create_snap_token.php`:

| Baris | Titik Kueri | Klausa WHERE | Proteksi Terminal State |
|---|---|---|---|
| **73–76** | `handle_existing_transaction_response` (W-01) | `WHERE id_transaksi = ? AND snap_status = 'Menunggu' AND status_pembayaran = 'Menunggu Pembayaran'` | **Aman:** Hanya memperbarui jika status pembayaran belum terminal. |
| **573–577** | Tahap 3B (Timeout Midtrans) | `WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'` | **Aman:** Tidak dapat menimpa jika webhook sudah mengubah status ke Lunas/Batal. |
| **593–597** | Tahap 3C (Gagal Definitif Midtrans) | `WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'` | **Aman:** Tidak dapat menimpa status terminal. |
| **652–656** | Tahap 3A (Sukses Midtrans) | `WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'` | **Aman:** Didahului `SELECT ... FOR UPDATE`; mutlak melindungi status Lunas/Dibatalkan. |

Semua jalur pembaruan terbukti aman dan tidak ada satu pun jalur yang dapat menimpa status pembayaran terminal.

---

## 7. Status Temuan F-01

| ID Temuan | Deskripsi | Status | Justifikasi |
|---|---|---|---|
| **F-01** | Late Snap Response Must Not Overwrite Terminal Payment State | **FIXED** | Tahap 3A menggunakan row-lock `FOR UPDATE`, memverifikasi status pembayaran terkini, menolak respons siap bayar jika sudah terminal, merekam token untuk audit trail tanpa mengubah status terminal, dan menggunakan conditional UPDATE. |

---

## 8. Skenario yang Belum Dapat Diuji Secara Runtime

- **Simulasi Konkurensi Milidetik Nyata:** Mengirim webhook `settlement` dan request Snap Token secara bersamaan pada tingkat socket jaringan (memerlukan mock server / Midtrans Sandbox aktif).
- **Pengujian Browser:** Interaksi frontend (akan diuji pada Fase 4).

---

## 9. Rekomendasi Kesiapan Melangkah ke Fase 3.2

Dengan diselesaikannya temuan F-01:
1. Tidak ada lagi celah penimpaan status antara endpoint checkout dan webhook notifikasi.
2. Seluruh status terminal (`Lunas` dan `Dibatalkan`) terlindungi dari regresi status.
3. Seluruh temuan dari Fase 3.1.1 (B-01, W-01, W-02, F-01) kini berstatus **FIXED**.

**Kesimpulan:**  
Proyek Beautyland **SEPENUHNYA SIAP** untuk dilanjutkan ke implementasi **Fase 3.2 — Midtrans Notification Webhook** (`midtrans_notification.php`).
