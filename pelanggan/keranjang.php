<?php
session_start();

// PROTEKSI HALAMAN
if (
    !isset($_SESSION['user']) ||
    $_SESSION['role'] !== 'pelanggan'
){
    header("Location: ../login.php");
    exit;
}

include '../includes/conn.php';

$id_pelanggan = $_SESSION['id_pelanggan'];


// ======================================
// TAMBAH KE KERANJANG
// ======================================
if(isset($_POST['id_produk'])){

    $id_produk = $_POST['id_produk'];

    // CEK PRODUK SUDAH ADA ATAU BELUM
    $cek = mysqli_query($conn, "
        SELECT *
        FROM keranjang
        WHERE
            id_pelanggan='$id_pelanggan'
            AND id_produk='$id_produk'
    ");

    // JIKA SUDAH ADA → TAMBAH JUMLAH
    if(mysqli_num_rows($cek) > 0){

        mysqli_query($conn, "
            UPDATE keranjang
            SET jumlah = jumlah + 1
            WHERE
                id_pelanggan='$id_pelanggan'
                AND id_produk='$id_produk'
        ");

    }else{

        // JIKA BELUM ADA → INSERT
        mysqli_query($conn, "
            INSERT INTO keranjang
            (
                id_pelanggan,
                id_produk,
                jumlah
            )
            VALUES
            (
                '$id_pelanggan',
                '$id_produk',
                1
            )
        ");
    }

    header("Location: keranjang.php");
    exit;
}

// ======================================
// TAMBAH JUMLAH
// ======================================
if(isset($_GET['tambah'])){

    $id_keranjang = $_GET['tambah'];

    mysqli_query($conn, "
        UPDATE keranjang
        SET jumlah = jumlah + 1
        WHERE id_keranjang='$id_keranjang'
    ");

    header("Location: keranjang.php");
    exit;
}


// ======================================
// KURANG JUMLAH
// ======================================
if(isset($_GET['kurang'])){

    $id_keranjang = $_GET['kurang'];

    // AMBIL JUMLAH SEKARANG
    $cek = mysqli_query($conn, "
        SELECT jumlah
        FROM keranjang
        WHERE id_keranjang='$id_keranjang'
    ");

    $dataJumlah = mysqli_fetch_assoc($cek);

    if($dataJumlah['jumlah'] > 1){

        mysqli_query($conn, "
            UPDATE keranjang
            SET jumlah = jumlah - 1
            WHERE id_keranjang='$id_keranjang'
        ");

    }else{

        // kalau jumlah tinggal 1 → hapus
        mysqli_query($conn, "
            DELETE FROM keranjang
            WHERE id_keranjang='$id_keranjang'
        ");
    }

    header("Location: keranjang.php");
    exit;
}

// ======================================
// HAPUS ITEM
// ======================================
if(isset($_GET['hapus'])){

    $id_keranjang = $_GET['hapus'];

    mysqli_query($conn, "
        DELETE FROM keranjang
        WHERE id_keranjang='$id_keranjang'
    ");

    header("Location: keranjang.php");
    exit;
}


// ======================================
// AMBIL DATA KERANJANG
// ======================================
$data = mysqli_query($conn, "
    SELECT
        k.*,
        p.nama_produk,
        p.harga,
        p.gambar
    FROM keranjang k
    JOIN produk p
    ON k.id_produk = p.id_produk
    WHERE k.id_pelanggan='$id_pelanggan'
    ORDER BY k.id_keranjang DESC
");
?>

<!DOCTYPE html>
<html lang="id">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Keranjang Saya - MAARS Beauty</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/sidebar.css">

<style>

body{
    font-family:'DM Sans',sans-serif;
    background-color:#fbf6f8;
    display:flex;
}

.main{
    flex:1;
    padding:40px;
    height:100vh;
    overflow-y:auto;
}

.topbar-modern{
    margin-bottom:35px;
}

.header-text h1{
    font-family:'Playfair Display',serif;
    font-size:28px;
    color:#1a0a12;
    margin-bottom:6px;
}

.header-text p{
    color:#8a6070;
    font-size:14px;
}

.cart-container{
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:30px;
    align-items:start;
}

.cart-items{
    display:flex;
    flex-direction:column;
    gap:15px;
}

.cart-item{
    background:white;
    padding:20px;
    border-radius:20px;
    display:flex;
    align-items:center;
    gap:20px;
    box-shadow:0 4px 15px rgba(0,0,0,0.02);
    border:1px solid rgba(240,221,229,0.4);
}

.checkbox-item{
    width:20px;
    height:20px;
    accent-color:#ff4f81;
    cursor:pointer;
}

.cart-item img{
    width:80px;
    height:80px;
    object-fit:cover;
    border-radius:12px;
    border:1px solid #f0dde5;
}

.cart-info{
    flex:1;
}

.cart-info h4{
    font-size:16px;
    color:#1a0a12;
    margin-bottom:5px;
    font-weight:600;
}

.cart-info p{
    color:#8a6070;
    font-size:14px;
}

.cart-qty{
    font-weight:600;
    color:#1a0a12;
    background:#fbf6f8;
    padding:8px 16px;
    border-radius:10px;
    font-size:14px;
}

.cart-subtotal{
    font-weight:700;
    color:#ff4f81;
    font-size:16px;
    min-width:120px;
    text-align:right;
}

.btn-hapus{
    background:#ffe8ee;
    color:#ff4f81;
    padding:10px 14px;
    border-radius:10px;
    text-decoration:none;
    font-size:13px;
    font-weight:600;
}

.summary-box{
    background:white;
    padding:30px;
    border-radius:20px;
    box-shadow:0 4px 15px rgba(0,0,0,0.02);
    border:1px solid rgba(240,221,229,0.4);
    position:sticky;
    top:40px;
}

.summary-box h3{
    font-family:'Playfair Display',serif;
    font-size:20px;
    margin-bottom:25px;
    color:#1a0a12;
}

.summary-row{
    display:flex;
    justify-content:space-between;
    margin-bottom:15px;
    color:#444;
    font-size:14px;
}

.summary-total{
    display:flex;
    justify-content:space-between;
    margin-top:20px;
    padding-top:20px;
    border-top:2px dashed #f0dde5;
    font-weight:700;
    font-size:18px;
    color:#ff4f81;
}

.btn-checkout{
    width:100%;
    background:#ff4f81;
    color:white;
    padding:15px;
    border-radius:12px;
    border:none;
    font-size:15px;
    font-weight:600;
    cursor:pointer;
    margin-top:25px;
}

.btn-checkout:hover{
    background:#e63e6d;
}

.empty-cart{
    text-align:center;
    padding:50px;
    background:white;
    border-radius:20px;
    color:#8a6070;
    border:1px dashed #f0dde5;
}

</style>

</head>

<body>

<?php include '../includes/sidebar_user.php'; ?>

<div class="main">

    <div class="topbar-modern">

        <div class="header-text">

            <h1>Keranjang Belanja</h1>

            <p>
                Pilih produk yang ingin kamu checkout ✨
            </p>

        </div>

    </div>

<?php if(mysqli_num_rows($data) > 0): ?>

<form action="check_out.php" method="POST" id="formCheckout">

<div class="cart-container">

    <!-- LIST PRODUK -->
    <div class="cart-items">

        <?php while($row = mysqli_fetch_assoc($data)): ?>

        <?php
            $subtotal = $row['harga'] * $row['jumlah'];
        ?>

        <div class="cart-item">

            <!-- CHECKBOX -->
            <input
                type="checkbox"
                name="pilih_produk[]"
                value="<?= $row['id_produk']; ?>"
                class="checkbox-item pilih-item"
                data-harga="<?= $subtotal; ?>"
                data-jumlah="<?= $row['jumlah']; ?>"
            >

            <!-- GAMBAR -->
            <img src="../assets/gambar/<?= $row['gambar']; ?>">

            <!-- INFO -->
            <div class="cart-info">

                <h4><?= $row['nama_produk']; ?></h4>

                <p>
                    Rp <?= number_format($row['harga'],0,',','.'); ?>
                    / pcs
                </p>

            </div>

            <!-- JUMLAH -->
            <div class="cart-qty" style="
                display:flex;
                align-items:center;
                gap:10px;
            ">

                <!-- MINUS -->
                <a href="?kurang=<?= $row['id_keranjang']; ?>"
                style="
                background:#ffe8ee;
                color:#ff4f81;
                width:30px;
                height:30px;
                display:flex;
                align-items:center;
                justify-content:center;
                border-radius:8px;
                text-decoration:none;
                font-weight:bold;
                ">
                    -
                </a>

                <!-- JUMLAH -->
                <span style="
                    min-width:20px;
                    text-align:center;
                    font-weight:600;
                ">
                    <?= $row['jumlah']; ?>
                </span>

                <!-- PLUS -->
                <a href="?tambah=<?= $row['id_keranjang']; ?>"
                style="
                background:#ff4f81;
                color:white;
                width:30px;
                height:30px;
                display:flex;
                align-items:center;
                justify-content:center;
                border-radius:8px;
                text-decoration:none;
                font-weight:bold;
                ">
                    +
                </a>

            </div>

            <!-- SUBTOTAL -->
            <div class="cart-subtotal">

                Rp <?= number_format($subtotal,0,',','.'); ?>

            </div>

            <!-- HAPUS -->
            <a
                href="?hapus=<?= $row['id_keranjang']; ?>"
                class="btn-hapus"
            >
                Hapus
            </a>

        </div>

        <?php endwhile; ?>

    </div>



    <!-- RINGKASAN -->
    <div class="summary-box">

        <h3>Ringkasan Belanja</h3>

        <div class="summary-row">

            <span>Total Barang</span>

            <span id="total-item">
                0 item
            </span>

        </div>

        <div class="summary-row">

            <span>Biaya Pengiriman</span>

            <span style="color:#2ecc71; font-weight:600;">
                Gratis
            </span>

        </div>

        <div class="summary-total">

            <span>Total Bayar</span>

            <span id="total-harga">
                Rp 0
            </span>

        </div>

        <button class="btn-checkout">
            Lanjut ke Pembayaran
        </button>

    </div>

</div>

</form>

<?php else: ?>

<div class="empty-cart">

    <h3 style="
        font-family:'Playfair Display',serif;
        color:#1a0a12;
        font-size:22px;
        margin-bottom:10px;
    ">
        Keranjangmu masih kosong 😢
    </h3>

    <p style="margin-bottom:20px;">
        Yuk lihat produk skincare favoritmu!
    </p>

    <a href="produk_user.php"
       style="
       background:#ff4f81;
       color:white;
       padding:10px 20px;
       border-radius:10px;
       text-decoration:none;
       font-weight:500;
    ">
        Mulai Belanja
    </a>

</div>

<?php endif; ?>

</div>


<!-- JAVASCRIPT -->
<script>

const checkbox = document.querySelectorAll('.pilih-item');

const totalHarga = document.getElementById('total-harga');

const totalItem = document.getElementById('total-item');

const formCheckout = document.getElementById('formCheckout');


// HITUNG TOTAL
function hitungTotal(){

    let total = 0;

    let item = 0;

    checkbox.forEach(function(box){

        if(box.checked){

            total += parseInt(box.dataset.harga);

            item += parseInt(box.dataset.jumlah);

        }

    });

    totalHarga.innerHTML =
        'Rp ' + total.toLocaleString('id-ID');

    totalItem.innerHTML =
        item + ' item';
}


// EVENT CHECKBOX
checkbox.forEach(function(box){

    box.addEventListener('change', hitungTotal);

});


// VALIDASI SUBMIT
formCheckout.addEventListener('submit', function(e){

    let adaDipilih = false;

    checkbox.forEach(function(box){

        if(box.checked){

            adaDipilih = true;

        }

    });

    if(!adaDipilih){

        e.preventDefault();

        alert('Pilih minimal 1 produk terlebih dahulu!');

    }

});

</script>

</body>
</html>