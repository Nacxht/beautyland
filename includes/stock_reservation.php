<?php

/**
 * Helper Reservasi Stok untuk Beautyland
 * 
 * Modul ini menyediakan fungsi pengelolaan reservasi stok terpisah:
 * - reserve_stock(): Mencegah overselling (AC-18) dengan locking FOR UPDATE.
 * - release_stock_reservation(): Melepaskan reservasi saat pembayaran batal/expire/gagal.
 * - convert_stock_reservation(): Mengonversi reservasi menjadi pengurangan stok aktual saat pembayaran lunas (AC-19).
 * - get_stock_reservations(): Memeriksa status reservasi suatu transaksi.
 * 
 * KETENTUAN TRANSAKSI:
 * Semua fungsi dalam helper ini TIDAK melakukan commit() maupun rollback().
 * Caller (pemanggil) wajib mengelola transaksi MySQL (begin_transaction, commit, rollback) sendiri.
 */

declare(strict_types=1);

/**
 * Membuat reservasi stok sementara untuk suatu transaksi.
 *
 * @param mysqli $conn Koneksi database aktif dalam transaksi MySQL
 * @param int $id_transaksi ID transaksi yang memiliki reservasi
 * @param array $items Array berisi item [['id_produk' => int, 'jumlah' => int], ...]
 * @param int $duration_seconds Batas waktu berlakunya reservasi dalam detik
 * @return array ['success' => bool, 'expires_at' => string, 'message' => ?string]
 */
