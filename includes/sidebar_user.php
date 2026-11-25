<?php
$current = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
  <h2>BEAUTYLAND</h2>
  <ul class="menu">
    <li><a href="<?= BASE_URL ?>/pelanggan/dashboard.php" <?= $current == 'dashboard.php' ? 'class="active"' : '' ?>>Dashboard</a></li>
    <li><a href="<?= BASE_URL ?>/pelanggan/produk_user.php" <?= $current == 'produk_user.php' ? 'class="active"' : '' ?>>Produk</a></li>
    <li><a href="<?= BASE_URL ?>/pelanggan/keranjang.php" <?= $current == 'keranjang.php' ? 'class="active"' : '' ?>>Keranjang</a></li>
    <li><a href="<?= BASE_URL ?>/pelanggan/pesanan_user.php" <?= $current == 'pesanan_user.php' ? 'class="active"' : '' ?>>Pesanan Saya</a></li>
    <li><a href="<?= BASE_URL ?>/pelanggan/booking_konsul.php" <?= $current == 'booking_konsul.php' ? 'class="active"' : '' ?>>Booking Konsultasi</a></li>
    <li><a href="<?= BASE_URL ?>/pelanggan/riwayat_konsul.php" <?= $current == 'riwayat_konsul.php' ? 'class="active"' : '' ?>>Riwayat Konsultasi</a></li>
    <li><a href="<?= BASE_URL ?>/pelanggan/pengiriman_user.php" <?= $current == 'pengiriman_user.php' ? 'class="active"' : '' ?>>Pengiriman</a></li>
    <li><a href="<?= BASE_URL ?>/login.php" <?= $current == '../login.php' ? 'class="active"' : '' ?>>Logout</a></li>
  </ul>
</div>