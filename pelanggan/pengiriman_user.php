<?php
session_start();
include '../includes/conn.php';

if(
    !isset($_SESSION['user']) ||
    $_SESSION['role'] !== 'pelanggan'
){
    header("Location: ../login.php");
    exit;
}

$id_pelanggan = $_SESSION['id_pelanggan'];


// ======================================
// AMBIL DATA PESANAN + PENGIRIMAN
// ======================================
$data = mysqli_query($conn, "
    SELECT
        t.*,
        p.status_pengiriman,
        p.jasa_kirim,
        p.nomor_resi,
        p.alamat_tujuan,
        p.tanggal_kirim
    FROM transaksi t
    LEFT JOIN pengiriman p
    ON t.id_transaksi = p.id_transaksi
    WHERE t.id_pelanggan='$id_pelanggan'
    ORDER BY t.id_transaksi DESC
");
?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Pesanan Saya</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/sidebar.css">

<style>

body{
    font-family:'DM Sans',sans-serif;
    background:#fbf6f8;
    display:flex;
}

.main{
    flex:1;
    padding:40px;
    height:100vh;
    overflow-y:auto;
}

.header{
    margin-bottom:35px;
}

.header h1{
    font-family:'Playfair Display',serif;
    font-size:34px;
    color:#1a0a12;
    margin-bottom:8px;
}

.header p{
    color:#8a6070;
    font-size:14px;
}


/* CARD */
.order-card{
    background:white;
    border-radius:24px;
    padding:28px;
    margin-bottom:25px;
    border:1px solid rgba(240,221,229,0.5);
    box-shadow:0 4px 15px rgba(0,0,0,0.03);
}

.card-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:25px;
}

.order-code{
    font-size:22px;
    font-weight:700;
    color:#1a0a12;
}

.status{
    padding:10px 16px;
    border-radius:12px;
    font-size:13px;
    font-weight:700;
    background:#fff0f5;
    color:#ff4f81;
}


/* GRID */
.info-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.info-box{
    background:#fff8fb;
    padding:18px;
    border-radius:16px;
}

.info-box span{
    display:block;
    font-size:13px;
    color:#8a6070;
    margin-bottom:6px;
}

.info-box strong{
    color:#1a0a12;
    line-height:1.6;
}


/* EMPTY */
.empty{
    background:white;
    padding:50px;
    border-radius:24px;
    text-align:center;
    color:#8a6070;
    border:1px dashed #f0dde5;
}

.empty h3{
    font-size:22px;
    margin-bottom:10px;
    color:#1a0a12;
    font-family:'Playfair Display',serif;
}


/* RESPONSIVE */
@media(max-width:800px){

    .info-grid{
        grid-template-columns:1fr;
    }

    .card-top{
        flex-direction:column;
        align-items:flex-start;
        gap:15px;
    }
}

</style>

</head>

<body>

<?php include '../includes/sidebar_user.php'; ?>

<div class="main">

    <!-- HEADER -->
    <div class="header">

        <h1>Pesanan Saya</h1>

        <p>
            Lihat status pesanan dan pengiriman skincare kamu ✨
        </p>

    </div>


    <?php if(mysqli_num_rows($data) > 0): ?>

        <?php while($row = mysqli_fetch_assoc($data)): ?>

        <div class="order-card">

            <!-- TOP -->
            <div class="card-top">

                <div class="order-code">

                    ORDER <?= $row['kode_pembayaran']; ?>

                </div>

                <div class="status">

                    <?= $row['status_pengiriman'] ?? 'Belum Diproses'; ?>

                </div>

            </div>


            <!-- INFO -->
            <div class="info-grid">

                <div class="info-box">

                    <span>Total Pembayaran</span>

                    <strong>

                        Rp <?= number_format(
                            $row['total_harga'],
                            0,
                            ',',
                            '.'
                        ); ?>

                    </strong>

                </div>


                <div class="info-box">

                    <span>Status Pembayaran</span>

                    <strong>

                        <?= $row['status_pembayaran']; ?>

                    </strong>

                </div>


                <div class="info-box">

                    <span>Jasa Kirim</span>

                    <strong>

                        <?= $row['jasa_kirim'] ?: '-'; ?>

                    </strong>

                </div>


                <div class="info-box">

                    <span>Nomor Resi</span>

                    <strong>

                        <?= $row['nomor_resi'] ?: '-'; ?>

                    </strong>

                </div>


                <div class="info-box" style="grid-column:1/-1;">

                    <span>Alamat Pengiriman</span>

                    <strong>

                        <?= $row['alamat_tujuan'] ?: '-'; ?>

                    </strong>

                </div>


                <div class="info-box" style="grid-column:1/-1;">

                    <span>Tanggal Kirim</span>

                    <strong>

                        <?= $row['tanggal_kirim'] ?: '-'; ?>

                    </strong>

                </div>

            </div>

        </div>

        <?php endwhile; ?>

    <?php else: ?>

    <div class="empty">

        <h3>
            Belum ada pesanan 😢
        </h3>

        <p>
            Yuk checkout produk skincare favoritmu!
        </p>

    </div>

    <?php endif; ?>

</div>

</body>
</html>