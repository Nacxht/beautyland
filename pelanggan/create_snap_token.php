<?php

declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// =============================================================================
// Helper: Penanganan Respons Transaksi Idempotent yang Sudah Ada
// =============================================================================
function handle_existing_transaction_response(mysqli $conn, array $trx): void
{
    $status_bayar = $trx['status_pembayaran'] ?? '';
    $snap_status  = $trx['snap_status'] ?? '';
    $snap_token   = $trx['snap_token'] ?? '';
    $expires_at   = $trx['snap_expires_at'] ?? null;
    $order_id     = $trx['midtrans_order_id'] ?? '';
    $updated_at   = $trx['updated_at'] ?? '';

    if ($status_bayar === 'Lunas') {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Pesanan ini telah lunas dibayar.'
        ]);
        exit;
    }

    if ($status_bayar === 'Dibatalkan') {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Pesanan ini telah dibatalkan. Silakan lakukan checkout baru.'
        ]);
        exit;
    }

    if ($status_bayar === 'Refund') {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Pesanan ini telah di-refund.'
        ]);
        exit;
    }

    // Jika token sudah berhasil diterbitkan dan belum kedaluwarsa, kembalikan token yang sama
    if ($snap_status === 'Berhasil' && !empty($snap_token)) {
        if ($expires_at === null || strtotime((string)$expires_at) > time()) {
            ob_clean();
            echo json_encode([
                'success'      => true,
                'snap_token'   => $snap_token,
                'order_id'     => $order_id,
                'redirect_url' => '',
                'idempotent'   => true
            ]);
            exit;
        }

        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Sesi pembayaran Midtrans untuk pesanan ini telah kedaluwarsa. Silakan lakukan checkout baru.'
        ]);
        exit;
    }

    if ($snap_status === 'Menunggu') {
        $time_diff = $updated_at !== '' ? (time() - strtotime((string)$updated_at)) : 0;
        if ($time_diff >= 120) {
            // W-01: Transaksi tertahan di 'Menunggu' > 2 menit -> tandai 'Perlu Rekonsiliasi'
            // F-01: Lindungi agar hanya memperbarui jika status pembayaran belum terminal
            $stmt_mark_stuck = $conn->prepare("
                UPDATE transaksi 
                SET snap_status = 'Perlu Rekonsiliasi' 
                WHERE id_transaksi = ? AND snap_status = 'Menunggu' AND status_pembayaran = 'Menunggu Pembayaran'
            ");
            if ($stmt_mark_stuck) {
                $id_trx = (int)$trx['id_transaksi'];
                $stmt_mark_stuck->bind_param("i", $id_trx);
                $stmt_mark_stuck->execute();
                $stmt_mark_stuck->close();
            }

            ob_clean();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Transaksi sebelumnya terhenti dan telah ditandai untuk rekonsiliasi. Reservasi stok Anda tetap aman. Silakan periksa status pesanan Anda.'
            ]);
            exit;
        }

        ob_clean();
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Sesi pembayaran sedang diproses oleh sistem. Mohon tunggu sejenak.'
        ]);
        exit;
    }

    if ($snap_status === 'Perlu Rekonsiliasi') {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Transaksi ini membutuhkan rekonsiliasi gateway pembayaran. Mohon periksa riwayat pesanan Anda atau hubungi dukungan.'
        ]);
        exit;
    }

    if ($snap_status === 'Gagal') {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Sesi pembayaran sebelumnya gagal diinisiasi. Silakan lakukan checkout ulang.'
        ]);
        exit;
    }

    ob_clean();
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Status transaksi saat ini tidak memungkinkan inisiasi token pembayaran baru.'
    ]);
    exit;
}

// =============================================================================
// 1. Validasi Metode HTTP
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metode request tidak diizinkan.'
    ]);
    exit;
}

// =============================================================================
// 2. Validasi Autentikasi Pelanggan
// =============================================================================
if (!isset($_SESSION['id_pelanggan'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Silakan login terlebih dahulu.'
    ]);
    exit;
}

$id_pelanggan = (int)$_SESSION['id_pelanggan'];

