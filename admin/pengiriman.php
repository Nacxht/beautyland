<?php
session_start();
include '../includes/conn.php';

// ======================================
// UPDATE PENGIRIMAN
// ======================================
if(isset($_POST['simpan'])){
    $id_pengiriman      = $_POST['id_pengiriman'];
    $jasa_kirim         = $_POST['jasa_kirim'];
    $nomor_resi         = $_POST['nomor_resi'];
    $status_pengiriman  = $_POST['status_pengiriman'];

    // PROTEKSI: Cek status di database sebelum update
    $cek_status = mysqli_query($conn, "SELECT status_pengiriman FROM pengiriman WHERE id_pengiriman = '$id_pengiriman'");
    $data_cek = mysqli_fetch_assoc($cek_status);

    if ($data_cek['status_pengiriman'] !== 'Selesai') {
        mysqli_query($conn, "
            UPDATE pengiriman
            SET jasa_kirim='$jasa_kirim',
                nomor_resi='$nomor_resi',
                status_pengiriman='$status_pengiriman',
                tanggal_kirim=NOW()
            WHERE id_pengiriman='$id_pengiriman'
        ");
    }

    header("Location: pengiriman.php");
    exit;
}

$data = mysqli_query($conn, "
    SELECT p.*, t.kode_pembayaran, t.total_harga, pl.nama
    FROM pengiriman p
    JOIN transaksi t ON p.id_transaksi = t.id_transaksi
    JOIN pelanggan pl ON t.id_pelanggan = pl.id_pelanggan
    ORDER BY p.id_pengiriman DESC
");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Pengiriman</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <style>
        body{ font-family:'DM Sans',sans-serif; background:#fbf6f8; display:flex; }
        .main{ flex:1; padding:40px; height:100vh; overflow-y:auto; }
        .header{ margin-bottom:35px; }
        .header h1{ font-family:'Playfair Display',serif; font-size:34px; color:#1a0a12; margin-bottom:8px; }
        .header p{ color:#8a6070; font-size:14px; }
        .card{ background:white; border-radius:24px; padding:28px; margin-bottom:25px; border:1px solid rgba(240,221,229,0.5); box-shadow:0 4px 15px rgba(0,0,0,0.03); }
        .card-top{ display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .order-code{ font-size:22px; font-weight:700; color:#1a0a12; }
        .status-badge{ padding:10px 16px; border-radius:12px; font-size:13px; font-weight:700; background:#fff0f5; color:#ff4f81; }
        .info-box{ display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:25px; }
        .info-item{ background:#fff8fb; padding:18px; border-radius:16px; }
        .info-item span{ display:block; font-size:13px; color:#8a6070; margin-bottom:6px; }
        .info-item strong{ color:#1a0a12; }
        .form-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
        .input-group label{ display:block; margin-bottom:8px; font-size:14px; font-weight:600; color:#1a0a12; }
        .input-group input, .input-group select{ width:100%; padding:14px; border-radius:14px; border:1px solid #f0dde5; outline:none; font-family:'DM Sans',sans-serif; font-size:14px; }
        .btn-simpan{ width:100%; margin-top:25px; padding:15px; border:none; border-radius:16px; background:#ff4f81; color:white; font-size:15px; font-weight:700; cursor:pointer; }
    </style>
</head>
<body>

<?php include '../includes/sidebar_admin.php'; ?>

<div class="main">
    <div class="header">
        <h1>Data Pengiriman</h1>
        <p>Kelola pengiriman pesanan pelanggan ✨</p>
    </div>

    <?php if(mysqli_num_rows($data) > 0): ?>
        <?php while($row = mysqli_fetch_assoc($data)): 
            $is_selesai = ($row['status_pengiriman'] == 'Selesai');
            $resi_otomatis = !empty($row['nomor_resi']) ? $row['nomor_resi'] : 'MB-131' . str_pad($row['id_pengiriman'], 5, '0', STR_PAD_LEFT);
        ?>
        <div class="card">
            <div class="card-top">
                <div class="order-code">ORDER <?= $row['kode_pembayaran']; ?></div>
                <div class="status-badge"><?= $row['status_pengiriman']; ?></div>
            </div>

            <div class="info-box">
                <div class="info-item"><span>Nama Pelanggan</span><strong><?= $row['nama']; ?></strong></div>
                <div class="info-item"><span>Total Belanja</span><strong>Rp <?= number_format($row['total_harga'],0,',','.'); ?></strong></div>
                <div class="info-item" style="grid-column:1/-1;"><span>Alamat Tujuan</span><strong><?= $row['alamat_tujuan']; ?></strong></div>
            </div>

            <form method="POST">
                <input type="hidden" name="id_pengiriman" value="<?= $row['id_pengiriman']; ?>">
                <div class="form-grid">
                    <div class="input-group">
                        <label>Jasa Kirim</label>
                        <select name="jasa_kirim" <?= $is_selesai ? 'disabled' : 'required'; ?>>
                            <option value="JNE" <?= $row['jasa_kirim'] == 'JNE' ? 'selected' : ''; ?>>JNE</option>
                            <option value="J&T" <?= $row['jasa_kirim'] == 'J&T' ? 'selected' : ''; ?>>J&T</option>
                            <option value="SiCepat" <?= $row['jasa_kirim'] == 'SiCepat' ? 'selected' : ''; ?>>SiCepat</option>
                        </select>
                    </div>

                    <div class="input-group">
                        <label>Nomor Resi</label>
                        <input type="text" name="nomor_resi" value="<?= $resi_otomatis; ?>" readonly style="background:#f9f9f9; cursor:not-allowed;">
                    </div>

                    <div class="input-group">
                        <label>Status Pengiriman</label>
                        <select name="status_pengiriman" <?= $is_selesai ? 'disabled' : ''; ?>>
                            <option value="Belum Diproses" <?= $row['status_pengiriman'] == 'Belum Diproses' ? 'selected' : ''; ?>>Belum Diproses</option>
                            <option value="Diproses" <?= $row['status_pengiriman'] == 'Diproses' ? 'selected' : ''; ?>>Diproses</option>
                            <option value="Dikirim" <?= $row['status_pengiriman'] == 'Dikirim' ? 'selected' : ''; ?>>Dikirim</option>
                            <option value="Selesai" <?= $row['status_pengiriman'] == 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                        </select>
                    </div>
                </div>
                
                <?php if (!$is_selesai): ?>
                    <button type="submit" name="simpan" class="btn-simpan">Simpan Pengiriman</button>
                <?php else: ?>
                    <button type="button" class="btn-simpan" style="background:#ccc; cursor:not-allowed;" disabled>Pesanan Sudah Selesai</button>
                <?php endif; ?>
            </form>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty"><h3>Belum ada data pengiriman 😢</h3></div>
    <?php endif; ?>
</div>

</body>
</html>