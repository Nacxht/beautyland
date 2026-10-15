<?php
session_start();
include "../includes/conn.php";

// Pastikan user sudah login
if (!isset($_SESSION['id_pelanggan'])) {
    header("Location: ../login.php");
    exit;
}

// Tambahkan di atas kode yang sudah ada:
$id_produk = $_POST['id_produk'];
$cek_stok = mysqli_query($conn, "SELECT stok FROM produk WHERE id_produk='$id_produk'");
$p = mysqli_fetch_assoc($cek_stok);

if($p['stok'] <= 0) {
    echo "<script>alert('Produk habis!'); window.location='keranjang.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id_produk'])) {
    $id_pelanggan = $_SESSION['id_pelanggan'];
    $id_produk = $_POST['id_produk'];

    // Cek apakah produk ini sudah ada di keranjang pelanggan tersebut
    $cek = mysqli_query($conn, "SELECT * FROM keranjang WHERE id_pelanggan='$id_pelanggan' AND id_produk='$id_produk'");

    if (mysqli_num_rows($cek) > 0) {
        // Kalau sudah ada, tambahkan jumlah kuantitasnya saja (+1)
        mysqli_query($conn, "UPDATE keranjang SET jumlah = jumlah + 1 WHERE id_pelanggan='$id_pelanggan' AND id_produk='$id_produk'");
    } else {
        // Kalau belum ada, insert sebagai barang baru di keranjang
        mysqli_query($conn, "INSERT INTO keranjang (id_pelanggan, id_produk, jumlah) VALUES ('$id_pelanggan', '$id_produk', 1)");
    }

    // Arahkan otomatis ke halaman keranjang
    header("Location: keranjang.php");
    exit;
} else {
    header("Location: produk_user.php");
    exit;
}
?>