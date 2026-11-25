<?php
session_start();
require_once __DIR__ . '/../includes/conn.php';

if(!isset($_SESSION['id_pelanggan'])){
    header("Location: ../login.php");
    exit;
}

$id_pelanggan = $_SESSION['id_pelanggan'];
$total = $_POST['total_harga'] ?? 0;
$metode = "Transfer Bank";

if($total <= 0 || !isset($_POST['id_produk']) || !is_array($_POST['id_produk'])){
    die("Checkout gagal");
}

foreach($_POST['id_produk'] as $key => $id_produk){
    $jumlah_dibeli = $_POST['jumlah'][$key];
    
    $query_cek = mysqli_query($conn, "SELECT stok, nama_produk FROM produk WHERE id_produk='$id_produk'");
    $data_produk = mysqli_fetch_assoc($query_cek);
    
    if(!$data_produk || $data_produk['stok'] < $jumlah_dibeli){
        $nama_prod = $data_produk ? $data_produk['nama_produk'] : 'Produk';
        echo "<script>alert('Mohon maaf, stok untuk produk ".$nama_prod." tidak mencukupi.'); window.history.back();</script>";
        exit;
    }
}

$kode = "MB-" . strtoupper(substr(md5(rand()),0,8));
$alamat = $_POST['alamat'] ?? 'Alamat belum diisi';

$items = [];
foreach($_POST['id_produk'] as $key => $id_produk){
    $jumlah = (int)$_POST['jumlah'][$key];
    $harga = (int)$_POST['harga_satuan'][$key];
    $subtotal = $jumlah * $harga;
    $items[] = [
        'id_produk'    => (int)$id_produk,
        'jumlah'       => $jumlah,
        'harga_satuan' => $harga,
        'subtotal'     => $subtotal
    ];
}

$_SESSION['pending_order'] = [
    'id_pelanggan'      => $id_pelanggan,
    'total_harga'       => (int)$total,
    'metode_pembayaran' => $metode,
    'kode_pembayaran'   => $kode,
    'alamat'            => $alamat,
    'items'             => $items
];

header("Location: pembayaran.php");
exit;