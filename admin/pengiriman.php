<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../includes/conn.php';

if(isset($_POST['simpan'])){
    $id_pengiriman      = (int)($_POST['id_pengiriman'] ?? 0);
    $jasa_kirim         = trim((string)($_POST['jasa_kirim'] ?? ''));
    $nomor_resi         = trim((string)($_POST['nomor_resi'] ?? ''));
    $status_pengiriman  = trim((string)($_POST['status_pengiriman'] ?? ''));

    $stmt_cek = $conn->prepare("
        SELECT p.status_pengiriman, t.status_pembayaran 
        FROM pengiriman p 
        JOIN transaksi t ON p.id_transaksi = t.id_transaksi 
        WHERE p.id_pengiriman = ?
    ");
    if ($stmt_cek) {
        $stmt_cek->bind_param("i", $id_pengiriman);
        $stmt_cek->execute();
        $data_cek = $stmt_cek->get_result()->fetch_assoc();
        $stmt_cek->close();

        if ($data_cek) {
            $pay_status = strtolower((string)($data_cek['status_pembayaran'] ?? ''));
            if ($pay_status !== 'lunas') {
                echo "<script>alert('Pengiriman hanya dapat diproses untuk pesanan yang telah lunas.'); window.location='pengiriman.php';</script>";
                exit;
            }

            if ($data_cek['status_pengiriman'] !== 'Selesai') {
                $stmt_up = $conn->prepare("
                    UPDATE pengiriman
                    SET jasa_kirim = ?,
                        nomor_resi = ?,
                        status_pengiriman = ?,
                        tanggal_kirim = NOW()
                    WHERE id_pengiriman = ?
                ");
                if ($stmt_up) {
                    $stmt_up->bind_param("sssi", $jasa_kirim, $nomor_resi, $status_pengiriman, $id_pengiriman);
                    $stmt_up->execute();
                    $stmt_up->close();
                }
            }
        }
    }

    header("Location: pengiriman.php");
    exit;
}

$data = mysqli_query($conn, "
    SELECT p.*, t.kode_pembayaran, t.total_harga, t.status_pembayaran, pl.nama
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/global.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/sidebar.css">
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

<?php require_once __DIR__ . '/../includes/sidebar_admin.php'; ?>

<div class="main">
    <div class="header">
        <h1>Data Pengiriman</h1>
        <p>Kelola pengiriman pesanan pelanggan ✨</p>
    </div>

    <?php if(mysqli_num_rows($data) > 0): ?>
        <?php while($row = mysqli_fetch_assoc($data)): 
            $is_selesai   = ($row['status_pengiriman'] == 'Selesai');
            $pay_status   = strtolower((string)($row['status_pembayaran'] ?? ''));
            $is_lunas     = ($pay_status === 'lunas');
            $is_blocked   = !$is_lunas;
            $is_disabled  = ($is_selesai || $is_blocked);
            $resi_otomatis = !empty($row['nomor_resi']) ? $row['nomor_resi'] : 'MB-131' . str_pad((string)$row['id_pengiriman'], 5, '0', STR_PAD_LEFT);
        ?>
        <div class="card">
            <div class="card-top">
                <div class="order-code">ORDER <?= $row['kode_pembayaran']; ?></div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <?php if ($is_blocked): ?>
                        <span class="status-badge" style="background:#fee2e2; color:#dc2626;">Pesanan <?= htmlspecialchars((string)$row['status_pembayaran']); ?></span>
                    <?php endif; ?>
                    <div class="status-badge"><?= $row['status_pengiriman']; ?></div>
                </div>
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
                        <select name="jasa_kirim" <?= $is_disabled ? 'disabled' : 'required'; ?>>
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
                        <select name="status_pengiriman" <?= $is_disabled ? 'disabled' : ''; ?>>
                            <option value="Belum Diproses" <?= $row['status_pengiriman'] == 'Belum Diproses' ? 'selected' : ''; ?>>Belum Diproses</option>
                            <option value="Diproses" <?= $row['status_pengiriman'] == 'Diproses' ? 'selected' : ''; ?>>Diproses</option>
                            <option value="Dikirim" <?= $row['status_pengiriman'] == 'Dikirim' ? 'selected' : ''; ?>>Dikirim</option>
                            <option value="Selesai" <?= $row['status_pengiriman'] == 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                        </select>
                    </div>
                </div>
                
                <?php if ($is_blocked): ?>
                    <button type="button" class="btn-simpan" style="background:#f3f4f6; color:#9ca3af; cursor:not-allowed;" disabled>Pesanan <?= htmlspecialchars((string)$row['status_pembayaran']); ?> (Pengiriman Dinonaktifkan)</button>
                <?php elseif (!$is_selesai): ?>
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