function reserve_stock(mysqli $conn, int $id_transaksi, array $items, int $duration_seconds = 86400): array
{
    if ($id_transaksi <= 0 || empty($items)) {
        return ['success' => false, 'message' => 'Parameter reservasi tidak valid.'];
    }

    // Urutkan item berdasarkan id_produk ASC untuk mencegah deadlock saat row locking
    usort($items, static function (array $a, array $b): int {
        return ((int)$a['id_produk'] <=> (int)$b['id_produk']);
    });

    $expires_at = date('Y-m-d H:i:s', time() + $duration_seconds);

    $stmt_check = $conn->prepare("
        SELECT id_produk, nama_produk, stok, stok_direservasi 
        FROM produk 
        WHERE id_produk = ? 
        FOR UPDATE
    ");
    if (!$stmt_check) {
        return ['success' => false, 'message' => 'Gagal mempersiapkan kueri reservasi produk.'];
    }

    $stmt_update_prod = $conn->prepare("
        UPDATE produk 
        SET stok_direservasi = stok_direservasi + ? 
        WHERE id_produk = ?
    ");
    if (!$stmt_update_prod) {
        $stmt_check->close();
        return ['success' => false, 'message' => 'Gagal mempersiapkan kueri pembaruan agregat reservasi.'];
    }

    $stmt_insert_res = $conn->prepare("
        INSERT INTO reservasi_stok (
            id_transaksi,
            id_produk,
            jumlah,
            status_reservasi,
            expires_at
        ) VALUES (?, ?, ?, 'Aktif', ?)
    ");
    if (!$stmt_insert_res) {
        $stmt_check->close();
        $stmt_update_prod->close();
        return ['success' => false, 'message' => 'Gagal mempersiapkan kueri pencatatan reservasi.'];
    }

    foreach ($items as $item) {
        $id_produk = (int)($item['id_produk'] ?? 0);
        $qty       = (int)($item['jumlah'] ?? 0);

        if ($id_produk <= 0 || $qty <= 0) {
            $stmt_check->close();
            $stmt_update_prod->close();
            $stmt_insert_res->close();
            return ['success' => false, 'message' => 'Data produk atau kuantitas reservasi tidak valid.'];
        }

        $stmt_check->bind_param('i', $id_produk);
        $stmt_check->execute();
        $prod = $stmt_check->get_result()->fetch_assoc();

        if (!$prod) {
            $stmt_check->close();
            $stmt_update_prod->close();
            $stmt_insert_res->close();
            return ['success' => false, 'message' => 'Produk tidak ditemukan saat melakukan reservasi.'];
        }

        $stok_aktual      = (int)$prod['stok'];
        $stok_direservasi = (int)$prod['stok_direservasi'];
        $stok_tersedia    = $stok_aktual - $stok_direservasi;

        if ($stok_tersedia < $qty) {
            $stmt_check->close();
            $stmt_update_prod->close();
            $stmt_insert_res->close();
            $sisa = max(0, $stok_tersedia);
            return [
                'success' => false,
                'message' => "Stok untuk produk {$prod['nama_produk']} tidak mencukupi (sisa tersedia: {$sisa})."
            ];
        }

        // Tambah reservasi pada agregat produk
        $stmt_update_prod->bind_param('ii', $qty, $id_produk);
        if (!$stmt_update_prod->execute() || $stmt_update_prod->affected_rows === 0) {
            $stmt_check->close();
            $stmt_update_prod->close();
            $stmt_insert_res->close();
            return ['success' => false, 'message' => "Gagal memperbarui agregat reservasi untuk produk ID {$id_produk}."];
        }

        // Catat baris reservasi
        $stmt_insert_res->bind_param('iiis', $id_transaksi, $id_produk, $qty, $expires_at);
        if (!$stmt_insert_res->execute() || $stmt_insert_res->affected_rows === 0) {
            $stmt_check->close();
            $stmt_update_prod->close();
            $stmt_insert_res->close();
            return ['success' => false, 'message' => "Gagal mencatat baris reservasi untuk produk ID {$id_produk}."];
        }
    }

    $stmt_check->close();
    $stmt_update_prod->close();
    $stmt_insert_res->close();

    return [
        'success'    => true,
        'expires_at' => $expires_at,
        'message'    => 'Reservasi stok berhasil dibuat.'
    ];
}

/**
 * Melepaskan reservasi stok untuk transaksi tertentu (Idempotent).
 * Dipanggil saat pesanan kedaluwarsa, dibatalkan, atau gagal.
 *
 * @param mysqli $conn Koneksi database aktif dalam transaksi MySQL
 * @param int $id_transaksi ID transaksi yang reservasinya akan dilepas
 * @return array ['success' => bool, 'released' => int, 'message' => string]
 */
function release_stock_reservation(mysqli $conn, int $id_transaksi, bool $is_midtrans = true): array
{
    if ($id_transaksi <= 0) {
        return [
            'success'          => false,
            'released'         => 0,
            'already_released' => false,
            'message'          => 'ID transaksi tidak valid.'
        ];
    }

    $stmt_get = $conn->prepare("
        SELECT id_reservasi, id_produk, jumlah, status_reservasi 
        FROM reservasi_stok 
        WHERE id_transaksi = ? 
        ORDER BY id_produk ASC, id_reservasi ASC 
        FOR UPDATE
    ");
    if (!$stmt_get) {
        return [
            'success'          => false,
            'released'         => 0,
            'already_released' => false,
            'message'          => 'Gagal mengambil reservasi aktif.'
        ];
    }

    $stmt_get->bind_param('i', $id_transaksi);
    $stmt_get->execute();
    $res = $stmt_get->get_result();

    $all_reservations = [];
    while ($row = $res->fetch_assoc()) {
        $all_reservations[] = $row;
    }
    $stmt_get->close();

    $total_count = count($all_reservations);

    if ($total_count === 0) {
        if (!$is_midtrans) {
            return [
                'success'          => true,
                'released'         => 0,
                'already_released' => false,
                'message'          => 'Tidak ada reservasi untuk transaksi pembayaran manual.'
            ];
        }
        return [
            'success'          => false,
            'released'         => 0,
            'already_released' => false,
            'needs_reconciliation' => true,
            'message'          => "Tidak ada reservasi ditemukan untuk transaksi Midtrans [{$id_transaksi}]. Memerlukan rekonsiliasi."
        ];
    }

    $active_count    = 0;
    $released_count  = 0;
    $converted_count = 0;

    foreach ($all_reservations as $r) {
        $st = (string)($r['status_reservasi'] ?? '');
        if ($st === 'Aktif') {
            $active_count++;
        } elseif ($st === 'Dilepas') {
            $released_count++;
        } elseif ($st === 'Dikonversi') {
            $converted_count++;
        }
    }

    if ($released_count === $total_count) {
        return [
            'success'          => true,
            'released'         => 0,
            'already_released' => true,
            'message'          => 'Semua reservasi stok telah dilepas sebelumnya.'
        ];
    }

    if ($converted_count === $total_count) {
        return [
            'success'              => false,
            'released'             => 0,
            'already_released'     => false,
            'already_converted'    => true,
            'message'              => "Semua reservasi untuk transaksi [{$id_transaksi}] telah dikonversi. Release tidak dilakukan."
        ];
    }

    if ($active_count !== $total_count) {
        return [
            'success'              => false,
            'released'             => 0,
            'already_released'     => false,
            'needs_reconciliation' => true,
            'message'              => "Status reservasi tidak konsisten untuk transaksi [{$id_transaksi}] (Aktif: {$active_count}, Dilepas: {$released_count}, Dikonversi: {$converted_count}) dari total {$total_count}. Memerlukan rekonsiliasi."
        ];
    }

    $active_reservations = $all_reservations;

    $stmt_check_prod = $conn->prepare("
        SELECT id_produk, stok_direservasi 
        FROM produk 
        WHERE id_produk = ? 
        FOR UPDATE
    ");
    if (!$stmt_check_prod) {
        return [
            'success'          => false,
            'released'         => 0,
            'already_released' => false,
            'message'          => 'Gagal mempersiapkan kueri validasi stok reservasi.'
        ];
    }

    $stmt_update_prod = $conn->prepare("
        UPDATE produk 
        SET stok_direservasi = stok_direservasi - ? 
        WHERE id_produk = ? AND stok_direservasi >= ?
    ");
    if (!$stmt_update_prod) {
        $stmt_check_prod->close();
        return [
            'success'          => false,
            'released'         => 0,
            'already_released' => false,
            'message'          => 'Gagal mempersiapkan kueri pembaruan agregat reservasi.'
        ];
    }

    $stmt_update_res = $conn->prepare("
        UPDATE reservasi_stok 
        SET status_reservasi = 'Dilepas' 
        WHERE id_reservasi = ?
    ");
    if (!$stmt_update_res) {
        $stmt_check_prod->close();
        $stmt_update_prod->close();
        return [
            'success'          => false,
            'released'         => 0,
            'already_released' => false,
            'message'          => 'Gagal mempersiapkan kueri pembaruan status reservasi.'
        ];
    }

    $count = 0;
    foreach ($active_reservations as $r) {
        $id_res    = (int)$r['id_reservasi'];
        $id_produk = (int)$r['id_produk'];
        $qty       = (int)$r['jumlah'];

        $stmt_check_prod->bind_param('i', $id_produk);
        $stmt_check_prod->execute();
        $prod_data = $stmt_check_prod->get_result()->fetch_assoc();

        if (!$prod_data) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            return [
                'success'              => false,
                'released'             => $count,
                'already_released'     => false,
                'needs_reconciliation' => true,
                'message'              => "Produk ID {$id_produk} tidak ditemukan saat release. Memerlukan rekonsiliasi."
            ];
        }

        $current_reserved = (int)$prod_data['stok_direservasi'];
        if ($current_reserved < $qty) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            error_log("RECONCILIATION_NEEDED: stok_direservasi ({$current_reserved}) < qty ({$qty}) untuk produk ID {$id_produk} pada transaksi [{$id_transaksi}].");
            return [
                'success'              => false,
                'released'             => $count,
                'already_released'     => false,
                'needs_reconciliation' => true,
                'message'              => "Stok reservasi tidak mencukupi untuk produk ID {$id_produk} (tersedia: {$current_reserved}, dibutuhkan: {$qty}). Memerlukan rekonsiliasi."
            ];
        }

        $stmt_update_prod->bind_param('iii', $qty, $id_produk, $qty);
        if (!$stmt_update_prod->execute() || $stmt_update_prod->affected_rows === 0) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            return [
                'success'              => false,
                'released'             => $count,
                'already_released'     => false,
                'needs_reconciliation' => true,
                'message'              => "Gagal melepaskan agregat reservasi produk ID {$id_produk} (conditional UPDATE gagal). Memerlukan rekonsiliasi."
            ];
        }

        $stmt_update_res->bind_param('i', $id_res);
        if (!$stmt_update_res->execute() || $stmt_update_res->affected_rows === 0) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            return [
                'success'              => false,
                'released'             => $count,
                'already_released'     => false,
                'needs_reconciliation' => true,
                'message'              => "Gagal memperbarui status reservasi ID {$id_res}."
            ];
        }

        $count++;
    }

    $stmt_check_prod->close();
    $stmt_update_prod->close();
    $stmt_update_res->close();

    return [
        'success'          => true,
        'released'         => $count,
        'already_released' => false,
        'message'          => "Sebanyak {$count} reservasi stok berhasil dilepas."
    ];
}

/**
 * Mengonversi reservasi stok menjadi pengurangan stok aktual (Idempotent).
 * Dipanggil saat pembayaran Midtrans berstatus 'settlement' atau 'capture' valid (AC-19).
 *
 * @param mysqli $conn Koneksi database aktif dalam transaksi MySQL
 * @param int $id_transaksi ID transaksi yang pembayarannya lunas
 * @return array ['success' => bool, 'converted' => int, 'message' => string]
 */
function convert_stock_reservation(mysqli $conn, int $id_transaksi): array
{
    if ($id_transaksi <= 0) {
        return [
            'success'           => false,
            'converted'         => 0,
            'already_converted' => false,
            'message'           => 'ID transaksi tidak valid.'
        ];
    }

    $stmt_get = $conn->prepare("
        SELECT id_reservasi, id_produk, jumlah, status_reservasi 
        FROM reservasi_stok 
        WHERE id_transaksi = ? 
        ORDER BY id_produk ASC, id_reservasi ASC 
        FOR UPDATE
    ");
    if (!$stmt_get) {
        return [
            'success'           => false,
            'converted'         => 0,
            'already_converted' => false,
            'message'           => 'Gagal mengambil data reservasi.'
        ];
    }

    $stmt_get->bind_param('i', $id_transaksi);
    $stmt_get->execute();
    $res = $stmt_get->get_result();

    $all_reservations = [];
    while ($row = $res->fetch_assoc()) {
        $all_reservations[] = $row;
    }
    $stmt_get->close();

    if (empty($all_reservations)) {
        return [
            'success'           => false,
            'converted'         => 0,
            'already_converted' => false,
            'message'           => 'Reservasi stok tidak ditemukan untuk transaksi ini.'
        ];
    }

    $total_count     = count($all_reservations);
    $active_count    = 0;
    $converted_count = 0;
    $released_count  = 0;

    foreach ($all_reservations as $r) {
        $st = (string)($r['status_reservasi'] ?? '');
        if ($st === 'Aktif') {
            $active_count++;
        } elseif ($st === 'Dikonversi') {
            $converted_count++;
        } elseif ($st === 'Dilepas') {
            $released_count++;
        }
    }

    if ($converted_count === $total_count) {
        return [
            'success'           => true,
            'converted'         => 0,
            'already_converted' => true,
            'message'           => 'Semua reservasi stok untuk transaksi ini telah dikonversi sebelumnya.'
        ];
    }

    if ($active_count !== $total_count) {
        return [
            'success'           => false,
            'converted'         => 0,
            'already_converted' => false,
            'message'           => "Status reservasi tidak konsisten (Aktif: {$active_count}, Dikonversi: {$converted_count}, Dilepas: {$released_count}) dari total {$total_count} item."
        ];
    }

    $stmt_check_prod = $conn->prepare("
        SELECT id_produk, stok, stok_direservasi 
        FROM produk 
        WHERE id_produk = ? 
        FOR UPDATE
    ");
    if (!$stmt_check_prod) {
        return [
            'success'           => false,
            'converted'         => 0,
            'already_converted' => false,
            'message'           => 'Gagal mempersiapkan kueri validasi stok produk.'
        ];
    }

    $stmt_update_prod = $conn->prepare("
        UPDATE produk 
        SET stok = stok - ?,
            stok_direservasi = stok_direservasi - ? 
        WHERE id_produk = ? AND stok >= ? AND stok_direservasi >= ?
    ");
    if (!$stmt_update_prod) {
        $stmt_check_prod->close();
        return [
            'success'           => false,
            'converted'         => 0,
            'already_converted' => false,
            'message'           => 'Gagal mempersiapkan kueri pembaruan stok produk.'
        ];
    }

    $stmt_update_res = $conn->prepare("
        UPDATE reservasi_stok 
        SET status_reservasi = 'Dikonversi' 
        WHERE id_reservasi = ?
    ");
    if (!$stmt_update_res) {
        $stmt_check_prod->close();
        $stmt_update_prod->close();
        return [
            'success'           => false,
            'converted'         => 0,
            'already_converted' => false,
            'message'           => 'Gagal mempersiapkan kueri pembaruan status reservasi.'
        ];
    }

    $count = 0;
    foreach ($all_reservations as $r) {
        $id_res    = (int)$r['id_reservasi'];
        $id_produk = (int)$r['id_produk'];
        $qty       = (int)$r['jumlah'];

        $stmt_check_prod->bind_param('i', $id_produk);
        $stmt_check_prod->execute();
        $prod_data = $stmt_check_prod->get_result()->fetch_assoc();

        if (!$prod_data) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            return [
                'success'              => false,
                'converted'            => $count,
                'already_converted'    => false,
                'needs_reconciliation' => true,
                'message'              => "Produk ID {$id_produk} tidak ditemukan saat konversi. Memerlukan rekonsiliasi."
            ];
        }

        $current_stok     = (int)$prod_data['stok'];
        $current_reserved = (int)$prod_data['stok_direservasi'];

        if ($current_stok < $qty) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            error_log("RECONCILIATION_NEEDED: stok ({$current_stok}) < qty ({$qty}) untuk produk ID {$id_produk} pada konversi transaksi [{$id_transaksi}].");
            return [
                'success'              => false,
                'converted'            => $count,
                'already_converted'    => false,
                'needs_reconciliation' => true,
                'message'              => "Stok fisik tidak mencukupi untuk konversi produk ID {$id_produk} (stok: {$current_stok}, dibutuhkan: {$qty}). Memerlukan rekonsiliasi."
            ];
        }

        if ($current_reserved < $qty) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            error_log("RECONCILIATION_NEEDED: stok_direservasi ({$current_reserved}) < qty ({$qty}) untuk produk ID {$id_produk} pada konversi transaksi [{$id_transaksi}].");
            return [
                'success'              => false,
                'converted'            => $count,
                'already_converted'    => false,
                'needs_reconciliation' => true,
                'message'              => "Stok reservasi tidak mencukupi untuk konversi produk ID {$id_produk} (reservasi: {$current_reserved}, dibutuhkan: {$qty}). Memerlukan rekonsiliasi."
            ];
        }

        $stmt_update_prod->bind_param('iiiii', $qty, $qty, $id_produk, $qty, $qty);
        if (!$stmt_update_prod->execute() || $stmt_update_prod->affected_rows === 0) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            return [
                'success'              => false,
                'converted'            => $count,
                'already_converted'    => false,
                'needs_reconciliation' => true,
                'message'              => "Gagal memperbarui stok fisik untuk produk ID {$id_produk} (conditional UPDATE gagal). Memerlukan rekonsiliasi."
            ];
        }

        $stmt_update_res->bind_param('i', $id_res);
        if (!$stmt_update_res->execute() || $stmt_update_res->affected_rows === 0) {
            $stmt_check_prod->close();
            $stmt_update_prod->close();
            $stmt_update_res->close();
            return [
                'success'              => false,
                'converted'            => $count,
                'already_converted'    => false,
                'needs_reconciliation' => true,
                'message'              => "Gagal memperbarui status reservasi ID {$id_res}."
            ];
        }

        $count++;
    }

    $stmt_check_prod->close();
    $stmt_update_prod->close();
    $stmt_update_res->close();

    return [
        'success'           => true,
        'converted'         => $count,
        'already_converted' => false,
        'message'           => "Sebanyak {$count} reservasi stok berhasil dikonversi ke penjualan."
    ];
}

/**
 * Mengambil daftar reservasi stok untuk suatu transaksi.
 *
 * @param mysqli $conn Koneksi database
 * @param int $id_transaksi ID transaksi
 * @return array Daftar baris reservasi
 */
function get_stock_reservations(mysqli $conn, int $id_transaksi): array
{
    if ($id_transaksi <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT id_reservasi, id_transaksi, id_produk, jumlah, status_reservasi, expires_at, created_at, updated_at
        FROM reservasi_stok 
        WHERE id_transaksi = ?
        ORDER BY id_produk ASC, id_reservasi ASC
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $id_transaksi);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}