// =============================================================================
// 3. Validasi Session Pending Order & Kepemilikan (Ownership)
// =============================================================================
if (
    !isset($_SESSION['pending_order']) ||
    !is_array($_SESSION['pending_order']) ||
    empty($_SESSION['pending_order']['items']) ||
    !is_array($_SESSION['pending_order']['items'])
) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Data pesanan tidak ditemukan atau telah kedaluwarsa. Silakan lakukan checkout ulang.'
    ]);
    exit;
}

// Validasi eksplisit: pending_order harus milik pelanggan yang sedang login
$pending_owner = (int)($_SESSION['pending_order']['id_pelanggan'] ?? 0);
if ($pending_owner !== $id_pelanggan) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Sesi pesanan tidak valid untuk akun yang sedang aktif.'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/stock_reservation.php';

try {
    require_once __DIR__ . '/../includes/midtrans.php';
} catch (\Throwable $e) {
    error_log("Midtrans configuration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Konfigurasi gateway pembayaran belum lengkap.'
    ]);
    exit;
}

// =============================================================================
// 4. Validasi Profil Pelanggan (Strict Validation - Tanpa Fallback Dummy)
// =============================================================================
$stmt_cust = $conn->prepare("SELECT nama, email, no_hp, alamat FROM pelanggan WHERE id_pelanggan = ?");
if (!$stmt_cust) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal memverifikasi data pelanggan.']);
    exit;
}
$stmt_cust->bind_param("i", $id_pelanggan);
$stmt_cust->execute();
$cust = $stmt_cust->get_result()->fetch_assoc();
$stmt_cust->close();

if (!$cust) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
    exit;
}

$customer_name  = trim((string)($cust['nama'] ?? ''));
$customer_email = trim((string)($cust['email'] ?? ''));
$customer_phone = trim((string)($cust['no_hp'] ?? ''));

// Prioritaskan alamat dari session jika diisi saat checkout, fallback ke profil
$alamat_pengiriman = trim((string)($_SESSION['pending_order']['alamat'] ?? ''));
if ($alamat_pengiriman === '' || $alamat_pengiriman === 'Alamat belum diisi') {
    $alamat_pengiriman = trim((string)($cust['alamat'] ?? ''));
}

$clean_phone = preg_replace('/[\s\-]/', '', $customer_phone);

if ($customer_name === '' || mb_strlen($customer_name) < 2) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Nama lengkap pelanggan belum diisi pada profil akun Anda.']);
    exit;
}

if (!filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Alamat email pelanggan tidak valid. Silakan perbarui di profil akun Anda.']);
    exit;
}

if ($clean_phone === '' || !preg_match('/^[0-9+]{9,16}$/', $clean_phone)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Nomor HP pelanggan tidak valid atau belum diisi pada profil akun Anda.']);
    exit;
}

if ($alamat_pengiriman === '' || $alamat_pengiriman === 'Alamat belum diisi' || mb_strlen($alamat_pengiriman) < 5) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Alamat pengiriman belum diisi. Silakan isi alamat pengiriman dengan lengkap.']);
    exit;
}

// =============================================================================
// 5. Idempotency Check (Pencegahan Double-Submit & Re-use Token Aktif)
// =============================================================================
$idempotency_key = trim((string)($_SESSION['pending_order']['checkout_idempotency_key'] ?? ''));
if ($idempotency_key === '') {
    $idempotency_key = bin2hex(random_bytes(16));
    $_SESSION['pending_order']['checkout_idempotency_key'] = $idempotency_key;
}

