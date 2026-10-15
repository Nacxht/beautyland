<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'pelanggan') {
    header("Location: ../login.php");
    exit;
}
include '../includes/conn.php';

if (!isset($_GET['id'])) {
    header("Location: booking_konsul.php");
    exit;
}

$id_booking = $_GET['id'];

// Mengambil data booking, jadwal, dan nama pelanggan
$query = mysqli_query($conn, "
    SELECT b.*, j.tanggal, j.konsultan, p.nama
    FROM booking b
    JOIN jadwal j ON b.id_jadwal = j.id_jadwal
    JOIN pelanggan p ON b.id_pelanggan = p.id_pelanggan
    WHERE b.id_booking = '$id_booking' AND b.status != 'Batal'
");
$data = mysqli_fetch_assoc($query);

if (!$data) {
    header("Location: booking_konsul.php");
    exit;
}

$print_otomatis = false;
if (isset($_POST['aksi_oke'])) {
    $update = mysqli_query($conn, "UPDATE booking SET status = 'Menunggu' WHERE id_booking = '$id_booking'");
    if ($update) {
        $print_otomatis = true;
        $data['status'] = 'Menunggu';
    }
}

if (isset($_POST['aksi_batal'])) {
    mysqli_query($conn, "DELETE FROM booking WHERE id_booking = '$id_booking'");
    header("Location: booking_konsul.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Antrean Booking</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <style>
        body { font-family: 'DM Sans', sans-serif; background-color: #fbf6f8; display: flex; margin: 0; }
        .main { flex: 1; padding: 40px; height: 100vh; display: flex; align-items: center; justify-content: center; box-sizing: border-box; }
        .invoice-card { background: white; border-radius: 24px; padding: 40px; width: 100%; max-width: 480px; box-shadow: 0 10px 30px rgba(26,10,18,0.05); text-align: center; box-sizing: border-box; }
        .invoice-card h2 { font-family: 'Playfair Display', serif; color: #1a0a12; margin: 0 0 10px 0; font-size: 24px; }
        .invoice-card p { color: #8a6070; font-size: 14px; margin: 0 0 25px 0; }
        .code-container { background: #fff0f5; border: 2px dashed #ff4f87; border-radius: 16px; padding: 15px; margin-bottom: 25px; }
        .code-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #ff4f87; font-weight: 700; margin-bottom: 3px; }
        .code-value { font-family: 'Courier New', monospace; font-size: 28px; font-weight: 700; color: #1a0a12; }
        .detail-list { text-align: left; background: #fcf8fa; padding: 20px; border-radius: 14px; margin-bottom: 20px; }
        .detail-item { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; }
        .detail-item:last-child { margin-bottom: 0; }
        .detail-label { color: #8a6070; }
        .detail-value { font-weight: 600; color: #1a0a12; }
        .price-highlight { color: #10b981; font-weight: 800; }
        .payment-info { font-size: 11px; color: #ff4f87; font-weight: 600; text-align: center; margin-bottom: 20px; border-top: 1px solid #f0e6eb; padding-top: 10px; }
        .action-buttons { display: flex; flex-direction: column; gap: 12px; }
        .row-buttons { display: flex; gap: 15px; }
        .btn { padding: 14px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor:pointer; border: none; transition: 0.2s; text-align: center; text-decoration: none; display: inline-block; }
        .btn-oke { background: #ff4f87; color: white; flex: 1; }
        .btn-batal { background: #f0e6eb; color: #8a6070; flex: 1; }
        .success-banner { background: #eaffea; color: #10b981; padding: 10px; border-radius: 10px; font-weight: 600; font-size: 13px; margin-bottom: 20px; }
        
        @media print {
            body { background: white; }
            aside, .sidebar, #sidebar, form, .action-buttons, .success-banner { display: none !important; } 
            .main { padding: 0; }
            .invoice-card { box-shadow: none; border: 1px solid #f0e6eb; margin: 0 auto; }
        }
    </style>
</head>
<body>
  
  <?php include '../includes/sidebar_user.php'; ?>
  
  <div class="main">
      <div class="invoice-card">
          <h2>MAARS Beauty</h2>
          <p>Bukti Reservasi Antrean Konsultasi</p>
          
          <?php if ($data['status'] == 'Menunggu') { ?>
              <div class="success-banner">✓ Antrean Sukses Dikonfirmasi!</div>
          <?php } ?>

          <div class="code-container">
              <div class="code-label">Kode Bukti Pertemuan</div>
              <div class="code-value">#<?= $data['kode_booking']; ?></div>
          </div>
          
          <div class="detail-list">
              <div class="detail-item">
                  <span class="detail-label">Nama</span>
                  <span class="detail-value"><?= htmlspecialchars($data['nama']); ?></span>
              </div>
              <div class="detail-item">
                  <span class="detail-label">Dokter</span>
                  <span class="detail-value"><?= htmlspecialchars($data['konsultan']); ?></span>
              </div>
              <div class="detail-item">
                  <span class="detail-label">Waktu</span>
                  <span class="detail-value"><?= date('d M Y', strtotime($data['tanggal'])); ?></span>
              </div>
              <div class="detail-item" style="margin-top: 10px; border-top: 1px solid #eee; padding-top: 10px;">
                  <span class="detail-label">Estimasi Biaya</span>
                  <span class="detail-value price-highlight">Rp 50.000</span>
              </div>
          </div>
          
          <div class="payment-info">
              *Pembayaran dilakukan di klinik (Cash/QRIS) saat sesi konsultasi selesai.
          </div>
          
          <form method="POST">
              <div class="action-buttons">
                  <?php if ($data['status'] == 'Menunggu') { ?>
                      <button type="button" onclick="window.print()" class="btn btn-oke">🖨️ Cetak Bukti</button>
                      <a href="riwayat_konsul.php" class="btn btn-batal">Kembali ke Riwayat</a>
                  <?php } else { ?>
                      <div class="row-buttons">
                          <button type="submit" name="aksi_batal" class="btn btn-batal">Batal</button>
                          <button type="submit" name="aksi_oke" class="btn btn-oke">Konfirmasi Booking</button>
                      </div>
                  <?php } ?>
              </div>
          </form>
      </div>
  </div>

  <script>
  <?php if ($print_otomatis) { ?>
      window.addEventListener('DOMContentLoaded', () => setTimeout(() => window.print(), 500));
  <?php } ?>
  </script>
</body>
</html>