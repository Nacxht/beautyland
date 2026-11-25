<?php
$current = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
  <h2>BeautyLand</h2>
  <ul class="menu">
    <li><a href="<?= BASE_URL ?>/admin/dashboard_admin.php" <?= $current == 'dashboard_admin.php' ? 'class="active"' : '' ?>>Dashboard</a></li>
    <li><a href="<?= BASE_URL ?>/admin/produk.php" <?= $current == 'produk.php' ? 'class="active"' : '' ?>>Produk</a></li>
    <li><a href="<?= BASE_URL ?>/admin/pesanan.php" <?= $current == 'pesanan.php' ? 'class="active"' : '' ?>>Pesanan</a></li>
    <li><a href="<?= BASE_URL ?>/admin/konsultasi.php" <?= $current == 'konsultasi.php' ? 'class="active"' : '' ?>>Konsultasi</a></li>
    <li><a href="<?= BASE_URL ?>/admin/pengiriman.php" <?= $current == 'pengiriman.php' ? 'class="active"' : '' ?>>Pengiriman</a></li>
    <li><a href="<?= BASE_URL ?>/admin/laporan.php" <?= $current == 'laporan.php' ? 'class="active"' : '' ?>>Laporan</a></li>
    <li><a href="<?= BASE_URL ?>/login.php" <?= $current == '../login.php' ? 'class="active"' : '' ?>>Logout</a></li>
  </ul>
</div>