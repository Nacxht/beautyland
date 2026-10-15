<?php
session_start();
include '../includes/conn.php';

if(!isset($_SESSION['id_pelanggan'])){
    header("Location: ../login.php");
    exit;
}

$id_pelanggan = $_SESSION['id_pelanggan'];
$total = $_POST['total_harga'] ?? 0;
$metode = "Transfer Bank";

if($total <= 0){
    die("Checkout gagal");
}

// ======================================
// VALIDASI STOK SEBELUM TRANSAKSI (PENTING!)
// ======================================
foreach($_POST['id_produk'] as $key => $id_produk){
    $jumlah_dibeli = $_POST['jumlah'][$key];
    
    $query_cek = mysqli_query($conn, "SELECT stok, nama_produk FROM produk WHERE id_produk='$id_produk'");
    $data_produk = mysqli_fetch_assoc($query_cek);
    
    if($data_produk['stok'] < $jumlah_dibeli){
        echo "<script>alert('Mohon maaf, stok untuk produk ".$data_produk['nama_produk']." tidak mencukupi.'); window.history.back();</script>";
        exit;
    }
}

// ======================================
// BUAT KODE PEMBAYARAN & SIMPAN TRANSAKSI
// ======================================
$kode = "MB-" . strtoupper(substr(md5(rand()),0,8));

mysqli_query($conn, "INSERT INTO transaksi (id_pelanggan, total_harga, metode_pembayaran, kode_pembayaran, status_pembayaran) 
VALUES ('$id_pelanggan', '$total', '$metode', '$kode', 'Menunggu Verifikasi')");

$id_transaksi = mysqli_insert_id($conn);

// ======================================
// SIMPAN DATA PENGIRIMAN
// ======================================
$alamat = $_POST['alamat'] ?? 'Alamat belum diisi';
mysqli_query($conn, "INSERT INTO pengiriman (id_transaksi, alamat_tujuan, status_pengiriman) VALUES ('$id_transaksi', '$alamat', 'Belum Diproses')");

// ======================================
// DETAIL TRANSAKSI & KURANGI STOK
// ======================================
foreach($_POST['id_produk'] as $key => $id_produk){
    $jumlah = $_POST['jumlah'][$key];
    $harga = $_POST['harga_satuan'][$key];
    $subtotal = $jumlah * $harga;

    mysqli_query($conn, "INSERT INTO detail_transaksi (id_transaksi, id_produk, jumlah, harga_satuan, subtotal) VALUES ('$id_transaksi', '$id_produk', '$jumlah', '$harga', '$subtotal')");

    // UPDATE STOK
    mysqli_query($conn, "UPDATE produk SET stok = stok - $jumlah WHERE id_produk='$id_produk'");

    // HAPUS DARI KERANJANG
    mysqli_query($conn, "DELETE FROM keranjang WHERE id_pelanggan='$id_pelanggan' AND id_produk='$id_produk'");
}

header("Location: pembayaran.php?id=$id_transaksi");
exit;
?>