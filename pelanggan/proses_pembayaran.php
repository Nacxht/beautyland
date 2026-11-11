<?php
session_start();
require_once __DIR__ . '/../includes/conn.php';

if (isset($_POST['action']) && $_POST['action'] === 'cancel') {
    if (isset($_SESSION['pending_order'])) {
        unset($_SESSION['pending_order']);
    }
    if (!empty($_POST['id_transaksi'])) {
        $id_cancel = (int)$_POST['id_transaksi'];
        mysqli_query($conn, "UPDATE transaksi SET status_pembayaran='Dibatalkan' WHERE id_transaksi='$id_cancel'");
    }
    header('Location: produk_user.php');
    exit;
}

if (!isset($_SESSION['id_pelanggan'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_FILES['bukti'])) {
    header("Location: produk_user.php");
    exit;
}

$nama_file = $_FILES['bukti']['name'];
$tmp       = $_FILES['bukti']['tmp_name'];
$error     = $_FILES['bukti']['error'];

if ($error === 0) {
    $ekstensi = strtolower(pathinfo($nama_file, PATHINFO_EXTENSION));
    $ekstensi_diperbolehkan = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ekstensi, $ekstensi_diperbolehkan)) {
        echo "<script>alert('Format file tidak didukung! Gunakan JPG, PNG, atau WEBP.'); window.history.back();</script>";
        exit;
    }

    $nama_file_baru = uniqid() . '.' . $ekstensi;
    $target_dir = __DIR__ . '/../assets/bukti/';
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $upload = move_uploaded_file($tmp, $target_dir . $nama_file_baru);

    if ($upload) {
        if (isset($_SESSION['pending_order'])) {
            $order = $_SESSION['pending_order'];
            $id_pelanggan = $order['id_pelanggan'];
            $total_harga = $order['total_harga'];
            $metode = $order['metode_pembayaran'];
            $kode = $order['kode_pembayaran'];
            $alamat = $order['alamat'];
            $items = $order['items'];

            foreach ($items as $item) {
                $id_prod = $item['id_produk'];
                $qty = $item['jumlah'];
                $stmt_cek = $conn->prepare("SELECT stok, nama_produk FROM produk WHERE id_produk = ?");
                $stmt_cek->bind_param("i", $id_prod);
                $stmt_cek->execute();
                $data_p = $stmt_cek->get_result()->fetch_assoc();
                if (!$data_p || $data_p['stok'] < $qty) {
                    echo "<script>alert('Mohon maaf, stok untuk produk ".($data_p['nama_produk'] ?? 'ini')." tidak mencukupi.'); window.location='keranjang.php';</script>";
                    exit;
                }
            }

            $status = 'Menunggu Verifikasi';
            $stmt_trx = $conn->prepare("INSERT INTO transaksi (id_pelanggan, total_harga, metode_pembayaran, kode_pembayaran, status_pembayaran, bukti_pembayaran) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_trx->bind_param("iissss", $id_pelanggan, $total_harga, $metode, $kode, $status, $nama_file_baru);
            $stmt_trx->execute();
            $id_transaksi = $conn->insert_id;

            $status_pengiriman = 'Belum Diproses';
            $stmt_ship = $conn->prepare("INSERT INTO pengiriman (id_transaksi, alamat_tujuan, status_pengiriman) VALUES (?, ?, ?)");
            $stmt_ship->bind_param("iss", $id_transaksi, $alamat, $status_pengiriman);
            $stmt_ship->execute();

            $stmt_detail = $conn->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, jumlah, harga_satuan, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmt_stok = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id_produk = ?");
            $stmt_cart = $conn->prepare("DELETE FROM keranjang WHERE id_pelanggan = ? AND id_produk = ?");

            foreach ($items as $item) {
                $id_prod = $item['id_produk'];
                $qty = $item['jumlah'];
                $price = $item['harga_satuan'];
                $sub = $item['subtotal'];

                $stmt_detail->bind_param("iiiii", $id_transaksi, $id_prod, $qty, $price, $sub);
                $stmt_detail->execute();

                $stmt_stok->bind_param("ii", $qty, $id_prod);
                $stmt_stok->execute();

                $stmt_cart->bind_param("ii", $id_pelanggan, $id_prod);
                $stmt_cart->execute();
            }

            unset($_SESSION['pending_order']);
        } elseif (!empty($_POST['id_transaksi'])) {
            $id = (int)$_POST['id_transaksi'];
            $status = 'Menunggu Verifikasi';
            $stmt_up = $conn->prepare("UPDATE transaksi SET bukti_pembayaran = ?, status_pembayaran = ? WHERE id_transaksi = ?");
            $stmt_up->bind_param("ssi", $nama_file_baru, $status, $id);
            $stmt_up->execute();
        }

        echo "<script>alert('Bukti pembayaran berhasil dikirim!'); window.location='pesanan_user.php';</script>";
        exit;
    } else {
        echo "<script>alert('Gagal memindahkan file ke folder assets/bukti/. Pastikan folder sudah ada!'); window.history.back();</script>";
        exit;
    }
} else {
    echo "<script>alert('Terjadi kesalahan saat mengunggah gambar.'); window.history.back();</script>";
    exit;
}
