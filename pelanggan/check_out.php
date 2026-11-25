<?php
session_start();

require_once __DIR__ . '/../includes/conn.php';

if (!isset($_SESSION['id_pelanggan'])) {
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
if (isset($_POST['id_produk'])) {

    $id_produk = $_POST['id_produk'];

    $query = mysqli_query($conn, "
        SELECT *
        FROM produk
        WHERE id_produk='$id_produk'
    ");

    $produk = mysqli_fetch_assoc($query);

    if (!$produk) {
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
elseif (isset($_POST['pilih_produk'])) {

    foreach ($_POST['pilih_produk'] as $id_produk) {

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

        if ($row) {
            $items[] = $row;
        }
    }
} else {
    die("Produk tidak ditemukan");
}



// ======================================
// TOTAL
// ======================================
foreach ($items as $item) {
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/global.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/sidebar.css">

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
            box-sizing: border-box;
        }

        .header-text h1 {
            font-family: 'Playfair Display', serif;
            font-size: 34px;
            color: #1a0a12;
            margin-bottom: 8px;
            margin-top: 0;
        }

        .header-text p {
            color: #8a6070;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .checkout-grid {
            display: grid;
            grid-template-columns: 1.5fr 1.1fr;
            gap: 30px;
            align-items: start;
        }

        .card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.02);
            border: 1px solid rgba(240, 221, 229, 0.6);
            box-sizing: border-box;
        }

        .card-title {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            margin-bottom: 25px;
            color: #1a0a12;
            font-weight: 700;
        }

        .product-item {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 20px 0;
            border-bottom: 1px solid #f6edf0;
        }

        .product-item:first-child {
            padding-top: 0;
        }

        .product-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .product-item img {
            width: 85px;
            height: 85px;
            border-radius: 16px;
            object-fit: cover;
            border: 1px solid #f0f0f0;
        }

        .product-info {
            flex: 1;
        }

        .product-info h4 {
            font-size: 16px;
            color: #1a0a12;
            margin: 0 0 8px 0;
            font-weight: 700;
        }

        .product-price {
            font-weight: 700;
            color: #ff4f81;
            font-size: 16px;
            white-space: nowrap;
        }

        /* --- STYLING NOTA / STRUK BELANJA --- */
        .nota-box {
            background: white;
            padding: 30px;
            border-radius: 24px;
            border: 1px solid rgba(240, 221, 229, 0.7);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.02);
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
            foreach ($items as $item) {
                echo $item['harga'] . ",";
            }
            ?>
        ];

        function formatRupiah(angka) {
            return new Intl.NumberFormat('id-ID').format(angka);
        }

        function updateSubtotal(input, hargaItem, index) {
            let jumlah = parseInt(input.value);
            let subtotal = jumlah * hargaItem;

            document.getElementById('subtotal' + index).innerHTML = 'Rp ' + formatRupiah(subtotal);

            let subtotalCalcContainer = document.getElementById('calcRow' + index);
            subtotalCalcContainer.innerHTML = '<span>' + jumlah + 'x @ Rp ' + formatRupiah(hargaItem) + '</span> <b>Rp ' + formatRupiah(subtotal) + '</b>';

            updateTotal();
        }

        let daftarTarif = [];
        let ongkirTerpilih = null;

        function hitungTotalProduk() {
            const inputs = document.getElementsByName('jumlah[]');
            let total = 0;

            for (let i = 0; i < inputs.length; i++) {
                total += Number(inputs[i].value) * Number(harga[i]);
            }

            return total;
        }

        function tampilkanTotal() {
            const grandTotal = document.getElementById('grandTotal');
            const totalInput = document.getElementById('totalHargaInput');

            if (ongkirTerpilih === null) {
                grandTotal.textContent = 'Belum dihitung';
                totalInput.value = '';
                return;
            }

            const total = hitungTotalProduk() + ongkirTerpilih;

            grandTotal.textContent = 'Rp ' + formatRupiah(total);
            totalInput.value = total;
        }


        function updateTotal() {
            const inputs = document.getElementsByName('jumlah[]');
            let totalItem = 0;

            for (const input of inputs) {
                totalItem += Number(input.value);
            }

            document.getElementById('totalItemCount').textContent =
                totalItem;

            // Perubahan jumlah barang membatalkan tarif lama
            ongkirTerpilih = null;
            daftarTarif = [];

            const pilihan = document.getElementById('pilihanOngkir');
            pilihan.innerHTML =
                '<option value="">Pilih layanan pengiriman</option>';
            pilihan.disabled = true;

            document.getElementById('ongkirDisplay').textContent =
                'Belum dipilih';

            document.getElementById('pesanOngkir').textContent =
                'Jumlah berubah. Silakan cek ongkir kembali.';

            tampilkanTotal();
        }


        function tambahJumlah(index, hargaItem) {
            let input = document.getElementById('jumlah' + index);
            let jumlah = parseInt(input.value);
            jumlah++;
            input.value = jumlah;
            updateSubtotal(input, hargaItem, index);
        }

        function kurangJumlah(index, hargaItem) {
            let input = document.getElementById('jumlah' + index);
            let jumlah = parseInt(input.value);
            if (jumlah > 1) {
                jumlah--;
                input.value = jumlah;
                updateSubtotal(input, hargaItem, index);
            }
        }
    </script>
</head>

<body>

    <?php require_once __DIR__ . '/../includes/sidebar_user.php'; ?>

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

                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $key => $item): ?>
                            <div class="product-item">
                                <img src="<?= BASE_URL ?>/assets/gambar/<?= $item['gambar']; ?>" alt="<?= htmlspecialchars($item['nama_produk']); ?>">

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
                                            x Rp <?= number_format($item['harga'], 0, ',', '.'); ?>
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


                    <!-- PILIH LOKASI TUJUAN -->
                    <div class="section-title">Cek Ongkos Kirim</div>

                    <div style="margin-bottom:15px;">
                        <label for="cariTujuan">
                            Cari Kecamatan / Kelurahan
                        </label>

                        <input
                            type="text"
                            id="cariTujuan"
                            placeholder="Contoh: Surabaya Timur"
                            autocomplete="off"
                            maxlength="60"
                            style="width:100%;padding:12px;box-sizing:border-box;">

                        <!-- Hasil pencarian dari RajaOngkir -->
                        <select
                            id="daftarLokasi"
                            size="5"
                            hidden
                            aria-label="Hasil pencarian lokasi"
                            style="width:100%;padding:10px;margin-top:8px;"></select>

                        <!-- ID disimpan secara internal -->
                        <input
                            type="hidden"
                            id="idTujuan"
                            value="">

                        <p id="statusLokasi" role="status"
                            style="font-size:13px;color:#777;">
                            Ketik minimal 3 karakter untuk mencari lokasi.
                        </p>
                        <button
                            type="button"
                            id="btnCekOngkir"
                            style="margin-top:10px;padding:12px;">
                            Cek Ongkir
                        </button>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label for="pilihanOngkir">Layanan Pengiriman</label>

                        <select
                            id="pilihanOngkir"
                            disabled
                            style="width:100%;padding:12px;">
                            <option value="">Pilih layanan pengiriman</option>
                        </select>
                    </div>

                    <p id="pesanOngkir" role="status">
                        Masukkan ID tujuan untuk melihat ongkir.
                    </p>


                    <div class="divider-nota"></div>

                    <!-- DAFTAR BARANG -->
                    <div class="section-title">🛍 Produk Belanja</div>
                    <div class="nota-item-container">
                        <?php
                        $jumlah_item = 0;
                        foreach ($items as $key => $item):
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
                        <span style="font-weight: 600; color: #1a0a12;" id="ongkirDisplay">Belum Dipilih</span>
                    </div>

                    <div class="summary-row">
                        <span>Metode Pembayaran</span>
                        <span style="font-weight: 600; color: #1a0a12;">Transfer Bank</span>
                    </div>

                    <div class="total-row-box">
                        <span>Total Bayar</span>
                        <span id="grandTotal" class="price-grand">Belum dihitung <?= number_format($total_bayar, 0, ',', '.'); ?></span>
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

        <script>
            const inputTujuan = document.getElementById('idTujuan');
            const btnCek = document.getElementById('btnCekOngkir');
            const pilihan = document.getElementById('pilihanOngkir');
            const pesan = document.getElementById('pesanOngkir');

            function resetOngkir() {

                const quoteInput = document.getElementById('quoteId');

                if (quoteInput) {
                    quoteInput.value = '';
                }
                ongkirTerpilih = null;
                daftarTarif = [];

                pilihan.innerHTML =
                    '<option value="">Pilih layanan pengiriman</option>';
                pilihan.disabled = true;

                document.getElementById('ongkirDisplay').textContent =
                    'Belum dipilih';

                tampilkanTotal();
            }

            inputTujuan.addEventListener('input', () => {
                resetOngkir();
                pesan.textContent = 'Silakan cek ongkir.';
            });

            btnCek.addEventListener('click', async function() {
                const destination = inputTujuan.value.trim();

                resetOngkir();

                if (!/^[1-9]\d*$/.test(destination)) {
                    pesan.textContent = 'Masukkan ID tujuan yang valid.';
                    return;
                }

                btnCek.disabled = true;
                pesan.textContent = 'Mengambil tarif RajaOngkir...';

                try {
                    const response = await fetch('cek_ongkir.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            destination
                        })
                    });

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(
                            result.message || 'Gagal mengambil ongkir.'
                        );
                    }

                    // Abaikan respons jika tujuan sudah berubah
                    if (inputTujuan.value.trim() !== destination) {
                        return;
                    }

                    daftarTarif = result.data.filter(item =>
                        Number.isSafeInteger(Number(item.ongkir)) &&
                        Number(item.ongkir) >= 0
                    );

                    if (daftarTarif.length === 0) {
                        throw new Error('Tidak ada tarif tersedia.');
                    }

                    daftarTarif.forEach((item, index) => {
                        const option = document.createElement('option');
                        option.value = String(index);
                        option.textContent =
                            `${item.kurir} - ${item.service} | ` +
                            `Rp ${formatRupiah(item.ongkir)} | ` +
                            `${item.estimasi}`;

                        pilihan.appendChild(option);
                    });

                    pilihan.disabled = false;
                    pesan.textContent = 'Silakan pilih layanan pengiriman.';

                } catch (error) {
                    pesan.textContent = error.message;
                } finally {
                    btnCek.disabled = false;
                }
            });

            pilihan.addEventListener('change', function() {
                if (this.value === '') {
                    ongkirTerpilih = null;
                    document.getElementById('ongkirDisplay').textContent =
                        'Belum dipilih';
                    tampilkanTotal();
                    return;
                }

                const item = daftarTarif[Number(this.value)];

                if (!item) return;

                ongkirTerpilih = Number(item.ongkir);

                document.getElementById('ongkirDisplay').textContent =
                    'Rp ' + formatRupiah(ongkirTerpilih);

                tampilkanTotal();
            });
        </script>

    </div>

    <script>
        (() => {
            const cari = document.getElementById('cariTujuan');
            const daftar = document.getElementById('daftarLokasi');
            const idTujuan = document.getElementById('idTujuan');
            const status = document.getElementById('statusLokasi');

            let timer;
            let controller;
            let versiPencarian = 0;

            // Mengosongkan tujuan dan tarif sebelumnya
            function resetTujuan() {
                idTujuan.value = '';

                // Memicu reset ongkir pada script sebelumnya
                idTujuan.dispatchEvent(
                    new Event('input', {
                        bubbles: true
                    })
                );
            }

            cari.addEventListener('input', () => {
                clearTimeout(timer);

                if (controller) {
                    controller.abort();
                }

                const versi = ++versiPencarian;
                const keyword = cari.value.trim();

                resetTujuan();
                daftar.replaceChildren();
                daftar.hidden = true;

                if (keyword.length < 3) {
                    status.textContent =
                        'Ketik minimal 3 karakter.';
                    return;
                }

                status.textContent = 'Mencari lokasi...';

                // Jeda agar tidak mengirim API setiap karakter
                timer = setTimeout(async () => {
                    controller = new AbortController();

                    try {
                        const response = await fetch(
                            'cari_lokasi.php?q=' +
                            encodeURIComponent(keyword), {
                                signal: controller.signal,
                                cache: 'no-store'
                            }
                        );

                        const result = await response.json();

                        if (versi !== versiPencarian) return;

                        if (!response.ok || !result.success) {
                            throw new Error(
                                result.message || 'Pencarian gagal.'
                            );
                        }

                        const lokasi = result.data || [];

                        if (!Array.isArray(lokasi) ||
                            lokasi.length === 0) {
                            status.textContent =
                                'Lokasi tidak ditemukan.';
                            return;
                        }

                        const awal = document.createElement('option');
                        awal.textContent = '-- Pilih lokasi --';
                        awal.value = '';
                        awal.disabled = true;
                        awal.selected = true;
                        daftar.appendChild(awal);

                        lokasi.forEach(item => {
                            const id = Number(item.id);

                            if (!Number.isSafeInteger(id) || id <= 0) {
                                return;
                            }

                            const option = document.createElement('option');
                            option.value = String(id);
                            option.textContent = String(item.label);

                            daftar.appendChild(option);
                        });

                        daftar.hidden = false;
                        status.textContent =
                            'Pilih lokasi yang sesuai dari daftar.';

                    } catch (error) {
                        if (error.name === 'AbortError') return;

                        if (versi === versiPencarian) {
                            status.textContent =
                                'Gagal mencari lokasi. Coba lagi.';
                        }
                    }
                }, 500);
            });

            daftar.addEventListener('change', () => {
                const pilihan = daftar.selectedOptions[0];

                if (!pilihan || !pilihan.value) return;

                cari.value = pilihan.textContent;
                daftar.hidden = true;

                // Simpan ID tanpa meminta pelanggan mengetiknya
                idTujuan.value = pilihan.value;

                idTujuan.dispatchEvent(
                    new Event('input', {
                        bubbles: true
                    })
                );

                status.textContent =
                    'Lokasi terpilih: ' + pilihan.textContent;
            });
        })();
    </script>


</body>

</html>