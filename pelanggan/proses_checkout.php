<?php
session_start();
require_once __DIR__ . '/../includes/conn.php';

if(!isset($_SESSION['id_pelanggan'])){
    header("Location: ../login.php");
    exit;
}

$id_pelanggan = (int)$_SESSION['id_pelanggan'];
$metode = "Transfer Bank";

if(!isset($_POST['id_produk']) || !is_array($_POST['id_produk']) || count($_POST['id_produk']) === 0){
    die("Checkout gagal: tidak ada produk yang dipilih.");
}

if(!isset($_POST['jumlah']) || !is_array($_POST['jumlah'])){
    die("Checkout gagal: data jumlah tidak valid.");
}

$id_produk_list = [];
$jumlah_list    = [];

foreach($_POST['id_produk'] as $key => $raw_id){
    $id = (int)$raw_id;
    $qty = isset($_POST['jumlah'][$key]) ? (int)$_POST['jumlah'][$key] : 0;

    if($id <= 0){
        die("Checkout gagal: ID produk tidak valid.");
    }
    if($qty <= 0){
        die("Checkout gagal: jumlah produk harus minimal 1.");
    }

    $id_produk_list[] = $id;
    $jumlah_list[]    = $qty;
}

$placeholders = implode(',', array_fill(0, count($id_produk_list), '?'));
$types        = str_repeat('i', count($id_produk_list));

$stmt = $conn->prepare("SELECT id_produk, nama_produk, harga, stok FROM produk WHERE id_produk IN ($placeholders)");
$stmt->bind_param($types, ...$id_produk_list);
$stmt->execute();
$result = $stmt->get_result();

$produk_map = [];
while($row = $result->fetch_assoc()){
    $produk_map[(int)$row['id_produk']] = $row;
}
$stmt->close();

$items       = [];
$total_harga = 0;

foreach($id_produk_list as $idx => $id_produk){
    $qty = $jumlah_list[$idx];

    if(!isset($produk_map[$id_produk])){
        die("Checkout gagal: produk dengan ID $id_produk tidak ditemukan.");
    }

    $produk = $produk_map[$id_produk];

    if($produk['stok'] < $qty){
        echo "<script>alert('Mohon maaf, stok untuk produk ".addslashes($produk['nama_produk'])." tidak mencukupi (sisa stok: ".$produk['stok'].").'); window.history.back();</script>";
        exit;
    }

    $harga_satuan = (int)$produk['harga'];
    $subtotal     = $qty * $harga_satuan;

    $items[] = [
        'id_produk'    => $id_produk,
        'jumlah'       => $qty,
        'harga_satuan' => $harga_satuan,
        'subtotal'     => $subtotal
    ];

    $total_harga += $subtotal;
}

$kode   = "MB-" . strtoupper(substr(md5(rand()), 0, 8));
$alamat = $_POST['alamat'] ?? 'Alamat belum diisi';
$idempotency_key = bin2hex(random_bytes(16));

$_SESSION['pending_order'] = [
    'id_pelanggan'             => $id_pelanggan,
    'total_harga'              => $total_harga,
    'metode_pembayaran'        => $metode,
    'kode_pembayaran'          => $kode,
    'checkout_idempotency_key' => $idempotency_key,
    'alamat'                   => $alamat,
    'items'                    => $items
];

header("Location: pembayaran.php");
exit;