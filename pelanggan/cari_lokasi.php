<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['id_pelanggan']) ||
    ($_SESSION['role'] ?? '') !== 'pelanggan') {

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Silakan login terlebih dahulu.'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/ongkir_service.php';

$keyword = trim((string)($_GET['q'] ?? ''));

// Batasi panjang pencarian
if (strlen($keyword) < 3 || strlen($keyword) > 60) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Masukkan minimal 3 karakter.'
    ]);
    exit;
}

try {
    // Fungsi yang sebelumnya berhasil diuji
    $hasil = cariLokasiOngkir($keyword);

    $lokasi = [];

    foreach ($hasil as $item) {
        $id = filter_var(
            $item['id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $label = trim((string)($item['label'] ?? ''));

        if ($id && $id > 0 && $label !== '') {
            $lokasi[] = [
                'id' => $id,
                'label' => $label
            ];
        }

        if (count($lokasi) >= 10) {
            break;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $lokasi
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('PPL-11 lokasi: ' . $e->getMessage());

    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal mencari lokasi pengiriman.'
    ]);
}
