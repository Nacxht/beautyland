<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'pelanggan') {
    header("Location: ../login.php");
    exit;
}

include '../includes/conn.php';
$id_pelanggan = $_SESSION['id_pelanggan'];

// Ambil data riwayat booking milik pelanggan yang sedang login beserta data jadwalnya
$query = mysqli_query($conn, "
    SELECT b.*, j.tanggal, j.konsultan
    FROM booking b
    JOIN jadwal j ON b.id_jadwal = j.id_jadwal
    WHERE b.id_pelanggan = '$id_pelanggan'
    ORDER BY b.id_booking DESC
");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Konsultasi - MAARS Beauty</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #fbf6f8; display: flex; margin: 0; }
        .main { flex: 1; padding: 40px; height: 100vh; overflow-y: auto; box-sizing: border-box; }
        .header-text h1 { font-family: 'Playfair Display', serif; font-size: 30px; color: #1a0a12; margin-bottom: 5px; }
        .header-text p { color: #8a6070; margin-bottom: 30px; }
        .card-box { background: white; border-radius: 20px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 15px 10px; text-align: left; font-size: 12px; color: #999; text-transform: uppercase; border-bottom: 2px solid #f0f0f0; }
        td { padding: 18px 10px; border-bottom: 1px solid #f5f5f5; font-size: 14px; color: #1a0a12; }
        tr:hover { background: #fcf8fa; }
        .badge { padding: 5px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-menunggu { background: #fff7ed; color: #c2410c; }
        .status-selesai { background: #f0fdf4; color: #15803d; }
        .status-batal { background: #fef2f2; color: #b91c1c; }
        .status-pending { background: #f3e8ff; color: #6b21a8; }
        .empty { text-align: center; color: #999; padding: 40px; font-style: italic; }
    </style>
</head>
<body>

<?php include '../includes/sidebar_user.php'; ?>

<div class="main">
    <div class="header-text">
        <h1>Riwayat Konsultasi</h1>
        <p>Daftar seluruh riwayat pengajuan konsultasi kecantikan Anda ✨</p>
    </div>

    <div class="card-box">
        <table>
            <thead>
                <tr>
                    <th width="15%">Kode Bukti</th>
                    <th width="15%">Tanggal Temu</th>
                    <th width="15%">No. Antrean</th>
                    <th width="20%">Jam Konsultasi</th>
                    <th width="20%">Dokter</th>
                    <th width="15%">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (mysqli_num_rows($query) > 0) {
                while ($row = mysqli_fetch_assoc($query)) {
                    // AMANKAN ERROR KODE BOOKING NULL: Jika kosong, ganti jadi '-'
                    $kode_booking = isset($row['kode_booking']) && !empty($row['kode_booking']) ? $row['kode_booking'] : '-';

                    // PERBAIKAN TOTAL AMANKAN ERROR LINE 71 (STRTOTIME):
                    // Ambil isi variabel, jika tidak ada di DB diset null agar aman
                    $jam_mulai_raw = $row['jam_mulai_user'] ?? null;
                    $jam_selesai_raw = $row['jam_selesai_user'] ?? null;

                    // Jalankan fungsi date() dan strtotime() HANYA JIKA data string jam di database valid
                    if (!empty($jam_mulai_raw) && !empty($jam_selesai_raw)) {
                        $jam_tampil = date('H:i', strtotime($jam_mulai_raw)) . ' - ' . date('H:i', strtotime($jam_selesai_raw)) . ' WIB';
                    } else {
                        $jam_tampil = '-'; // Teks pengganti jika jam kosong, tidak akan memicu error 00:00 lagi
                    }

                    // Amankan format tanggal
                    $tanggal_raw = $row['tanggal'] ?? null;
                    $tanggal_tampil = !empty($tanggal_raw) ? date('d M Y', strtotime($tanggal_raw)) : '-';

                    // Menentukan class warna badge status
                    $status = strtolower($row['status'] ?? 'pending');
                    $badge_class = 'status-pending';
                    if ($status == 'menunggu') $badge_class = 'status-menunggu';
                    if ($status == 'selesai') $badge_class = 'status-selesai';
                    if ($status == 'batal') $badge_class = 'status-batal';
            ?>
                <tr>
                    <td><strong>#<?= htmlspecialchars($kode_booking); ?></strong></td>
                    <td><?= $tanggal_tampil; ?></td>
                    <td><span style="font-weight: 700; color: #ff4f81;">Ke-<?= $row['no_antrean'] ?? '-'; ?></span></td>
                    <td>
                        <span style="font-weight: 500; color: #10b981;"><?= $jam_tampil; ?></span>
                    </td>
                    <td><strong><?= htmlspecialchars($row['konsultan'] ?? 'Dokter MAARS'); ?></strong></td>
                    <td>
                        <span class="badge <?= $badge_class; ?>"><?= ucfirst($status); ?></span>
                    </td>
                </tr>
            <?php
                }
            } else {
                echo "<tr><td colspan='6' class='empty'>Belum ada riwayat booking konsultasi.</td></tr>";
            }
            ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>