<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
include '../includes/conn.php';

date_default_timezone_set('Asia/Jakarta');
$waktu_sekarang_db = date('Y-m-d H:i:s');

// Update status selesai otomatis
mysqli_query($conn, "UPDATE jadwal SET keterangan = 'Selesai' WHERE CONCAT(tanggal, ' ', jam_mulai) < '$waktu_sekarang_db' AND (keterangan = 'Aktif' OR keterangan IS NULL)");

if(isset($_GET['selesai'])){
    $id = $_GET['selesai'];
    mysqli_query($conn, "UPDATE booking SET status='Selesai' WHERE id_booking='$id'");
    header("Location: konsultasi.php");
    exit;
}

if(isset($_POST['simpan_jadwal'])){
    $tanggal = $_POST['tanggal'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    $konsultan = $_POST['konsultan'];
    mysqli_query($conn, "INSERT INTO jadwal (tanggal, jam_mulai, jam_selesai, konsultan, keterangan) VALUES ('$tanggal', '$jam_mulai', '$jam_selesai', '$konsultan', 'Aktif')");
    header("Location: konsultasi.php");
    exit;
}

$jadwal = mysqli_query($conn, "SELECT * FROM jadwal ORDER BY tanggal ASC, jam_mulai ASC");

// PERBAIKAN QUERY: Mengambil jam dari tabel booking (b.jam_mulai_user)
$booking = mysqli_query($conn, "
    SELECT b.*, p.nama as nama_pelanggan, j.tanggal, 
    b.jam_mulai_user, b.jam_selesai_user,
    (SELECT COUNT(*) + 1 FROM booking b2 WHERE b2.id_jadwal = b.id_jadwal AND b2.id_booking < b.id_booking) as nomor_antrean
    FROM booking b 
    JOIN pelanggan p ON b.id_pelanggan = p.id_pelanggan 
    LEFT JOIN jadwal j ON b.id_jadwal = j.id_jadwal 
    ORDER BY j.tanggal ASC, b.jam_mulai_user ASC, b.id_booking ASC
");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Konsultasi Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <style>
        body { 
            font-family: 'DM Sans', sans-serif; 
            background: #fbf6f8; 
            display: flex; 
            margin: 0; 
        }
        .main { 
            flex: 1; 
            padding: 40px; 
            height: 100vh; 
            overflow-y: auto; 
        }
        .main a {
            margin-top: 20px;
        }
        .form-box, .card-box {
            background: white; 
            border-radius: 20px; 
            padding: 30px; 
            margin-top: 25px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
        }
        .section-title { 
            font-size: 20px; 
            font-weight: 700; 
            margin-bottom: 20px; 
            color: #1a0a12; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 10px; 
        }
        th { 
            padding: 15px; 
            text-align: left; 
            font-size: 12px; 
            color: #8a6070; 
            text-transform: uppercase; 
            border-bottom: 2px solid #ff4f81; 
        }
        td { 
            padding: 18px; 
            border-bottom: 1px solid #f5f5f5; 
            vertical-align: middle; 
        }
        .btn-pink { 
            background-color: #ff4f81; 
            color: white; 
            padding: 8px 16px; 
            border-radius: 10px; 
            text-decoration: none; 
            font-weight: 700; 
            font-size: 12px; 
            border: none; 
            display: inline-block; 
            transition: all 0.3s ease; /* Transisi halus */
            cursor: pointer;
        }

        /* Efek saat Mouse di atas tombol (Hover) */
        .btn-pink:hover { 
            background-color: #e03b6a; /* Warna sedikit lebih gelap */
            transform: translateY(-2px); /* Tombol sedikit naik ke atas */
            box-shadow: 0 4px 8px rgba(255, 79, 129, 0.3); /* Menambahkan bayangan */
            color: white;
        }

        /* Efek saat tombol diklik (Active) */
        .btn-pink:active {
            transform: translateY(0);
        }
        .antrean-numb { 
            background: #ff4f81; 
            color: white; 
            width: 35px; 
            height: 35px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 800; 
            margin: auto; 
        }
        /* Style Dasar Tombol Edit */
        .btn-edit { 
            background-color: #fff0f5; 
            color: #ff4f81; 
            padding: 6px 12px; 
            border-radius: 8px; 
            text-decoration: none; 
            font-size: 11px; 
            font-weight: 700; 
            border: 1px solid #ff4f81; 
            transition: all 0.3s ease; /* Transisi halus */
            display: inline-block;
        }

        /* Efek Hover untuk Tombol Edit */
        .btn-edit:hover { 
            background-color: #ff4f81; /* Warna latar berubah jadi pink solid */
            color: white;             /* Warna teks berubah jadi putih */
            box-shadow: 0 4px 6px rgba(255, 79, 129, 0.2);
        }
        /* Style untuk Label Status */
        .status-label {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-aktif {
            background-color: #e6f9ed; /* Hijau muda */
            color: #27ae60;            /* Hijau tua */
        }

        .status-selesai {
            background-color: #f2f2f2;  /* Abu-abu */
            color: #7f8c8d;            /* Abu-abu gelap */
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar_admin.php'; ?>
    <div class="main">
        <h1>Jadwal Konsultasi</h1>

        <a href="tambah_jadwal.php" class="btn-pink" style="text-decoration: none; padding: 10px 20px;">+ Tambah Jadwal</a>

        <div class="card-box">
            <div class="section-title">Jadwal Konsultasi Tersedia</div>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Konsultan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1; 
                    while($j = mysqli_fetch_assoc($jadwal)){ 
                        $jm = !empty($j['jam_mulai']) ? date('H:i', strtotime($j['jam_mulai'])) : '--:--';
                        $js = !empty($j['jam_selesai']) ? date('H:i', strtotime($j['jam_selesai'])) : '--:--';
                    ?>
                    <tr>
                        <td><?= $no++; ?></td>
                        <td><?= date('d M Y', strtotime($j['tanggal'])); ?></td>
                        <td><?= $jm . ' - ' . $js; ?></td>
                        <td><?= htmlspecialchars($j['konsultan']); ?></td>
                        <td style="text-align: center;">
                            <?php if($j['keterangan'] == 'Selesai'): ?>
                                <span class="status-label status-selesai">Selesai</span>
                            <?php else: ?>
                                <span class="status-label status-aktif">Aktif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($j['keterangan'] !== 'Selesai'): ?>
                                <a href="edit_jadwal.php?id=<?= $j['id_jadwal']; ?>" class="btn-edit" >Edit</a>
                            <?php else: ?>
                                <span style="color:#aaa; font-size:11px;">Terkunci</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="card-box">
            <div class="section-title">Data Booking Pelanggan</div>
            <table>
                <thead>
                    <tr>
                        <th>Antrean</th>
                        <th>Pelanggan</th>
                        <th>Waktu</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($b = mysqli_fetch_assoc($booking)){ 
                        // Ambil jam dari booking, jika kosong ambil dari jadwal
                        $jm = !empty($b['jam_mulai_user']) ? date('H:i', strtotime($b['jam_mulai_user'])) : '--:--';
                        $js = !empty($b['jam_selesai_user']) ? date('H:i', strtotime($b['jam_selesai_user'])) : '--:--';
                        
                        // Pastikan tanggal_booking dari database ada, jika tidak, tampilkan tanggal dari jadwal (j.tanggal)
                        $tanggal_tampil = !empty($b['tanggal']) ? $b['tanggal'] : $b['tanggal'];
                    ?>
                        <tr>
                            <td><div class="antrean-numb"><?= $b['nomor_antrean']; ?></div></td>
                            <td>
                                <strong><?= htmlspecialchars($b['nama_pelanggan']); ?></strong><br>
                                <small style="color:#ff4f81;">#<?= $b['kode_booking']; ?></small>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime($tanggal_tampil)); ?><br>
                                <?= $jm . ' - ' . $js; ?> WIB
                            </td>
                            <td>
                                <?php if($b['status'] != 'Selesai'): ?>
                                    <a href="?selesai=<?= $b['id_booking']; ?>" class="btn-pink">Tandai Selesai</a>
                                <?php else: ?>
                                    <span style="color:green; font-weight:bold;">Selesai</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
            </table>
        </div>
    </div>
</body>
</html>