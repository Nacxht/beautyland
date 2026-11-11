# BeautyLand — Product Backlog untuk Evaluasi OpenCode

Dokumen ini berisi backlog/issue utama dari repository [`Nacxht/beautyland`](https://github.com/Nacxht/beautyland), disalin dari deskripsi issue GitHub agar dapat digunakan sebagai konteks evaluasi oleh OpenCode.

> **Catatan penggunaan:** checklist di bawah adalah status yang tertulis pada deskripsi issue saat dokumen dibuat, bukan hasil verifikasi implementasi. OpenCode tetap harus memeriksa kode, pengujian, dan bukti Pull Request secara independen. Status kolom GitHub Projects tidak disertakan karena tampilan Projects tidak berhasil diakses melalui koneksi yang tersedia.

## Daftar Backlog

| Backlog | Issue | Tautan |
|---|---:|---|
| PPL-01 Login Admin | #1 | https://github.com/Nacxht/beautyland/issues/1 |
| PPL-02 Kelola Data Produk | #2 | https://github.com/Nacxht/beautyland/issues/2 |
| PPL-03 Kelola Transaksi | #3 | https://github.com/Nacxht/beautyland/issues/3 |
| PPL-04 Status Pembayaran Admin | #4 | https://github.com/Nacxht/beautyland/issues/4 |
| PPL-05 Input Data Pengiriman | #5 | https://github.com/Nacxht/beautyland/issues/5 |
| PPL-06 Update Status Pengiriman | #6 | https://github.com/Nacxht/beautyland/issues/6 |
| PPL-07 Registrasi dan Login Pelanggan | #7 | https://github.com/Nacxht/beautyland/issues/7 |
| PPL-08 Lihat Daftar Produk | #8 | https://github.com/Nacxht/beautyland/issues/8 |
| PPL-09 Keranjang Produk | #9 | https://github.com/Nacxht/beautyland/issues/9 |
| PPL-10 Pemesanan Produk | #10 | https://github.com/Nacxht/beautyland/issues/10 |
| PPL-11 Integrasi API Pengiriman Untuk Ongkir | #11 | https://github.com/Nacxht/beautyland/issues/11 |
| PPL-12 Integrasi API Pembayaran | #12 | https://github.com/Nacxht/beautyland/issues/12 |
| PPL-13 Status Pembayaran Pelanggan | #13 | https://github.com/Nacxht/beautyland/issues/13 |
| PPL-14 Status Pengiriman | #14 | https://github.com/Nacxht/beautyland/issues/14 |
| PPL-15 Riwayat Transaksi | #15 | https://github.com/Nacxht/beautyland/issues/15 |
| PPL-16 Menampilkan 5 Produk Terlaris | #16 | https://github.com/Nacxht/beautyland/issues/16 |
| PPL-17 Pekerjaan UI/UX | #53 | https://github.com/Nacxht/beautyland/issues/53 |
| PPL-18 Pekerjaan Product Owner | #54 | https://github.com/Nacxht/beautyland/issues/54 |
| PPL-19-Dummy Tambahkan penjelasan berupa komentar | #58 | https://github.com/Nacxht/beautyland/issues/58 |

---

## PPL-01 Login Admin

**GitHub Issue:** [#1](https://github.com/Nacxht/beautyland/issues/1)

**User story**

Sebagai admin, saya ingin melakukan login, agar dapat mengakses sistem sesuai dengan hak akses saya.

**Acceptance Criteria**
- [x] Admin dapat melakukan login menggunakan akun yang terdaftar.
- [x] Sistem memvalidasi username/email dan password admin.
- [x] Sistem memberikan akses ke halaman admin setelah login berhasil.

**Definition of Done**
- [x] Semua acceptance criteria terpenuhi
- [x] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [x] Fitur sudah diuji QA/Tester
- [x] Tidak ada error yang mengganggu penggunaan fitur
- [x] Kartu di GitHub Projects sudah Done

## PPL-02 Kelola Data Produk

**GitHub Issue:** [#2](https://github.com/Nacxht/beautyland/issues/2)

**User story**

Sebagai admin, saya ingin mengelola data produk, agar informasi produk yang dijual selalu sesuai.

**Acceptance Criteria**
- [x] Admin dapat menambahkan data produk, beserta nama, harga, dan stok.
- [x] Admin dapat melihat, mengubah, dan menghapus data produk.
- [x] Perubahan data produk tersimpan dan ditampilkan pada sistem.

**Definition of Done**
- [x] Semua acceptance criteria terpenuhi
- [x] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [x] Fitur sudah diuji QA/Tester
- [x] Tidak ada error yang mengganggu penggunaan fitur
- [x] Kartu di GitHub Projects sudah Done

## PPL-03 Kelola Transaksi

**GitHub Issue:** [#3](https://github.com/Nacxht/beautyland/issues/3)

**User Story**

Sebagai admin, saya ingin mengelola transaksi, agar pesanan pelanggan dapat diproses dengan baik.

**Acceptance Criteria**
- [ ] Admin dapat melihat data transaksi pelanggan.
- [ ] Admin dapat memproses pesanan yang masuk.
- [ ] Data pesanan tersimpan sesuai dengan pesanan pelanggan.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-04 Status Pembayaran Admin

**GitHub Issue:** [#4](https://github.com/Nacxht/beautyland/issues/4)

**User Story**

Sebagai admin, saya ingin melihat status pembayaran, agar dapat mengetahui pembayaran pelanggan.

**Acceptance Criteria**
- [ ] Admin dapat melihat status pembayaran setiap transaksi.
- [ ] Sistem menampilkan status pembayaran sesuai konfirmasi payment gateway.
- [ ] Status pembayaran yang telah dikonfirmasi ditampilkan pada halaman admin.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-05 Input Data Pengiriman

**GitHub Issue:** [#5](https://github.com/Nacxht/beautyland/issues/5)

**User Story**

Sebagai admin, saya ingin menginput data pengiriman, agar informasi pengiriman pesanan tercatat di sistem.

**Acceptance Criteria**
- [ ] Admin dapat memasukkan data pengiriman pesanan.
- [ ] Data pengiriman tersimpan pada transaksi yang sesuai.
- [ ] Informasi pengiriman dapat dilihat pada halaman pengiriman.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-06 Update Status Pengiriman

**GitHub Issue:** [#6](https://github.com/Nacxht/beautyland/issues/6)

**User Story**

Sebagai admin, saya ingin mengupdate status pengiriman, agar pelanggan dapat mengetahui perkembangan pengiriman pesanannya.

**Acceptance Criteria**
- [ ] Admin dapat memperbarui status pengiriman pesanan.
- [ ] Status pengiriman terbaru tersimpan di sistem.
- [ ] Pelanggan dapat melihat status pengiriman yang telah diperbarui.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-07 Registrasi dan Login Pelanggan

**GitHub Issue:** [#7](https://github.com/Nacxht/beautyland/issues/7)

**User story**

Sebagai pelanggan, saya ingin melakukan registrasi dan login, agar dapat mengakses sistem untuk melakukan pemesanan.

**Acceptance Criteria**
- [x] Pelanggan dapat melakukan registrasi akun.
- [x] Pelanggan dapat login menggunakan akun yang terdaftar.
- [x] Sistem memberikan akses ke fitur pelanggan setelah login berhasil.

**Definition of Done**
- [x] Semua acceptance criteria terpenuhi
- [x] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [x] Fitur sudah diuji QA/Tester
- [x] Tidak ada error yang mengganggu penggunaan fitur
- [x] Kartu di GitHub Projects sudah Done

## PPL-08 Lihat Daftar Produk

**GitHub Issue:** [#8](https://github.com/Nacxht/beautyland/issues/8)

**User story**

Sebagai pelanggan, saya ingin melihat daftar produk, agar dapat mengetahui produk yang tersedia untuk dibeli.

**Acceptance Criteria**
- [x] Pelanggan dapat melihat daftar produk yang tersedia.
- [x] Sistem menampilkan nama produk, harga, gambar dan stok produk.
- [x] Pelanggan dapat memilih produk untuk dimasukkan ke keranjang.

**Definition of Done**
- [x] Semua acceptance criteria terpenuhi
- [x] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [x] Fitur sudah diuji QA/Tester
- [x] Tidak ada error yang mengganggu penggunaan fitur
- [x] Kartu di GitHub Projects sudah Done

## PPL-09 Keranjang Produk

**GitHub Issue:** [#9](https://github.com/Nacxht/beautyland/issues/9)

**User story**

Sebagai pelanggan, saya ingin menambahkan produk ke keranjang, agar dapat mengumpulkan produk yang ingin dibeli.

**Acceptance Criteria**
- [x] Pelanggan dapat menambahkan produk ke keranjang.
- [x] Produk yang dipilih tampil pada keranjang.
- [x] Pelanggan dapat melihat jumlah dan total harga produk dalam keranjang.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-10 Pemesanan Produk

**GitHub Issue:** [#10](https://github.com/Nacxht/beautyland/issues/10)

**User story**

Sebagai pelanggan, saya ingin melakukan pemesanan produk, agar dapat membeli produk yang dipilih.

**Acceptance Criteria**
- [x] Pelanggan dapat melakukan checkout dari keranjang
- [x] Sistem menampilkan detail dan total harga pesanan
- [x] Data pesanan tersimpan setelah pelanggan melakukan pembayaran.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-11 Integrasi API Pengiriman Untuk Ongkir

**GitHub Issue:** [#11](https://github.com/Nacxht/beautyland/issues/11)

**User Story**

Sebagai pelanggan, saya ingin melihat ongkos kirim pada halaman checkout agar dapat mengetahui total biaya pesanan sebelum melakukan pembayaran.

**Acceptance Criteria**
- [ ] Sistem mengirimkan permintaan perhitungan ongkos kirim ke API pengiriman
- [ ] Sistem menampilkan tarif pengiriman yang diperoleh dari API pada halaman checkout
- [ ] Sistem menghitung dan menampilkan total harga produk beserta ongkos kirim

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-12 Integrasi API Pembayaran

**GitHub Issue:** [#12](https://github.com/Nacxht/beautyland/issues/12)

**User Story**

Sebagai pelanggan, saya ingin melakukan pembayaran melalui payment gateway agar pembayaran pesanan dapat diproses.

**Acceptance Criteria**
- [ ] Pelanggan dapat memulai pembayaran melalui payment gateway yang terintegrasi.
- [ ] Sistem mengirimkan data pembayaran sesuai transaksi yang dipilih.
- [ ] Sistem menerima hasil konfirmasi pembayaran dari payment gateway dan memperbarui status transaksi.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-13 Status Pembayaran Pelanggan

**GitHub Issue:** [#13](https://github.com/Nacxht/beautyland/issues/13)

**User Story**

Sebagai pelanggan, saya ingin melihat status pembayaran, agar mengetahui apakah pembayaran sudah diterima.

**Acceptance Criteria**
- [ ] Pelanggan dapat melihat status pembayaran pesanannya.
- [ ] Sistem menampilkan status pembayaran.
- [ ] Status pembayaran diperbarui setelah sistem menerima konfirmasi pembayaran.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-14 Status Pengiriman

**GitHub Issue:** [#14](https://github.com/Nacxht/beautyland/issues/14)

**User Story**

Sebagai pelanggan, saya ingin melihat status pengiriman barang, agar mengetahui perkembangan pengiriman pesanan.

**Acceptance Criteria**
- [ ] Pelanggan dapat melihat status pengiriman pesanannya.
- [ ] Sistem menampilkan status pengiriman terbaru.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-15 Riwayat Transaksi

**GitHub Issue:** [#15](https://github.com/Nacxht/beautyland/issues/15)

**User Story**

Sebagai pelanggan, saya ingin melihat riwayat transaksi, agar mengetahui pesanan yang pernah dilakukan.

**Acceptance Criteria**
- [ ] Pelanggan dapat melihat daftar transaksi yang pernah dilakukan.
- [ ] Sistem menampilkan detail transaksi pelanggan.
- [ ] Riwayat transaksi hanya menampilkan transaksi milik pelanggan yang sedang login.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-16 Menampilkan 5 Produk Terlaris

**GitHub Issue:** [#16](https://github.com/Nacxht/beautyland/issues/16)

**User Story**

Sebagai admin, saya ingin melihat 5 produk terlaris berdasarkan jumlah terjual, agar dapat mengetahui produk yang paling banyak diminati pelanggan.

**Acceptance Criteria**
- [ ] Sistem menghitung jumlah produk yang terjual berdasarkan data transaksi yang pembayarannya telah berhasil.
- [ ] Sistem menampilkan maksimal 5 produk dengan jumlah terjual terbanyak.
- [ ] Daftar produk terlaris diperbarui secara otomatis ketika terdapat transaksi berhasil yang memengaruhi jumlah penjualan.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kode di-merge ke main melalui Pull Request dan di-review minimal 1 anggota
- [ ] Fitur sudah diuji QA/Tester
- [ ] Tidak ada error yang mengganggu penggunaan fitur
- [ ] Kartu di GitHub Projects sudah Done

## PPL-17 Pekerjaan UI/UX

**GitHub Issue:** [#53](https://github.com/Nacxht/beautyland/issues/53)

**Deskripsi**

Menyesuaikan antarmuka aplikasi RPL menjadi BeautyLand dan merancang halaman baru.

**Rincian Pekerjaan**
- [x] Rancang identitas BeautyLand (nama dan warna)
- [x] Rancang UI checkout, pilihan ongkir, dan pembayaran
- [x] Rancang UI produk terlaris, status pesanan, dan status pengiriman
- [x] Daftar penyesuaian halaman RPL (ganti "MAARS Beauty", hapus menu konsultasi)

**Acceptance Criteria**
- [x] Seluruh rancangan tersedia dan konsisten.

**Definition of Done**
- [x] Semua acceptance criteria terpenuhi
- [x] Kartu di GitHub Projects sudah Done

## PPL-18 Pekerjaan Product Owner

**GitHub Issue:** [#54](https://github.com/Nacxht/beautyland/issues/54)

**Deskripsi**

Menyusun product backlog dan laporan requirement (SRS) BeautyLand sesuai template IEEE dan profil UMKM yang dipilih.

**Rincian Pekerjaan**
- [x] Susun product backlog fitur beserta prioritasnya (PPL-01 sampai PPL-16)
- [x] Susun draft Laporan Requirement bab 1–2
- [ ] Finalisasi Laporan Requirement

**Acceptance Criteria**
- [ ] Laporan mengikuti struktur template IEEE, dan setiap requirement jelas, dapat diuji, serta merujuk kode user story.

**Definition of Done**
- [ ] Semua acceptance criteria terpenuhi
- [ ] Kartu di GitHub Projects sudah Done

## PPL-19-Dummy Tambahkan penjelasan berupa komentar

**GitHub Issue:** [#58](https://github.com/Nacxht/beautyland/issues/58)

**Deskripsi**

Issue ini tidak memiliki deskripsi atau Acceptance Criteria/Definition of Done pada data yang berhasil diambil. Periksa issue GitHub secara langsung sebelum melakukan evaluasi.

---

## Catatan untuk Evaluator (OpenCode)

1. Perlakukan setiap Acceptance Criteria dan Definition of Done sebagai persyaratan yang harus diverifikasi, bukan sebagai bukti bahwa pekerjaan telah selesai.
2. Periksa implementasi pada source code dan konfigurasi yang relevan. Cantumkan path file dan nomor baris bila memungkinkan.
3. Jalankan atau periksa test yang relevan. Jangan menyatakan kriteria lulus hanya karena terdapat implementasi yang tampak sesuai.
4. Untuk DoD terkait Pull Request, merge ke `main`, dan review, periksa riwayat Pull Request/commit. Jika bukti tidak tersedia, tandai **Belum dapat diverifikasi**.
5. Untuk DoD QA/Tester dan tidak adanya error, cari bukti test atau dokumentasi QA. Jangan menyimpulkan lulus tanpa bukti.
6. Untuk kriteria “Kartu di GitHub Projects sudah Done”, status perlu diperiksa langsung dari GitHub Projects; status ini tidak dapat dibuktikan dari source code repository.
7. Gunakan hasil: **Terpenuhi**, **Tidak terpenuhi**, atau **Belum dapat diverifikasi**. Sertakan alasan dan bukti untuk setiap hasil.
8. Jangan mengubah issue, checklist, label, status Project, atau source code. Evaluasi dilakukan secara read-only.

## Sumber

- GitHub Project: https://github.com/users/Nacxht/projects/5/views/1
- Repository: https://github.com/Nacxht/beautyland
