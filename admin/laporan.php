<?php
session_start();

// PROTEKSI ADMIN
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

include '../includes/conn.php';


// ==========================
// TOTAL DATA
// ==========================

// TOTAL PRODUK
$produk = mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) as total_produk 
        FROM produk
    ")
);

// TOTAL PELANGGAN
$pelanggan = mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) as total_pelanggan 
        FROM pelanggan
    ")
);

// TOTAL TRANSAKSI
$transaksi = mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) as total_transaksi 
        FROM transaksi
    ")
);

// TOTAL PENDAPATAN
$pendapatan = mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT SUM(total_harga) as total_pendapatan
        FROM transaksi
        WHERE status_pembayaran='Lunas'
    ")
);

// TOTAL BOOKING
$booking = mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) as total_booking
        FROM booking
    ")
);


// ==========================
// DATA TRANSAKSI
// ==========================
$data_transaksi = mysqli_query($conn, "
    SELECT t.*, p.nama
    FROM transaksi t
    JOIN pelanggan p
    ON t.id_pelanggan = p.id_pelanggan
    ORDER BY t.id_transaksi DESC
");


// ==========================
// LAPORAN PRODUK
// ==========================
$produk_terlaris = mysqli_query($conn, "
    SELECT 
        pr.nama_produk,

        COALESCE(SUM(dt.jumlah), 0) as total_terjual,

        pr.stok as sisa_stok,

        (pr.stok + COALESCE(SUM(dt.jumlah),0)) as stok_awal

    FROM produk pr

    LEFT JOIN detail_transaksi dt
    ON pr.id_produk = dt.id_produk

    GROUP BY pr.id_produk

    ORDER BY total_terjual DESC
");


// ==========================
// DATA KONSULTASI
// ==========================
$konsultasi = mysqli_query($conn, "
    SELECT 
        b.*,
        p.nama as nama_pelanggan,
        j.tanggal,
        j.konsultan
    FROM booking b
    JOIN pelanggan p
    ON b.id_pelanggan = p.id_pelanggan
    JOIN jadwal j
    ON b.id_jadwal = j.id_jadwal
    ORDER BY b.id_booking DESC
");

// ==========================
// LAPORAN KEUANGAN (Tambahan)
// ==========================
$laporan_keuangan = mysqli_query($conn, "
    SELECT kode_pembayaran, total_harga, tgl_transaksi 
    FROM transaksi 
    WHERE status_pembayaran='Lunas' 
    ORDER BY tgl_transaksi DESC
");
?>

<!DOCTYPE html>
<html lang="id">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Laporan Admin - MAARS Beauty</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/sidebar.css">

<style>

body{
    font-family:'DM Sans',sans-serif;
    background:#fbf6f8;
    display:flex;
    margin: 0;
}

.main{
    flex:1;
    padding:40px;
    height:100vh;
    overflow-y:auto;
    box-sizing: border-box;
}

.header-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #f0dde5;
    padding-bottom: 20px;
    margin-bottom: 10px;
}

.header h1{
    font-family:'Playfair Display',serif;
    font-size:30px;
    color:#1a0a12;
    margin: 0;
}

.header p{
    color:#8a6070;
    margin-top:5px;
    margin-bottom: 0;
}

/* BUTTON ACTION STYLES */
.btn-print-primary {
    background: #ff4f81;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 12px;
    font-family: 'DM Sans', sans-serif;
    font-weight: bold;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(255, 79, 129, 0.2);
    transition: 0.2s;
}

.btn-print-primary:hover {
    background: #e63e6d;
    transform: translateY(-2px);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.btn-print-sub {
    background: #fdf2f6;
    color: #ff4f81;
    border: 1px solid #f0dde5;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.btn-print-sub:hover {
    background: #ff4f81;
    color: white;
}

.stats-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:20px;
    margin-top:30px;
}

.stat-card{
    background:white;
    border-radius:20px;
    padding:25px;
    box-shadow:0 4px 15px rgba(0,0,0,0.03);
}

.stat-card h3{
    font-size:14px;
    color:#8a6070;
    margin-bottom:10px;
    margin-top: 0;
}

.stat-card h1{
    font-size:24px;
    color:#ff4f81;
    margin: 0;
}

.card-box{
    background:white;
    border-radius:20px;
    padding:30px;
    margin-top:30px;
    box-shadow:0 4px 15px rgba(0,0,0,0.03);
    page-break-inside: avoid; /* Menjaga agar card tidak terpotong hancur di tengah halaman PDF */
}

.section-title{
    font-size:22px;
    font-weight:700;
    color:#1a0a12;
    margin: 0;
}

table{
    width:100%;
    border-collapse:collapse;
}

th{
    padding:15px 10px;
    text-align:left;
    font-size:12px;
    color:#999;
    text-transform:uppercase;
    border-bottom:2px solid #f0f0f0;
}

td{
    padding:16px 10px;
    border-bottom:1px solid #f5f5f5;
    font-size:14px;
}

.badge{
    padding:6px 12px;
    border-radius:8px;
    font-size:12px;
    font-weight:600;
}

.lunas{
    background:#eaffea;
    color:#2ecc71;
}

.menunggu{
    background:#fff4e5;
    color:#f39c12;
}

/* ======================================================== */
/* CSS KHUSUS PRINT & SAVE AS PDF                           */
/* ======================================================== */
@media print {
    /* 1. Sembunyikan elemen navigasi yang mengganggu */
    .sidebar, 
    .btn-print-primary, 
    .btn-print-sub {
        display: none !important;
    }

    /* 2. Atur ulang layout utama agar penuh satu halaman */
    body {
        background: white !important;
        color: black !important;
    }

    .main {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        height: auto !important;
        overflow: visible !important;
    }

    .card-box {
        box-shadow: none !important;
        padding: 20px 0 !important;
        margin-top: 20px !important;
        border-bottom: 1px dashed #ccc;
    }

    .stat-card {
        border: 1px solid #eee !important;
        box-shadow: none !important;
    }
    
    /* 3. Tampilkan judul cetak berkas resmi */
    .header-container {
        border-bottom: 3px double #1a0a12 !important;
    }
}
</style>

</head>

<body>

<!-- SIDEBAR -->
<?php include '../includes/sidebar_admin.php'; ?>

<div class="main">

    <div class="header-container">
        <div class="header">
            <h1>Laporan Admin</h1>
            <p>Data penjualan dan konsultasi MAARS Beauty 📊</p>
        </div>
        <!-- TOMBOL UTAMA CETAK SEMUA KE PDF -->
        <button class="btn-print-primary" onclick="window.print()">
            🖨️ Cetak Semua Dokumen (PDF)
        </button>
    </div>


    <!-- STATISTIK -->
    <div class="stats-grid">

        <div class="stat-card">
            <h3>Total Produk</h3>
            <h1><?= $produk['total_produk']; ?></h1>
        </div>

        <div class="stat-card">
            <h3>Total Pelanggan</h3>
            <h1><?= $pelanggan['total_pelanggan']; ?></h1>
        </div>

        <div class="stat-card">
            <h3>Total Transaksi</h3>
            <h1><?= $transaksi['total_transaksi']; ?></h1>
        </div>

        <div class="stat-card">
            <h3>Total Booking</h3>
            <h1><?= $booking['total_booking']; ?></h1>
        </div>

        <div class="stat-card">
            <h3>Pendapatan</h3>
            <h1 style="color: #2ecc71;">
                Rp <?= number_format($pendapatan['total_pendapatan'],0,',','.'); ?>
            </h1>
        </div>

    </div>

<!-- LAPORAN KEUANGAN -->
<div class="card-box" id="print-keuangan">
        <div class="section-header">
            <div class="section-title">Rincian Laporan Keuangan (Lunas)</div>
            <button class="btn-print-sub" onclick="printSpesifik('print-keuangan')">Cetak</button>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Kode Pembayaran</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            $grand_total = 0;
            while($keu = mysqli_fetch_assoc($laporan_keuangan)){ 
                $grand_total += $keu['total_harga'];
            ?>
                <tr>
                    <td><?= !empty($keu['tgl_transaksi']) ? date('d M Y', strtotime($keu['tgl_transaksi'])) : '-'; ?></td>
                    <td><?= $keu['kode_pembayaran']; ?></td>
                    <td><strong>Rp <?= number_format($keu['total_harga'],0,',','.'); ?></strong></td>
                </tr>
            <?php } ?>
            </tbody>
            <tfoot>
                <tr style="background:#fff8fb;">
                    <td colspan="2" style="text-align:right;"><strong>TOTAL PENDAPATAN:</strong></td>
                    <td><strong style="color:#ff4f81; font-size:16px;">Rp <?= number_format($grand_total,0,',','.'); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- LAPORAN TRANSAKSI -->
    <div class="card-box" id="print-transaksi">

        <div class="section-header">
            <div class="section-title">Laporan Transaksi</div>
            <button class="btn-print-sub" onclick="printSpesifik('print-transaksi')">Cetak Bagian Ini</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Pelanggan</th>
                    <th>Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            mysqli_data_seek($data_transaksi, 0); // Reset pointer array data
            while($t = mysqli_fetch_assoc($data_transaksi)){
                $badge = strtolower($t['status_pembayaran']) == 'lunas' ? 'lunas' : 'menunggu';
            ?>
                <tr>
                    <td><strong>TRX-00<?= $t['id_transaksi']; ?></strong></td>
                    <td><?= $t['nama']; ?></td>
                    <td>Rp <?= number_format($t['total_harga'],0,',','.'); ?></td>
                    <td><span class="badge <?= $badge; ?>"><?= $t['status_pembayaran']; ?></span></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

    </div>



    <!-- LAPORAN PRODUK -->
    <div class="card-box" id="print-produk">

        <div class="section-header">
            <div class="section-title">Laporan Produk</div>
            <button class="btn-print-sub" onclick="printSpesifik('print-produk')">Cetak Bagian Ini</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Stok Awal</th>
                    <th>Total Terjual</th>
                    <th>Sisa Stok</th>
                </tr>
            </thead>
            <tbody>
            <?php while($p = mysqli_fetch_assoc($produk_terlaris)){ ?>
                <tr>
                    <td><?= $p['nama_produk']; ?></td>
                    <td><?= $p['stok_awal']; ?> pcs</td>
                    <td><span class="badge lunas"><?= $p['total_terjual']; ?> terjual</span></td>
                    <td><span class="badge menunggu"><?= $p['sisa_stok']; ?> tersedia</span></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

    </div>



    <!-- LAPORAN KONSULTASI -->
    <div class="card-box" id="print-konsultasi">

        <div class="section-header">
            <div class="section-title">Laporan Konsultasi</div>
            <button class="btn-print-sub" onclick="printSpesifik('print-konsultasi')">Cetak Bagian Ini</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Pelanggan</th>
                    <th>Konsultan</th>
                    <th>Tanggal</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            while($k = mysqli_fetch_assoc($konsultasi)){
                $badge = strtolower($k['status']) == 'selesai' ? 'lunas' : 'menunggu';
            ?>
                <tr>
                    <td><?= $k['nama_pelanggan']; ?></td>
                    <td><?= $k['konsultan']; ?></td>
                    <td><?= date('d M Y', strtotime($k['tanggal'])); ?></td>
                    <td><span class="badge <?= $badge; ?>"><?= $k['status']; ?></span></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

    </div>

</div>



<!-- SCRIPT UNTUK METODE CETAK SPESIFIK -->
<script>
function printSpesifik(idElemen) {
    var isiAsliHtml = document.body.innerHTML;
    var elemenCetak = document.getElementById(idElemen).innerHTML;
    
    // Set konten layar hanya ke bagian data tabel yang dipilih + Header Judul ringkas
    document.body.innerHTML = `
        <div style="padding:30px; font-family:'DM Sans',sans-serif;">
            <h2 style="text-align:center; color:#1a0a12; font-family:'Playfair Display',serif; border-bottom:2px solid #ff4f81; padding-bottom:10px;">
                MAARS BEAUTY - DOKUMEN LAPORAN RESMI
            </h2>
            ${elemenCetak}
        </div>
    `;
    
    window.print();
    
    // Kembalikan struktur dashboard admin setelah jendela print ditutup
    document.body.innerHTML = isiAsliHtml;
    window.location.reload(); // Reload ringan untuk mengaktifkan kembali handler JS DOM
}
</script>

</body>
</html>