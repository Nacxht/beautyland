<?php
session_start();

include '../includes/conn.php';

if(!isset($_SESSION['id_pelanggan'])){
    header("Location: ../login.php");
    exit;
}

$id_pelanggan = $_SESSION['id_pelanggan'];

$user = mysqli_query($conn, "
    SELECT *
    FROM pelanggan
    WHERE id_pelanggan='$id_pelanggan'
");

$data_user = mysqli_fetch_assoc($user);

$items = [];
$total_bayar = 0;


// ======================================
// BELI LANGSUNG (Sudah diperbaiki dari error)
// ======================================
if(isset($_POST['id_produk'])){

    $id_produk = $_POST['id_produk'];

    $query = mysqli_query($conn, "
        SELECT *
        FROM produk
        WHERE id_produk='$id_produk'
    ");

    $produk = mysqli_fetch_assoc($query);

    if(!$produk){
        die("Produk tidak ditemukan");
    }

    $items[] = [
        'id_produk'   => $produk['id_produk'],
        'nama_produk' => $produk['nama_produk'],
        'harga'       => $produk['harga'],
        'jumlah'      => 1,
        'gambar'      => $produk['gambar']
    ];
}


// ======================================
// DARI KERANJANG
// ======================================
elseif(isset($_POST['pilih_produk'])){

    foreach($_POST['pilih_produk'] as $id_produk){

        $query = mysqli_query($conn, "
            SELECT
                k.*,
                p.nama_produk,
                p.harga,
                p.gambar
            FROM keranjang k
            JOIN produk p
            ON k.id_produk = p.id_produk
            WHERE
                k.id_pelanggan='$id_pelanggan'
                AND k.id_produk='$id_produk'
        ");

        $row = mysqli_fetch_assoc($query);

        if($row){
            $items[] = $row;
        }
    }

}else{
    die("Produk tidak ditemukan");
}



// ======================================
// TOTAL
// ======================================
foreach($items as $item){
    $total_bayar += ($item['harga'] * $item['jumlah']);
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - MAARS Beauty</title>
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

        .header-text h1{
            font-family:'Playfair Display',serif;
            font-size:34px;
            color:#1a0a12;
            margin-bottom:8px;
            margin-top: 0;
        }

        .header-text p{
            color:#8a6070;
            margin-bottom:30px;
            font-size:14px;
        }

        .checkout-grid{
            display:grid;
            grid-template-columns:1.5fr 1.1fr;
            gap:30px;
            align-items: start;
        }

        .card{
            background:white;
            border-radius:24px;
            padding:30px;
            box-shadow:0 6px 18px rgba(0,0,0,0.02);
            border:1px solid rgba(240,221,229,0.6);
            box-sizing: border-box;
        }

        .card-title{
            font-family:'Playfair Display',serif;
            font-size:22px;
            margin-bottom:25px;
            color:#1a0a12;
            font-weight: 700;
        }

        .product-item{
            display:flex;
            align-items:center;
            gap:18px;
            padding:20px 0;
            border-bottom:1px solid #f6edf0;
        }

        .product-item:first-child{
            padding-top: 0;
        }

        .product-item:last-child{
            border-bottom:none;
            padding-bottom: 0;
        }

        .product-item img{
            width:85px;
            height:85px;
            border-radius:16px;
            object-fit:cover;
            border:1px solid #f0f0f0;
        }

        .product-info{
            flex:1;
        }

        .product-info h4{
            font-size:16px;
            color:#1a0a12;
            margin: 0 0 8px 0;
            font-weight:700;
        }

        .product-price{
            font-weight:700;
            color:#ff4f81;
            font-size:16px;
            white-space: nowrap;
        }

        /* --- STYLING NOTA / STRUK BELANJA --- */
        .nota-box {
            background: white;
            padding: 30px;
            border-radius: 24px;
            border: 1px solid rgba(240,221,229,0.7);
            box-shadow: 0 8px 24px rgba(0,0,0,0.02);
            box-sizing: border-box;
        }

        .nota-title {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            color: #1a0a12;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px dashed #f0dde5;
            text-align: center;
        }

        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #ff4f81;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            margin-top: 5px;
        }

        /* Data Penerima */
        .customer-details {
            font-size: 14px;
            color: #1a0a12;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .customer-details div span {
            color: #8a6070;
            display: inline-block;
            width: 70px;
        }

        /* Item List Struk */
        .nota-item-container {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .nota-item-row {
            font-size: 14px;
        }

        .nota-item-main {
            display: flex;
            justify-content: space-between;
            color: #1a0a12;
            font-weight: 600;
        }

        .nota-item-name {
            flex: 1;
            padding-right: 10px;
        }

        .nota-item-subtotal-calc {
            font-size: 13px;
            color: #8a6070;
            margin-top: 4px;
            display: flex;
            justify-content: space-between;
        }

        .divider-nota {
            height: 1px;
            border-top: 1px dashed #f0dde5;
            margin: 20px 0;
        }

        /* Ringkasan Biaya */
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            color: #8a6070;
        }

        .total-row-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 16px;
            font-weight: 700;
            background: #ff4f81;
            color: white;
            padding: 14px 20px;
            border-radius: 14px;
            margin: 15px 0;
            box-shadow: 0 6px 16px rgba(255, 79, 129, 0.15);
        }

        .total-row-box .price-grand {
            font-size: 18px;
        }

        .payment-box {
            background: #fdf2f6;
            border: 1px solid #ffd4e1;
            padding: 14px;
            border-radius: 14px;
            margin-bottom: 20px;
        }

        .payment-box h5 {
            color: #ff4f81;
            margin: 0 0 6px 0;
            font-size: 14px;
            font-weight: 700;
        }

        .payment-box p {
            font-size: 13px;
            color: #8a6070;
            line-height: 1.5;
            margin: 0;
        }

        .btn-checkout {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 16px;
            background: #ff4f81; 
            color: white;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: 0 6px 14px rgba(255, 79, 129, 0.2);
        }

        .btn-checkout:hover {
            background: #e63e6d;
            transform: translateY(-2px);
        }

        .empty {
            text-align: center;
            color: #8a6070;
            padding: 30px;
        }

        @media (max-width: 992px) {
            .checkout-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        let harga = [
        <?php
        foreach($items as $item){
            echo $item['harga'] . ",";
        }
        ?>
        ];

        function formatRupiah(angka){
            return new Intl.NumberFormat('id-ID').format(angka);
        }

        function updateSubtotal(input, hargaItem, index){
            let jumlah = parseInt(input.value);
            let subtotal = jumlah * hargaItem;

            document.getElementById('subtotal'+index).innerHTML = 'Rp ' + formatRupiah(subtotal);
            
            let subtotalCalcContainer = document.getElementById('calcRow'+index);
            subtotalCalcContainer.innerHTML = '<span>' + jumlah + 'x @ Rp ' + formatRupiah(hargaItem) + '</span> <b>Rp ' + formatRupiah(subtotal) + '</b>';

            updateTotal();
        }

        function updateTotal(){
            let inputs = document.getElementsByName('jumlah[]');
            let total = 0;
            let totalItem = 0;

            for(let i=0; i<inputs.length; i++){
                let qty = parseInt(inputs[i].value);
                total += qty * harga[i];
                totalItem += qty;
            }

            document.getElementById('grandTotal').innerHTML = 'Rp ' + formatRupiah(total);
            document.getElementById('totalHargaInput').value = total;
            document.getElementById('totalItemCount').innerHTML = totalItem;
        }

        function tambahJumlah(index, hargaItem){
            let input = document.getElementById('jumlah'+index);
            let jumlah = parseInt(input.value);
            jumlah++;
            input.value = jumlah;
            updateSubtotal(input, hargaItem, index);
        }

        function kurangJumlah(index, hargaItem){
            let input = document.getElementById('jumlah'+index);
            let jumlah = parseInt(input.value);
            if(jumlah > 1){
                jumlah--;
                input.value = jumlah;
                updateSubtotal(input, hargaItem, index);
            }
        }
    </script>
</head>

<body>

    <?php include '../includes/sidebar_user.php'; ?>

    <div class="main">
        <div class="header-text">
            <h1>Checkout Pesanan</h1>
            <p>Periksa kembali produk sebelum melanjutkan pembayaran ✨</p>
        </div>

        <form action="proses_checkout.php" method="POST">
            <div class="checkout-grid">

                <!-- KOLOM KIRI: UPDATE PRODUK -->
                <div class="card">
                    <div class="card-title">Produk Dipilih</div>

                    <?php if(count($items) > 0): ?>
                        <?php foreach($items as $key => $item): ?>
                        <div class="product-item">
                            <img src="../assets/gambar/<?= $item['gambar']; ?>" alt="<?= $item['nama_produk']; ?>">
                            
                            <div class="product-info">
                                <h4><?= $item['nama_produk']; ?></h4>
                                
                                <div style="display:flex; align-items:center; gap:10px; margin-top:10px;">
                                    <!-- MINUS -->
                                    <button type="button" onclick="kurangJumlah(<?= $key; ?>, <?= $item['harga']; ?>)" style="width:32px; height:32px; border:none; border-radius:8px; background:#ffe8ee; color:#ff4f81; font-size:18px; cursor:pointer; font-weight:bold;">-</button>

                                    <!-- INPUT QUANTITY -->
                                    <input type="text" name="jumlah[]" id="jumlah<?= $key; ?>" value="<?= $item['jumlah']; ?>" readonly style="width:45px; text-align:center; border:none; background:#fbf6f8; border-radius:8px; padding:8px; font-weight:600; box-sizing: border-box;">

                                    <!-- PLUS -->
                                    <button type="button" onclick="tambahJumlah(<?= $key; ?>, <?= $item['harga']; ?>)" style="width:32px; height:32px; border:none; border-radius:8px; background:#ff4f81; color:white; font-size:18px; cursor:pointer; font-weight:bold;">+</button>

                                    <span style="font-size: 13px; color: #8a6070; margin-left: 5px;">
                                        x Rp <?= number_format($item['harga'],0,',','.'); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="product-price" id="subtotal<?= $key; ?>">
                                Rp <?= number_format($item['harga'] * $item['jumlah'], 0, ',', '.'); ?>
                            </div>
                        </div>

                        <!-- HIDDEN DATA PER ITEM -->
                        <input type="hidden" name="id_produk[]" value="<?= $item['id_produk']; ?>">
                        <input type="hidden" name="harga_satuan[]" value="<?= $item['harga']; ?>">
                        <?php endforeach; ?>

                    <?php else: ?>
                        <div class="empty">Tidak ada produk dipilih</div>
                    <?php endif; ?>
                </div>

                <!-- KOLOM KANAN: SATU BOX NOTA / STRUK INTEGRASI -->
                <div class="nota-box">
                    <div class="nota-title">🧾 Struk Belanja Anda</div>
                    
                    <!-- DATA PENERIMA -->
                    <div class="section-title">📍 Tujuan Pengiriman</div>
                    <div class="customer-details">
                        <div><span>Nama :</span> <b><?= $data_user['nama']; ?></b></div>
                        <div><span>No HP :</span> <?= $data_user['no_hp']; ?></div>
                        <div><span>Alamat :</span> <?= $data_user['alamat']; ?></div>
                    </div>

                    <div class="divider-nota"></div>

                    <!-- DAFTAR BARANG -->
                    <div class="section-title">🛍 Produk Belanja</div>
                    <div class="nota-item-container">
                        <?php
                        $jumlah_item = 0;
                        foreach($items as $key => $item):
                            $subtotal = $item['harga'] * $item['jumlah'];
                            $jumlah_item += $item['jumlah'];
                        ?>
                        <div class="nota-item-row">
                            <div class="nota-item-main">
                                <span class="nota-item-name"><?= $item['nama_produk']; ?></span>
                                <span>Rp <?= number_format($item['harga'], 0, ',', '.'); ?></span>
                            </div>
                            
                            <div class="nota-item-subtotal-calc" id="calcRow<?= $key; ?>">
                                <span><?= $item['jumlah']; ?>x @ Rp <?= number_format($item['harga'], 0, ',', '.'); ?></span>
                                <b>Rp <?= number_format($subtotal, 0, ',', '.'); ?></b>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="divider-nota"></div>

                    <!-- RINGKASAN METODE, ONGKIR, & TOTAL -->
                    <div class="summary-row">
                        <span>Total Qty</span>
                        <span id="totalItemCount" style="font-weight: 600; color: #1a0a12;"><?= $jumlah_item; ?></span>
                    </div>

                    <div class="summary-row">
                        <span>Ongkos Kirim</span>
                        <span style="font-weight: 600; color: #1a0a12;">Rp 0</span>
                    </div>

                    <div class="summary-row">
                        <span>Metode Pembayaran</span>
                        <span style="font-weight: 600; color: #1a0a12;">Transfer Bank</span>
                    </div>

                    <div class="total-row-box">
                        <span>Total Bayar</span>
                        <span id="grandTotal" class="price-grand">Rp <?= number_format($total_bayar, 0, ',', '.'); ?></span>
                    </div>

                    <!-- HIDDEN SYSTEM DATA -->
                    <input type="hidden" name="metode_pembayaran" value="Transfer Bank">
                    <input type="hidden" name="total_harga" id="totalHargaInput" value="<?= $total_bayar; ?>">
                    <input type="hidden" name="alamat" value="<?= $data_user['alamat']; ?>">

                    <!-- INFO BOX DAN TOMBOL CHECKOUT -->
                    <div class="payment-box">
                        <h5>Info Transfer</h5>
                        <p>Silakan tuntaskan proses transfer bank setelah pesanan resmi Anda checkout.</p>
                    </div>

                    <button type="submit" class="btn-checkout">Checkout Sekarang</button>
                </div>

            </div>
        </form>
    </div>

</body>
</html>