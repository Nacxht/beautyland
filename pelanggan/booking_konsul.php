<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'pelanggan') {
    header("Location: ../login.php");
    exit;
}

include '../includes/conn.php';
$id_pelanggan = $_SESSION['id_pelanggan'];
$durasi_per_sesi = 45; // Durasi tetap per orang

// ==========================================
// PROSES BOOKING
// ==========================================
if (isset($_POST['proses_booking'])) {
    if (isset($_POST['id_jadwal'])) {
        $id_jadwal = $_POST['id_jadwal'];

        // 1. Cek apakah user sudah punya bookingan aktif
        $cek_double = mysqli_query($conn, "SELECT * FROM booking WHERE id_pelanggan='$id_pelanggan' AND id_jadwal='$id_jadwal' AND status != 'Batal'");
        if (mysqli_num_rows($cek_double) > 0) {
            $error = "Kamu sudah membooking jadwal ini.";
        } else {
            // 2. Ambil data jadwal & hitung kuota otomatis (Durasi / 45)
            $q_j = mysqli_query($conn, "
                SELECT *, TIMESTAMPDIFF(MINUTE, jam_mulai, jam_selesai) as total_durasi 
                FROM jadwal WHERE id_jadwal='$id_jadwal'
            ");
            $j = mysqli_fetch_assoc($q_j);
            
            $maksimal_slot = floor($j['total_durasi'] / $durasi_per_sesi);
            
            // 3. Hitung jumlah antrean yang sudah terisi
            $q_antrean = mysqli_query($conn, "SELECT COUNT(*) as total FROM booking WHERE id_jadwal='$id_jadwal' AND status != 'Batal'");
            $d_antrean = mysqli_fetch_assoc($q_antrean);
            $jumlah_terisi = $d_antrean['total'];

            if ($jumlah_terisi >= $maksimal_slot) {
                $error = "Maaf, slot antrean untuk jadwal ini sudah penuh!";
            } else {
                // 4. Hitung No Antrean dan Jam Estimasi
                $no_antrean = $jumlah_terisi + 1;
                $menit_tambahan = ($no_antrean - 1) * $durasi_per_sesi;
                
                $jam_mulai_user = date('H:i:s', strtotime("+$menit_tambahan minutes", strtotime($j['jam_mulai'])));
                $jam_selesai_user = date('H:i:s', strtotime("+$durasi_per_sesi minutes", strtotime($jam_mulai_user)));
                
                $kode_booking = "MRS-" . strtoupper(substr(md5(time() . rand()), 0, 5));
                $tanggal_booking = date('Y-m-d');

                $insert_lock = mysqli_query($conn, "
                    INSERT INTO booking (id_pelanggan, id_jadwal, kode_booking, no_antrean, jam_mulai_user, jam_selesai_user, tanggal_booking, status)
                    VALUES ('$id_pelanggan', '$id_jadwal', '$kode_booking', '$no_antrean', '$jam_mulai_user', '$jam_selesai_user', '$tanggal_booking', 'Pending')
                ");

                if ($insert_lock) {
                    $id_booking_baru = mysqli_insert_id($conn);
                    header("Location: konfirmasi_booking.php?id=$id_booking_baru");
                    exit;
                }
            }
        }
    } else {
        $error = "Silakan pilih jadwal terlebih dahulu.";
    }
}

// ==========================================
// AMBIL DATA JADWAL
// ==========================================
$q_jadwal = mysqli_query($conn, "
    SELECT *, TIMESTAMPDIFF(MINUTE, jam_mulai, jam_selesai) as total_durasi 
    FROM jadwal 
    WHERE tanggal >= CURDATE() 
    ORDER BY tanggal ASC, jam_mulai ASC
");
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Konsultasi - MAARS Beauty</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/sidebar.css">
<style>
body { font-family:'DM Sans',sans-serif; background:#fbf6f8; display:flex; margin:0; }
.main { flex:1; padding:40px; height:100vh; overflow-y:auto; box-sizing:border-box; }
.header-text h1 { font-family:'Playfair Display',serif; font-size:30px; color:#1a0a12; margin-bottom:5px; }
.header-text p { color:#8a6070; margin-bottom:30px; }
.card-box { background:white; border-radius:20px; padding:30px; box-shadow:0 4px 15px rgba(0,0,0,0.03); }
table { width:100%; border-collapse:collapse; }
th { padding:15px 10px; text-align:left; font-size:12px; color:#999; text-transform:uppercase; border-bottom:2px solid #f0f0f0; }
td { padding:18px 10px; border-bottom:1px solid #f5f5f5; font-size:14px; }
tr:hover { background:#fcfcfc; }
.radio-jadwal { width:18px; height:18px; accent-color:#ff4f81; cursor:pointer; }
.btn-submit { margin-top:25px; background:#ff4f81; color:white; border:none; padding:14px 25px; border-radius:12px; font-weight:600; cursor:pointer; width:100%; font-size:14px; transition: 0.2s; }
.btn-submit:hover { background:#e63f75; }
.error-msg { background:#fef2f2; color:#ef4444; padding:14px; border-radius:10px; margin-bottom:20px; font-size:13px; }
.badge-status { padding:4px 8px; border-radius:6px; font-size:12px; font-weight:600; display:inline-block; }
.badge-info { background:#fff0f5; color:#ff4f81; }
.badge-danger { background:#fef2f2; color:#ef4444; }
.empty { text-align:center; color:#999; padding:30px; }
</style>
</head>
<body>

<?php include '../includes/sidebar_user.php'; ?>

<div class="main">
    <div class="header-text">
        <h1>Booking Konsultasi</h1>
        <p>Pilih jadwal konsultasi yang tersedia ✨</p>
    </div>

    <div class="card-box">
        <?php if(isset($error)){ ?>
            <div class="error-msg"><?= $error; ?></div>
        <?php } ?>

        <form method="POST">
            <table>
                <thead>
                    <tr>
                        <th width="10%">Pilih</th>
                        <th width="25%">Tanggal</th>
                        <th width="30%">Waktu Konsultasi</th>
                        <th width="35%">Dokter</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if(mysqli_num_rows($q_jadwal) > 0){
                    while($row = mysqli_fetch_assoc($q_jadwal)){
                        $id_j = $row['id_jadwal'];
                        
                        // Logika perhitungan kuota dinamis
                        $max = floor($row['total_durasi'] / $durasi_per_sesi);
                        $q_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM booking WHERE id_jadwal='$id_j' AND status != 'Batal'");
                        $d_count = mysqli_fetch_assoc($q_count);
                        $sisa = $max - $d_count['total'];
                ?>
                    <tr>
                        <td>
                            <?php if($sisa <= 0) { ?>
                                <span class="badge-status badge-danger">Penuh</span>
                            <?php } else { ?>
                                <input type="radio" name="id_jadwal" value="<?= $row['id_jadwal']; ?>" class="radio-jadwal" required>
                            <?php } ?>
                        </td>
                        <td><strong><?= date('d M Y', strtotime($row['tanggal'])); ?></strong></td>
                        <td>
                            <span class="badge-status badge-info">
                                <?= date('H:i', strtotime($row['jam_mulai'])); ?> - <?= date('H:i', strtotime($row['jam_selesai'])); ?> WIB
                            </span>
                            <small style="display:block; color:#999; margin-top:2px;">(Sisa <?= max(0, $sisa); ?> Slot)</small>
                        </td>
                        <td><strong style="color: #1a0a12;"><?= htmlspecialchars($row['konsultan']); ?></strong></td>
                    </tr>
                <?php
                    }
                } else {
                    echo "<tr><td colspan='4' class='empty'>Belum ada jadwal konsultasi tersedia.</td></tr>";
                }
                ?>
                </tbody>
            </table>

            <?php if(mysqli_num_rows($q_jadwal) > 0){ ?>
                <button type="submit" name="proses_booking" class="btn-submit">Booking Sekarang</button>
            <?php } ?>
        </form>
    </div>
</div>
</body>
</html>