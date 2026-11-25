
<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode tidak diizinkan']);
    exit;
}

if (!isset($_SESSION['id_pelanggan']) ||
    ($_SESSION['role'] ?? '') !== 'pelanggan') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Silakan login terlebih dahulu']);
    exit;
}

require_once __DIR__ . '/../includes/ongkir_service.php';

try {
    $destination = filter_input(
        INPUT_POST,
        'destination',
        FILTER_VALIDATE_INT
    );

    if (!$destination || $destination <= 0) {
        throw new Exception('ID tujuan tidak valid');
    }

    $config = require __DIR__ . '/../includes/ongkir_config.local.php';

    $origin = (int) ($config['origin_id'] ?? 0);
    $weight = (int) ($config['berat_uji_gram'] ?? 0);

    if ($origin <= 0 || $weight <= 0) {
        throw new Exception('Konfigurasi pengiriman belum lengkap');
    }

    // Memanggil API RajaOngkir
    $hasil = hitungOngkir(
        $origin,
        $destination,
        $weight,
        'jne'
    );

    $layanan = [];

    foreach ($hasil as $item) {
        if (!isset($item['cost']) ||
            !is_numeric($item['cost']) ||
            (int)$item['cost'] < 0) {
            continue;
        }

        $layanan[] = [
            'kurir' => $item['name'] ?? 'JNE',
            'kode' => $item['code'] ?? 'jne',
            'service' => $item['service'] ?? '',
            'ongkir' => (int)$item['cost'],
            'estimasi' => $item['etd'] ?? '-'
        ];
    }

    if (empty($layanan)) {
        throw new Exception('Tidak ada tarif pengiriman tersedia');
    }

    echo json_encode([
        'success' => true,
        'data' => $layanan
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('PPL-11 ongkir: ' . $e->getMessage());

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Tarif tidak tersedia. Periksa tujuan atau coba lagi.'
    ]);
}
