-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Waktu pembuatan: 08 Okt 2026 pada 05.40
-- Versi server: 8.0.30
-- Versi PHP: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Basis data: `beauty_care`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `booking`
--

CREATE TABLE `booking` (
  `id_booking` int NOT NULL,
  `kode_booking` varchar(20) DEFAULT NULL,
  `id_pelanggan` int DEFAULT NULL,
  `id_jadwal` int DEFAULT NULL,
  `no_antrean` int DEFAULT NULL,
  `jam_mulai_user` time DEFAULT NULL,
  `jam_selesai_user` time DEFAULT NULL,
  `tanggal_booking` date DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `booking`
--

INSERT INTO `booking` (`id_booking`, `kode_booking`, `id_pelanggan`, `id_jadwal`, `no_antrean`, `jam_mulai_user`, `jam_selesai_user`, `tanggal_booking`, `status`) VALUES
(3, NULL, 2, 4, NULL, NULL, NULL, '2026-05-09', 'Selesai'),
(4, NULL, 2, 5, NULL, NULL, NULL, '2026-05-09', 'Selesai'),
(12, 'MRS-9B4C0', 3, 14, 1, '01:00:00', '01:45:00', '2026-05-23', 'Pending'),
(14, 'MRS-7E92F', 3, 13, 1, '08:00:00', '08:45:00', '2026-05-25', 'Pending'),
(15, 'MRS-C57DB', 2, 13, 2, '08:45:00', '09:30:00', '2026-05-25', 'Menunggu'),
(16, 'MRS-4A8D0', 2, 18, 1, '09:15:00', '10:00:00', '2026-06-22', 'Selesai');

-- --------------------------------------------------------

--
-- Struktur dari tabel `detail_transaksi`
--

CREATE TABLE `detail_transaksi` (
  `id_detail` int NOT NULL,
  `id_transaksi` int DEFAULT NULL,
  `id_produk` int DEFAULT NULL,
  `jumlah` int DEFAULT NULL,
  `harga_satuan` int DEFAULT NULL,
  `subtotal` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `detail_transaksi`
--

INSERT INTO `detail_transaksi` (`id_detail`, `id_transaksi`, `id_produk`, `jumlah`, `harga_satuan`, `subtotal`) VALUES
(1, 1, 3, 1, 54000, 54000),
(8, 10, 6, 1, 30450, 30450),
(9, 11, 5, 1, 31670, 31670),
(10, 11, 4, 2, 32000, 64000),
(11, 12, 7, 2, 36000, 72000),
(12, 13, 6, 2, 30450, 60900),
(13, 17, 2, 2, 32000, 64000),
(14, 18, 7, 1, 36000, 36000),
(15, 19, 7, 6, 36000, 216000),
(16, 19, 6, 5, 30450, 152250),
(17, 20, 6, 1, 30450, 30450),
(18, 21, 7, 2, 36000, 72000),
(19, 22, 4, 2, 32000, 64000),
(20, 23, 7, 1, 36000, 36000),
(21, 24, 7, 1, 36000, 36000),
(22, 25, 6, 1, 30450, 30450),
(23, 26, 5, 1, 31670, 31670),
(24, 27, 5, 1, 31670, 31670),
(25, 28, 6, 1, 30450, 30450),
(26, 29, 6, 5, 30450, 152250),
(27, 30, 6, 1, 30450, 30450),
(28, 31, 6, 1, 30450, 30450),
(29, 32, 6, 1, 30450, 30450),
(30, 33, 6, 1, 30450, 30450),
(31, 34, 6, 1, 30450, 30450),
(32, 35, 6, 1, 30450, 30450),
(33, 36, 6, 1, 30450, 30450),
(34, 37, 4, 2, 32000, 64000),
(35, 38, 6, 1, 30450, 30450),
(36, 39, 5, 1, 31670, 31670),
(37, 40, 6, 2, 30450, 60900);

-- --------------------------------------------------------

--
-- Struktur dari tabel `jadwal`
--

CREATE TABLE `jadwal` (
  `id_jadwal` int NOT NULL,
  `tanggal` date DEFAULT NULL,
  `jam_mulai` time DEFAULT NULL,
  `jam_selesai` time DEFAULT NULL,
  `konsultan` varchar(100) DEFAULT NULL,
  `keterangan` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `jadwal`
--

INSERT INTO `jadwal` (`id_jadwal`, `tanggal`, `jam_mulai`, `jam_selesai`, `konsultan`, `keterangan`) VALUES
(2, '2026-05-11', '09:00:00', NULL, 'dr. Cut Natya Rucitra', 'Selesai'),
(3, '2026-05-13', '08:30:00', NULL, 'dr. Atika Damayanti', 'Selesai'),
(4, '2026-05-13', '01:00:00', NULL, 'dr. Cut Natya Rucitra', 'Selesai'),
(5, '2026-05-18', '09:00:00', NULL, 'dr. Astri Adelia', 'Selesai'),
(12, '2026-05-25', '09:00:00', '10:30:00', 'dr. Cut Natya Rucitra', 'Selesai'),
(13, '2026-06-09', '08:00:00', '11:00:00', 'dr. Atika Damayanti', 'Selesai'),
(14, '2026-06-08', '01:00:00', '04:45:00', 'dr. Astri Adelia', 'Selesai'),
(15, '2026-06-10', '09:15:00', '10:45:00', 'dr. Atika Damayanti', 'Selesai'),
(16, '2026-05-25', '08:15:00', '09:11:00', 'dr. Cut Natya Rucitra', 'Selesai'),
(17, '2026-06-22', '08:00:00', '11:00:00', 'dr. Cut Natya Rucitra', 'Selesai'),
(18, '2026-06-23', '09:15:00', '10:45:00', 'dr. Atika Damayanti', 'Selesai');

-- --------------------------------------------------------

--
-- Struktur dari tabel `keranjang`
--

CREATE TABLE `keranjang` (
  `id_keranjang` int NOT NULL,
  `id_pelanggan` int DEFAULT NULL,
  `id_produk` int DEFAULT NULL,
  `jumlah` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `keranjang`
--

INSERT INTO `keranjang` (`id_keranjang`, `id_pelanggan`, `id_produk`, `jumlah`) VALUES
(10, 2, 4, 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `pelanggan`
--

CREATE TABLE `pelanggan` (
  `id_pelanggan` int NOT NULL,
  `nama` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `no_hp` varchar(15) DEFAULT NULL,
  `alamat` text,
  `tanggal_daftar` date DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `pelanggan`
--

INSERT INTO `pelanggan` (`id_pelanggan`, `nama`, `email`, `no_hp`, `alamat`, `tanggal_daftar`, `foto`) VALUES
(2, 'mawa', 'mawa@gmail.com', '08364712898', 'telang', '2026-05-04', NULL),
(3, 'ayu lestari', 'yules1@gmail.com', '085478369274', 'jl.telang indah gang 2 timur,kamal,bangkalan', '2026-05-24', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `pengiriman`
--

CREATE TABLE `pengiriman` (
  `id_pengiriman` int NOT NULL,
  `id_transaksi` int DEFAULT NULL,
  `jasa_kirim` varchar(50) DEFAULT NULL,
  `nomor_resi` varchar(100) DEFAULT NULL,
  `alamat_tujuan` text,
  `status_pengiriman` varchar(50) DEFAULT NULL,
  `tanggal_kirim` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `pengiriman`
--

INSERT INTO `pengiriman` (`id_pengiriman`, `id_transaksi`, `jasa_kirim`, `nomor_resi`, `alamat_tujuan`, `status_pengiriman`, `tanggal_kirim`) VALUES
(1, 17, 'JNE', 'MB-1310001', 'telang                    ', 'Selesai', '2026-06-07'),
(2, 18, NULL, NULL, 'telang                    ', 'Belum Diproses', NULL),
(3, 19, NULL, NULL, 'telang                    ', 'Belum Diproses', NULL),
(4, 20, NULL, NULL, 'telang                    ', 'Belum Diproses', NULL),
(5, 21, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(6, 22, 'JNE', 'MB-13100006', 'telang', 'Diproses', '2026-05-24'),
(7, 23, 'JNE', 'MB-13100007', 'telang', 'Diproses', '2026-05-25'),
(8, 24, 'JNE', 'MB-13100008', 'jl.telang indah gang 2 timur,kamal,bangkalan', 'Dikirim', '2026-06-07'),
(9, 25, 'JNE', 'MB-13100009', 'jl.telang indah gang 2 timur,kamal,bangkalan', 'Selesai', '2026-06-07'),
(10, 26, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(11, 27, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(12, 28, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(13, 29, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(14, 30, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(15, 31, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(16, 32, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(17, 33, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(18, 34, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(19, 35, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(20, 36, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(21, 37, NULL, NULL, 'telang', 'Belum Diproses', NULL),
(22, 38, 'JNE', 'MB-13100022', 'telang', 'Belum Diproses', '2026-06-21'),
(23, 39, 'JNE', 'MB-13100023', 'telang', 'Selesai', '2026-06-22'),
(24, 40, 'JNE', 'MB-13100024', 'telang', 'Dikirim', '2026-06-23');

-- --------------------------------------------------------

--
-- Struktur dari tabel `produk`
--

CREATE TABLE `produk` (
  `id_produk` int NOT NULL,
  `nama_produk` varchar(100) DEFAULT NULL,
  `deskripsi` text,
  `harga` int DEFAULT NULL,
  `stok` int DEFAULT NULL,
  `gambar` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `produk`
--

INSERT INTO `produk` (`id_produk`, `nama_produk`, `deskripsi`, `harga`, `stok`, `gambar`) VALUES
(2, 'facial wash wardah light+', NULL, 32000, 26, 'fw.png'),
(3, 'Serum g2g', NULL, 54000, 31, 'serum.jpg'),
(4, 'Facial Wash Glad 2Glow', 'Face Wash Glad2Glow Niacinamide Serum Facial Wash Brightening Pembersih Wajah Sabun Cuci Muka Skincare Sabun Muka Cerah Glowing', 32000, 10, '1778169396-fw g2g.png'),
(5, 'Facial Wash Emina', 'NEW Formula! Emina Brightening Face Wash with Niacinamide & Oxy Ceramide, sabun cuci muka yang cocok digunakan untuk masalah kulit kusam. Diformulasikan khusus untuk dapat membersihkan kotoran di wajah hingga ke pori, wajah tampak cerah dalam 1x bilas, dan mengontrol minyak tanpa merusak skin barrier', 31670, 19, '1778169611-fw emina.png'),
(6, 'facial wash', 'YOU Hy! Amino Glowing Gentle Cleansing Facial Wash kini hadir dengan formula baru yang mengandung Niacinamide, Vitamin C, dan Sakura. Membersihkan wajah Anda dengan lembut, membuat kulit Anda terlihat lebih cerah dan halus. \r\n\r\n\r\n\r\nManfaat:\r\n\r\nTriple Whitening: Dapat  menghambat melanin, mengurangi kekusaman, dan meningkatkan kecerahan.\r\n\r\nDual Hydration: Melembapkan dan memperkuat skin barrier untuk kulit yang bercahaya.', 30450, 4, '1778169694-fw y.o.u.jpg'),
(7, 'Facial Wash Elvicto', 'ELVICTO HYDRATING GENTLE FACE WASH\r\n\r\nElvicto Hydrating Gentle Face Wash merupakan sabun pembersih wajah yang diformulasikan secara khusus untuk kulit sensitif, tidak mengandung bahan bahan yang dapat mengiritasi kulit dapat membersihkan kulit wajah secara mendalam tanpa menghilangkan kelembapan alami kulit.', 36000, 0, '1778170711-fw elvicto.jpg');

-- --------------------------------------------------------

--
-- Struktur dari tabel `transaksi`
--

CREATE TABLE `transaksi` (
  `id_transaksi` int NOT NULL,
  `id_pelanggan` int DEFAULT NULL,
  `tgl_transaksi` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `total_harga` int DEFAULT NULL,
  `metode_pembayaran` varchar(50) DEFAULT NULL,
  `status_pembayaran` varchar(50) DEFAULT NULL,
  `kode_pembayaran` varchar(30) DEFAULT NULL,
  `bukti_pembayaran` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `transaksi`
--

INSERT INTO `transaksi` (`id_transaksi`, `id_pelanggan`, `tgl_transaksi`, `total_harga`, `metode_pembayaran`, `status_pembayaran`, `kode_pembayaran`, `bukti_pembayaran`) VALUES
(1, 2, '2026-05-06 17:00:00', 54000, 'Transfer Bank', 'Lunas', NULL, NULL),
(4, 2, '2026-05-07 15:32:25', 32000, 'Transfer Bank', 'Lunas', 'MB-613852FA', 'WhatsApp Image 2026-05-06 at 08.14.46.jpeg'),
(5, 2, '2026-05-07 15:32:25', 54000, 'Transfer Bank', 'Lunas', 'MB-4CBDA878', 'icon.png'),
(6, 2, '2026-05-07 17:01:46', 36000, 'Transfer Bank', 'Lunas', 'MB-5E3C98BB', 'fw elvicto.jpg'),
(7, 2, '2026-05-08 19:31:58', 31670, NULL, 'Lunas', 'MB-E1D06AF3', NULL),
(8, 2, '2026-05-09 14:12:46', 32000, NULL, 'Lunas', 'MB-5E8BF2A2', 'fw kahf.webp'),
(9, 2, '2026-05-09 15:20:53', 32000, 'Transfer Bank', 'Lunas', 'MB-BBC4804B', 'fw nivea men.webp'),
(10, 2, '2026-05-10 14:24:41', 30450, 'Transfer Bank', 'Lunas', 'MB-DB90374A', 'alodokter.png'),
(11, 2, '2026-05-10 14:41:15', 95670, 'Transfer Bank', 'Lunas', 'MB-200E96EF', 'foto1.png'),
(12, 2, '2026-05-18 09:27:38', 36000, 'Transfer Bank', 'Lunas', 'MB-87621C3A', NULL),
(13, 2, '2026-05-18 09:30:03', 60900, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-59FD23D2', NULL),
(17, 2, '2026-05-18 15:32:47', 64000, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-32A261D0', 'alodokter.png'),
(18, 2, '2026-05-18 15:43:04', 36000, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-DA623B4B', 'foto8.png'),
(19, 2, '2026-05-22 06:46:48', 368250, 'Transfer Bank', 'Lunas', 'MB-E610BF08', 'Mikat.png'),
(20, 2, '2026-05-22 06:49:12', 30450, 'Transfer Bank', 'Lunas', 'MB-C16F631A', 'ChatGPT Image 15 Mei 2026, 12.55.18.png'),
(21, 2, '2026-05-23 16:04:56', 72000, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-A44B87D3', NULL),
(22, 2, '2026-05-23 16:20:42', 64000, 'Transfer Bank', 'Lunas', 'MB-AEEAF5FA', '6a11d3ea54fd3.jpeg'),
(23, 2, '2026-05-25 04:43:46', 36000, 'Transfer Bank', 'Lunas', 'MB-D025A821', '6a13d39b15878.png'),
(24, 3, '2026-05-25 04:46:34', 36000, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-02E380D4', '6a13d43708baf.png'),
(25, 3, '2026-06-07 15:56:29', 30450, 'Transfer Bank', 'Lunas', 'MB-78643389', '6a2594ba776c7.png'),
(26, 2, '2026-06-14 16:34:33', 31670, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-FCC9E17A', '6a2ed8259570b.png'),
(27, 2, '2026-06-14 16:35:04', 31670, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-B4BB9A7B', NULL),
(28, 2, '2026-06-14 16:35:32', 30450, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-C4BAC13B', NULL),
(29, 2, '2026-06-14 16:38:22', 152250, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-EA8DA4EA', NULL),
(30, 2, '2026-06-14 16:38:37', 30450, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-2220EFB8', NULL),
(31, 2, '2026-06-15 09:54:15', 30450, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-1494B54F', NULL),
(32, 2, '2026-06-15 10:01:24', 30450, 'Transfer Bank', 'Dibatalkan', 'MB-CA0D7288', NULL),
(33, 2, '2026-06-15 10:08:54', 30450, 'Transfer Bank', 'Dibatalkan', 'MB-BCD3725F', NULL),
(34, 2, '2026-06-15 10:12:20', 30450, 'Transfer Bank', 'Dibatalkan', 'MB-7D342608', NULL),
(35, 2, '2026-06-15 10:15:17', 30450, 'Transfer Bank', 'Dibatalkan', 'MB-088D9E80', NULL),
(36, 2, '2026-06-15 10:18:15', 30450, 'Transfer Bank', 'Dibatalkan', 'MB-9D9956A8', NULL),
(37, 2, '2026-06-15 10:23:19', 64000, 'Transfer Bank', 'Dibatalkan', 'MB-37AE26A7', NULL),
(38, 2, '2026-06-15 10:23:39', 30450, 'Transfer Bank', 'Menunggu Verifikasi', 'MB-8BEFB879', NULL),
(39, 2, '2026-06-22 12:43:13', 31670, 'Transfer Bank', 'Lunas', 'MB-46617E38', '6a392df06a3f8.png'),
(40, 2, '2026-06-23 04:58:47', 60900, 'Transfer Bank', 'Lunas', 'MB-A93A1027', '6a3a12929af00.png');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id_user` int NOT NULL,
  `id_pelanggan` int DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','pelanggan') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id_user`, `id_pelanggan`, `email`, `password`, `role`) VALUES
(1, NULL, 'admin@gmail.com', '0192023a7bbd73250516f069df18b500', 'admin'),
(3, 2, 'mawa@gmail.com', '0a301c3ac40c711a025cda9490246797', 'pelanggan'),
(4, 3, 'yules1@gmail.com', '6b3856a68709cabf924738209c3bc8b4', 'pelanggan');

--
-- Indeks untuk tabel yang dibuang
--

--
-- Indeks untuk tabel `booking`
--
ALTER TABLE `booking`
  ADD PRIMARY KEY (`id_booking`),
  ADD KEY `id_pelanggan` (`id_pelanggan`),
  ADD KEY `id_jadwal` (`id_jadwal`);

--
-- Indeks untuk tabel `detail_transaksi`
--
ALTER TABLE `detail_transaksi`
  ADD PRIMARY KEY (`id_detail`),
  ADD KEY `id_transaksi` (`id_transaksi`),
  ADD KEY `id_produk` (`id_produk`);

--
-- Indeks untuk tabel `jadwal`
--
ALTER TABLE `jadwal`
  ADD PRIMARY KEY (`id_jadwal`);

--
-- Indeks untuk tabel `keranjang`
--
ALTER TABLE `keranjang`
  ADD PRIMARY KEY (`id_keranjang`);

--
-- Indeks untuk tabel `pelanggan`
--
ALTER TABLE `pelanggan`
  ADD PRIMARY KEY (`id_pelanggan`);

--
-- Indeks untuk tabel `pengiriman`
--
ALTER TABLE `pengiriman`
  ADD PRIMARY KEY (`id_pengiriman`),
  ADD KEY `id_transaksi` (`id_transaksi`);

--
-- Indeks untuk tabel `produk`
--
ALTER TABLE `produk`
  ADD PRIMARY KEY (`id_produk`);

--
-- Indeks untuk tabel `transaksi`
--
ALTER TABLE `transaksi`
  ADD PRIMARY KEY (`id_transaksi`),
  ADD KEY `id_pelanggan` (`id_pelanggan`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id_user`),
  ADD KEY `id_pelanggan` (`id_pelanggan`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `booking`
--
ALTER TABLE `booking`
  MODIFY `id_booking` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT untuk tabel `detail_transaksi`
--
ALTER TABLE `detail_transaksi`
  MODIFY `id_detail` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT untuk tabel `jadwal`
--
ALTER TABLE `jadwal`
  MODIFY `id_jadwal` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT untuk tabel `keranjang`
--
ALTER TABLE `keranjang`
  MODIFY `id_keranjang` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `pelanggan`
--
ALTER TABLE `pelanggan`
  MODIFY `id_pelanggan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `pengiriman`
--
ALTER TABLE `pengiriman`
  MODIFY `id_pengiriman` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT untuk tabel `produk`
--
ALTER TABLE `produk`
  MODIFY `id_produk` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `transaksi`
--
ALTER TABLE `transaksi`
  MODIFY `id_transaksi` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id_user` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `booking`
--
ALTER TABLE `booking`
  ADD CONSTRAINT `booking_ibfk_1` FOREIGN KEY (`id_pelanggan`) REFERENCES `pelanggan` (`id_pelanggan`),
  ADD CONSTRAINT `booking_ibfk_2` FOREIGN KEY (`id_jadwal`) REFERENCES `jadwal` (`id_jadwal`);

--
-- Ketidakleluasaan untuk tabel `detail_transaksi`
--
ALTER TABLE `detail_transaksi`
  ADD CONSTRAINT `detail_transaksi_ibfk_1` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`),
  ADD CONSTRAINT `detail_transaksi_ibfk_2` FOREIGN KEY (`id_produk`) REFERENCES `produk` (`id_produk`);

--
-- Ketidakleluasaan untuk tabel `pengiriman`
--
ALTER TABLE `pengiriman`
  ADD CONSTRAINT `pengiriman_ibfk_1` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`);

--
-- Ketidakleluasaan untuk tabel `transaksi`
--
ALTER TABLE `transaksi`
  ADD CONSTRAINT `transaksi_ibfk_1` FOREIGN KEY (`id_pelanggan`) REFERENCES `pelanggan` (`id_pelanggan`);

--
-- Ketidakleluasaan untuk tabel `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`id_pelanggan`) REFERENCES `pelanggan` (`id_pelanggan`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
