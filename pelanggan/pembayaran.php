<?php
session_start();
require_once __DIR__ . '/../includes/conn.php';

if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'pelanggan') {
    header("Location: ../login.php");
    exit;
}

if (isset($_SESSION['pending_order'])) {
    $total_harga = $_SESSION['pending_order']['total_harga'];
    $kode_pembayaran = $_SESSION['pending_order']['kode_pembayaran'];
    $id_transaksi = 0;
} elseif (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $query = mysqli_query($conn, "SELECT * FROM transaksi WHERE id_transaksi='$id'");
    $data = mysqli_fetch_assoc($query);
    if (!$data) {
        header("Location: pesanan_user.php");
        exit;
    }
    $total_harga = $data['total_harga'];
    $kode_pembayaran = $data['kode_pembayaran'];
    $id_transaksi = $data['id_transaksi'];
} else {
    header("Location: keranjang.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pembayaran</title>

<style>

body{
    font-family:Arial;
    background:#fbf6f8;
    padding:40px;
}

.card{
    max-width:500px;
    margin:auto;
    background:white;
    padding:30px;
    border-radius:20px;
}

h2{
    color:#ff4f81;
}

.kode{
    background:#fff0f5;
    padding:15px;
    border-radius:12px;
    text-align:center;
    font-size:22px;
    font-weight:bold;
    margin:20px 0;
    color:#ff4f81;
}

.btn{
    width:100%;
    background:#ff4f81;
    color:white;
    padding:14px;
    border:none;
    border-radius:12px;
    margin-top:20px;
    cursor:pointer;
}

input[type=file]{
    width:100%;
    margin-top:15px;
}

.qris{
    width:220px;
    display:block;
    margin:auto;
}

</style>
</head>

<body>

<div class="card">

    <h2>Pembayaran Pesanan</h2>

    <p><strong>Total:</strong></p>

    <h1>
        Rp <?= number_format($total_harga, 0, ',', '.'); ?>
    </h1>

    <p>Kode Pembayaran:</p>

    <div class="kode">
        <?= htmlspecialchars($kode_pembayaran); ?>
    </div>

    <p>
        Silakan transfer sesuai total pembayaran.
    </p>

    <form action="proses_pembayaran.php" 
          method="POST" 
          enctype="multipart/form-data">

        <input type="hidden" 
               name="id_transaksi"
               value="<?= $id_transaksi; ?>">

        <label>Upload Bukti Pembayaran</label>

        <input type="file" 
               name="bukti"
               required>

        <button type="submit" class="btn">
            Kirim Bukti Pembayaran
        </button>

    </form>

    <form action="proses_pembayaran.php" method="POST" onsubmit="return confirm('Yakin membatalkan transaksi?');">
        <input type="hidden" name="id_transaksi" value="<?= $id_transaksi; ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn" style="background:#ff7aa3; margin-top:10px;">Batal</button>
    </form>
</div>

</body>
</html>