// Periksa apakah transaksi dengan idempotency key ini sudah pernah dicatat di database
$stmt_check_idem = $conn->prepare("
    SELECT id_transaksi, midtrans_order_id, snap_token, snap_status, snap_expires_at, total_harga, status_pembayaran, updated_at
    FROM transaksi 
    WHERE checkout_idempotency_key = ?
");
$stmt_check_idem->bind_param("s", $idempotency_key);
$stmt_check_idem->execute();
$existing_trx = $stmt_check_idem->get_result()->fetch_assoc();
$stmt_check_idem->close();

if ($existing_trx) {
    handle_existing_transaction_response($conn, $existing_trx);
}

// =============================================================================
// 6. Validasi Kuantitas & Ekstraksi Item
// =============================================================================
$items_raw = $_SESSION['pending_order']['items'];
$parsed_items = [];

foreach ($items_raw as $item) {
    $id_prod = isset($item['id_produk']) ? (int)$item['id_produk'] : 0;
    $qty     = isset($item['jumlah']) ? (int)$item['jumlah'] : 0;

    if ($id_prod <= 0 || $qty <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Data produk atau kuantitas tidak valid.']);
        exit;
    }

    $parsed_items[] = [
        'id_produk' => $id_prod,
        'jumlah'    => $qty,
    ];
}

// Urutkan item berdasarkan id_produk ASC untuk mencegah deadlock saat row-locking
usort($parsed_items, static function (array $a, array $b): int {
    return ($a['id_produk'] <=> $b['id_produk']);
});

// Durasi kedaluwarsa transaksi dan reservasi (default 24 jam / 86400 detik)
$expiry_duration = (int)($_ENV['MIDTRANS_EXPIRY_DURATION'] ?? 86400);
if ($expiry_duration <= 0) {
    $expiry_duration = 86400;
}
$snap_expires_at = date('Y-m-d H:i:s', time() + $expiry_duration);

$kode_pembayaran = (string)($_SESSION['pending_order']['kode_pembayaran'] ?? ("MB-" . strtoupper(substr(md5(rand()), 0, 8))));

// =============================================================================
// 7. TAHAP 1: PERSISTENSI LOKAL & RESERVASI STOK (Atomik MySQL Transaction)
//    Koneksi MySQL akan di-COMMIT SEBELUM melakukan panggilan HTTP ke Midtrans.
// =============================================================================
$id_transaksi      = 0;
$midtrans_order_id = '';
$total_harga       = 0;
$verified_items    = [];

$conn->begin_transaction();

try {
    // 7a. Lock baris produk dan hitung ulang harga resmi dari database
    $stmt_lock = $conn->prepare("
        SELECT id_produk, nama_produk, harga, stok, stok_direservasi 
        FROM produk 
        WHERE id_produk = ? 
        FOR UPDATE
    ");

    foreach ($parsed_items as $p_item) {
        $stmt_lock->bind_param("i", $p_item['id_produk']);
        $stmt_lock->execute();
        $prod_data = $stmt_lock->get_result()->fetch_assoc();

        if (!$prod_data) {
            throw new RuntimeException("Produk dengan ID {$p_item['id_produk']} tidak ditemukan.");
        }

        $harga_satuan = (int)$prod_data['harga'];
        $subtotal     = $p_item['jumlah'] * $harga_satuan;
        $total_harga += $subtotal;

        $verified_items[] = [
            'id_produk'    => (int)$prod_data['id_produk'],
            'nama_produk'  => (string)$prod_data['nama_produk'],
            'harga_satuan' => $harga_satuan,
            'jumlah'       => $p_item['jumlah'],
            'subtotal'     => $subtotal,
        ];
    }
    $stmt_lock->close();

    // 7b. Simpan record transaksi dengan status 'Menunggu'
    $metode_pembayaran = 'Midtrans';
    $status_pembayaran = 'Menunggu Pembayaran';
    $snap_status_awal  = 'Menunggu';
    $stok_dikurangi    = 0;

    $stmt_trx = $conn->prepare("
        INSERT INTO transaksi (
            id_pelanggan,
            total_harga,
            metode_pembayaran,
            kode_pembayaran,
            status_pembayaran,
            stok_dikurangi,
            checkout_idempotency_key,
            snap_status,
            snap_expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_trx->bind_param(
        "iisssisss",
        $id_pelanggan,
        $total_harga,
        $metode_pembayaran,
        $kode_pembayaran,
        $status_pembayaran,
        $stok_dikurangi,
        $idempotency_key,
        $snap_status_awal,
        $snap_expires_at
    );
    $stmt_trx->execute();
    $id_transaksi = (int)$conn->insert_id;
    $stmt_trx->close();

    // 7c. Bentuk midtrans_order_id yang stabil dan unik
    $midtrans_order_id = "BL-" . $id_transaksi . "-" . time();
    $stmt_order_id = $conn->prepare("UPDATE transaksi SET midtrans_order_id = ? WHERE id_transaksi = ?");
    $stmt_order_id->bind_param("si", $midtrans_order_id, $id_transaksi);
    $stmt_order_id->execute();
    $stmt_order_id->close();

    // 7d. Lakukan reservasi stok atomik menggunakan helper
    $res_result = reserve_stock($conn, $id_transaksi, $verified_items, $expiry_duration);
    if (!$res_result['success']) {
        throw new DomainException($res_result['message'] ?? 'Stok tidak mencukupi untuk reservasi.');
    }

    // 7e. Insert detail_transaksi
    $stmt_detail = $conn->prepare("
        INSERT INTO detail_transaksi (
            id_transaksi,
            id_produk,
            jumlah,
            harga_satuan,
            subtotal
        ) VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($verified_items as $vi) {
        $stmt_detail->bind_param(
            "iiiii",
            $id_transaksi,
            $vi['id_produk'],
            $vi['jumlah'],
            $vi['harga_satuan'],
            $vi['subtotal']
        );
        $stmt_detail->execute();
    }
    $stmt_detail->close();

    // 7f. Insert pengiriman
    $status_pengiriman = 'Belum Diproses';
    $stmt_ship = $conn->prepare("
        INSERT INTO pengiriman (
            id_transaksi,
            alamat_tujuan,
            status_pengiriman
        ) VALUES (?, ?, ?)
    ");
    $stmt_ship->bind_param("iss", $id_transaksi, $alamat_pengiriman, $status_pengiriman);
    $stmt_ship->execute();
    $stmt_ship->close();

    // 7g. COMMIT transaksi lokal. Database SELESAI dipersiapkan dan kunci dilepas!
    $conn->commit();

} catch (DomainException $e) {
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;

} catch (\Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
        $conn->rollback();
    }

    // =========================================================================
    // B-01: Penanganan Khusus Duplicate Key pada Request Paralel
    // =========================================================================
    $is_duplicate_key = ($conn->errno === 1062 || (int)$e->getCode() === 1062);
    $is_idempotency_dup = $is_duplicate_key && (
        strpos($e->getMessage(), 'checkout_idempotency_key') !== false ||
        strpos($e->getMessage(), 'idx_checkout_idempotency_key') !== false
    );

    if ($is_idempotency_dup) {
        // Query ulang transaksi yang dibuat oleh request paralel pertama
        $stmt_existing = $conn->prepare("
            SELECT id_transaksi, id_pelanggan, midtrans_order_id, snap_token, snap_status, snap_expires_at, total_harga, status_pembayaran, updated_at
            FROM transaksi 
            WHERE checkout_idempotency_key = ?
        ");
        if ($stmt_existing) {
            $stmt_existing->bind_param("s", $idempotency_key);
            $stmt_existing->execute();
            $dup_trx = $stmt_existing->get_result()->fetch_assoc();
            $stmt_existing->close();

            // Pastikan transaksi yang ditemukan memang milik pelanggan yang sedang login
            if ($dup_trx && (int)$dup_trx['id_pelanggan'] === $id_pelanggan) {
                handle_existing_transaction_response($conn, $dup_trx);
            }
        }

        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Konflik konkurensi pada pesanan. Silakan muat ulang halaman pesanan Anda.'
        ]);
        exit;
    }

    error_log("Gagal persistensi lokal checkout: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat memproses pesanan Anda. Silakan coba kembali.'
    ]);
    exit;
}

// =============================================================================
// 8. TAHAP 2: REQUEST MIDTRANS SNAP API (Koneksi DB Bebas Transaksi)
// =============================================================================
$item_details = [];
foreach ($verified_items as $vi) {
    $item_details[] = [
        'id'       => (string)$vi['id_produk'],
        'price'    => (int)$vi['harga_satuan'],
        'quantity' => (int)$vi['jumlah'],
        'name'     => mb_substr($vi['nama_produk'], 0, 50),
    ];
}

$expiry_minutes = max(1, (int)ceil($expiry_duration / 60));

$params = [
    'transaction_details' => [
        'order_id'     => $midtrans_order_id,
        'gross_amount' => $total_harga,
    ],
    'item_details' => $item_details,
    'customer_details' => [
        'first_name'       => $customer_name,
        'email'            => $customer_email,
        'phone'            => $clean_phone,
        'shipping_address' => [
            'first_name' => $customer_name,
            'phone'      => $clean_phone,
            'address'    => $alamat_pengiriman,
        ],
    ],
    'expiry' => [
        'start_time' => date('Y-m-d H:i:s O'),
        'unit'       => 'minute',
        'duration'   => $expiry_minutes,
    ],
];

// Pisahkan secara tegas penanganan exception pemanggilan Midtrans vs penyimpanan DB
$snapToken   = '';
$redirectUrl = '';

try {
    $snapResponse = \Midtrans\Snap::createTransaction($params);
    $snapToken    = $snapResponse->token ?? '';
    $redirectUrl  = $snapResponse->redirect_url ?? '';

    if (empty($snapToken)) {
        throw new RuntimeException("Respons Midtrans tidak memuat Snap Token.");
    }
} catch (\Throwable $e) {
    error_log("Midtrans Snap Request Exception untuk order [{$midtrans_order_id}]: " . $e->getMessage());

    $err_code = (int)$e->getCode();
    $err_msg  = strtolower($e->getMessage());

    // Deteksi apakah kegagalan merupakan network timeout / ambiguitas transmisi (misal cURL 28)
    $is_timeout = ($err_code === 28 || strpos($err_msg, 'timed out') !== false || strpos($err_msg, 'timeout') !== false);

    if ($is_timeout) {
        // =====================================================================
        // TAHAP 3B: KEGAGALAN AMBIGU / TIMEOUT
        // Jangan lepaskan reservasi; tandai transaksi membutuhkan rekonsiliasi.
        // F-01: Pastikan hanya memperbarui jika status pembayaran belum terminal.
        // =====================================================================
        $stmt_recon = $conn->prepare("
            UPDATE transaksi 
            SET snap_status = 'Perlu Rekonsiliasi' 
            WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
        ");
        if ($stmt_recon) {
            $stmt_recon->bind_param("i", $id_transaksi);
            $stmt_recon->execute();
            $stmt_recon->close();
        }

        ob_clean();
        http_response_code(504);
        echo json_encode([
            'success' => false,
            'message' => 'Gateway pembayaran mengalami kendala waktu (timeout). Pesanan Anda telah tercatat dan membutuhkan rekonsiliasi. Mohon periksa riwayat pesanan Anda sebelum mencoba kembali.'
        ]);
        exit;
    }

    // =========================================================================
    // TAHAP 3C: KEGAGALAN DEFINITIF SEBELUM TRANSAKSI DIBUAT DI MIDTRANS
    // Tandai status 'Gagal' dan lepaskan reservasi stok secara atomik.
    // F-01: Pastikan hanya memperbarui jika status pembayaran belum terminal.
    // =========================================================================
    $release_succeeded = false;
    $conn->begin_transaction();
    try {
        $stmt_lock_trx = $conn->prepare("
            SELECT id_transaksi, status_pembayaran 
            FROM transaksi 
            WHERE id_transaksi = ? 
            FOR UPDATE
        ");
        if (!$stmt_lock_trx) {
            throw new RuntimeException("Gagal mempersiapkan kueri lock transaksi untuk Tahap 3C.");
        }
        $stmt_lock_trx->bind_param("i", $id_transaksi);
        $stmt_lock_trx->execute();
        $locked_trx = $stmt_lock_trx->get_result()->fetch_assoc();
        $stmt_lock_trx->close();

        if (!$locked_trx) {
            throw new RuntimeException("Transaksi [{$id_transaksi}] tidak ditemukan untuk Tahap 3C.");
        }

        $locked_pay_status = (string)($locked_trx['status_pembayaran'] ?? '');
        if (in_array($locked_pay_status, ['Lunas', 'Dibatalkan', 'Refund'], true)) {
            $conn->rollback();

            ob_clean();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Status pesanan telah berubah. Silakan periksa riwayat pesanan Anda.'
            ]);
            exit;
        }

        $rel = release_stock_reservation($conn, $id_transaksi, true);
        if (!$rel['success']) {
            throw new RuntimeException("Release reservasi gagal: " . ($rel['message'] ?? 'Tidak diketahui'));
        }

        $stmt_fail = $conn->prepare("
            UPDATE transaksi 
            SET snap_status = 'Gagal' 
            WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
        ");
        if (!$stmt_fail) {
            throw new RuntimeException("Gagal mempersiapkan kueri pembaruan snap_status.");
        }
        $stmt_fail->bind_param("i", $id_transaksi);
        $stmt_fail->execute();
        $stmt_fail->close();

        $conn->commit();
        $release_succeeded = true;
    } catch (\Throwable $rel_err) {
        if ($conn instanceof mysqli && $conn->connect_errno === 0) {
            $conn->rollback();
        }
        error_log("CRITICAL [SNAP_FAIL_RELEASE_FAILED]: Gagal melepas reservasi dan menandai status gagal untuk transaksi [{$id_transaksi}]: " . $rel_err->getMessage());

        try {
            if ($conn instanceof mysqli && $conn->connect_errno === 0 && @$conn->ping()) {
                $stmt_recon = $conn->prepare("
                    UPDATE transaksi 
                    SET snap_status = 'Perlu Rekonsiliasi' 
                    WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
                ");
                if ($stmt_recon) {
                    $stmt_recon->bind_param("i", $id_transaksi);
                    $stmt_recon->execute();
                    $stmt_recon->close();
                }
            }
        } catch (\Throwable $ignored) {
        }
    }

    if ($release_succeeded && isset($_SESSION['pending_order'])) {
        $_SESSION['pending_order']['checkout_idempotency_key'] = bin2hex(random_bytes(16));
    }

    ob_clean();
    if ($release_succeeded) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menginisiasi pembayaran dengan gateway Midtrans. Silakan periksa kembali pesanan Anda atau ulangi beberapa saat lagi.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menginisiasi pembayaran dan gagal melepaskan reservasi stok. Pesanan Anda memerlukan rekonsiliasi. Mohon periksa riwayat pesanan atau hubungi dukungan.'
        ]);
    }
    exit;
}

// =============================================================================
// 9. TAHAP 3A: MIDTRANS SUKSES — SIMPAN TOKEN KE DATABASE (W-02 & F-01)
//    F-01: Lindungi status pembayaran terminal dari penimpaan respons terlambat
//    W-02: Jika penyimpanan token gagal di sini, JANGAN lepas reservasi!
// =============================================================================
$conn->begin_transaction();

try {
    // F-01: Kunci baris transaksi dan periksa status terkini sebelum memperbarui
    $stmt_current = $conn->prepare("
        SELECT id_transaksi, status_pembayaran, snap_status, snap_token 
        FROM transaksi 
        WHERE id_transaksi = ? 
        FOR UPDATE
    ");
    if (!$stmt_current) {
        throw new RuntimeException("Gagal mempersiapkan kueri pengecekan status transaksi.");
    }
    $stmt_current->bind_param("i", $id_transaksi);
    $stmt_current->execute();
    $current_trx = $stmt_current->get_result()->fetch_assoc();
    $stmt_current->close();

    if (!$current_trx) {
        throw new RuntimeException("Transaksi ID [{$id_transaksi}] tidak ditemukan saat penyimpanan token.");
    }

    $current_pay_status = (string)($current_trx['status_pembayaran'] ?? '');

    // Skenario 2: Transaksi sudah 'Lunas' ketika respons Midtrans diterima
    if ($current_pay_status === 'Lunas') {
        // Simpan token untuk audit jika belum tersimpan, tanpa mengubah status pembayaran
        if (empty($current_trx['snap_token'])) {
            $stmt_audit = $conn->prepare("UPDATE transaksi SET snap_token = ? WHERE id_transaksi = ?");
            if ($stmt_audit) {
                $stmt_audit->bind_param("si", $snapToken, $id_transaksi);
                $stmt_audit->execute();
                $stmt_audit->close();
            }
        }
        $conn->commit();

        // Hapus item dari keranjang karena pesanan sudah lunas
        $stmt_cart = $conn->prepare("DELETE FROM keranjang WHERE id_pelanggan = ? AND id_produk = ?");
        if ($stmt_cart) {
            foreach ($verified_items as $vi) {
                $stmt_cart->bind_param("ii", $id_pelanggan, $vi['id_produk']);
                $stmt_cart->execute();
            }
            $stmt_cart->close();
        }
        unset($_SESSION['pending_order']);

        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Pesanan ini telah lunas dibayar.'
        ]);
        exit;
    }

    // Skenario 3: Transaksi sudah 'Dibatalkan' ketika respons Midtrans diterima
    if ($current_pay_status === 'Dibatalkan') {
        // Simpan token untuk audit jika belum tersimpan, tanpa mengubah status pembayaran
        if (empty($current_trx['snap_token'])) {
            $stmt_audit = $conn->prepare("UPDATE transaksi SET snap_token = ? WHERE id_transaksi = ?");
            if ($stmt_audit) {
                $stmt_audit->bind_param("si", $snapToken, $id_transaksi);
                $stmt_audit->execute();
                $stmt_audit->close();
            }
        }
        $conn->commit();

        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Pesanan ini telah dibatalkan.'
        ]);
        exit;
    }

    if ($current_pay_status === 'Refund') {
        if (empty($current_trx['snap_token'])) {
            $stmt_audit = $conn->prepare("UPDATE transaksi SET snap_token = ? WHERE id_transaksi = ?");
            if ($stmt_audit) {
                $stmt_audit->bind_param("si", $snapToken, $id_transaksi);
                $stmt_audit->execute();
                $stmt_audit->close();
            }
        }
        $conn->commit();

        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Pesanan ini telah di-refund.'
        ]);
        exit;
    }

    // Skenario 1 & 4: Transaksi masih aktif ('Menunggu Pembayaran')
    // Baik berstatus 'Menunggu' maupun 'Perlu Rekonsiliasi' (pemulihan aman)
    $stmt_token = $conn->prepare("
        UPDATE transaksi 
        SET snap_token = ?, snap_status = 'Berhasil' 
        WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
    ");
    if (!$stmt_token) {
        throw new RuntimeException("Gagal mempersiapkan kueri penyimpanan Snap Token.");
    }
    $stmt_token->bind_param("si", $snapToken, $id_transaksi);
    $stmt_token->execute();
    $stmt_token->close();

    // Hapus item dari keranjang hanya setelah token berhasil disimpan
    $stmt_cart = $conn->prepare("DELETE FROM keranjang WHERE id_pelanggan = ? AND id_produk = ?");
    if ($stmt_cart) {
        foreach ($verified_items as $vi) {
            $stmt_cart->bind_param("ii", $id_pelanggan, $vi['id_produk']);
            $stmt_cart->execute();
        }
        $stmt_cart->close();
    }

    $conn->commit();

    // Hapus pending_order karena transaksi Midtrans sudah resmi terbentuk dan tersimpan
    unset($_SESSION['pending_order']);

    ob_clean();
    echo json_encode([
        'success'      => true,
        'snap_token'   => $snapToken,
        'order_id'     => $midtrans_order_id,
        'redirect_url' => $redirectUrl,
    ]);
    exit;

} catch (\Throwable $db_err) {
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
        $conn->rollback();
    }

    // W-02: Midtrans sudah menerbitkan token, tetapi penyimpanan lokal gagal!
    // KETENTUAN KETAT:
    // 1. JANGAN menandai transaksi 'Gagal'!
    // 2. JANGAN memanggil release_stock_reservation()!
    // 3. JANGAN membuat idempotency key baru!
    // 4. PERTAHANKAN reservasi stok!
    // 5. Upayakan menandai transaksi sebagai 'Perlu Rekonsiliasi' jika koneksi DB masih memungkinkan dan status belum terminal.
    error_log("Kritis W-02: Snap Token berhasil diterbitkan untuk order [{$midtrans_order_id}], namun gagal disimpan ke DB: " . $db_err->getMessage());

    try {
        if ($conn->connect_errno === 0 && @$conn->ping()) {
            $stmt_recon = $conn->prepare("
                UPDATE transaksi 
                SET snap_status = 'Perlu Rekonsiliasi' 
                WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
            ");
            if ($stmt_recon) {
                $stmt_recon->bind_param("i", $id_transaksi);
                $stmt_recon->execute();
                $stmt_recon->close();
            }
        }
    } catch (\Throwable $ignored) {
        // Jika database tidak dapat diakses sama sekali, jangan menganggap perubahan status berhasil
    }

    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Token pembayaran telah berhasil diterbitkan oleh gateway namun terjadi kendala pencatatan sistem lokal. Pesanan Anda ditandai untuk rekonsiliasi. Mohon periksa riwayat pesanan Anda.'
    ]);
    exit;
}
