<?php

function hitungOngkir($origin, $destination, $weight, $courier = 'jne')
{
    if (!function_exists('curl_init')) {
        throw new Exception('Ekstensi cURL belum aktif.');
    }

    if (
        filter_var($origin, FILTER_VALIDATE_INT) === false ||
        filter_var($destination, FILTER_VALIDATE_INT) === false ||
        filter_var($weight, FILTER_VALIDATE_INT) === false ||
        (int)$origin <= 0 ||
        (int)$destination <= 0 ||
        (int)$weight <= 0
    ) {
        throw new Exception('Data pengiriman tidak valid.');
    }

    if (!preg_match('/^[a-z:]+$/', $courier)) {
        throw new Exception('Kode kurir tidak valid.');
    }

    $configFile = __DIR__ . '/ongkir_config.local.php';

    if (!is_file($configFile)) {
        throw new Exception('Konfigurasi API belum tersedia.');
    }

    $config = require $configFile;
    $apiKey = $config['api_key'] ?? '';

    if ($apiKey === '' || $apiKey === 'ISI_API_KEY_RAJAONGKIR') {
        throw new Exception('API Key belum dikonfigurasi.');
    }

    $url = 'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost';

    $data = [
        'origin' => (int)$origin,
        'destination' => (int)$destination,
        'weight' => (int)$weight,
        'courier' => $courier
    ];

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            'key: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);

    curl_close($curl);

    if ($response === false) {
        error_log('RajaOngkir cURL: ' . $error);
        throw new Exception('Gagal menghubungi layanan ongkir.');
    }

    if ($httpCode !== 200) {
        error_log('RajaOngkir HTTP: ' . $httpCode);
        throw new Exception('Layanan ongkir tidak berhasil menghitung tarif.');
    }

    $result = json_decode($response, true);

    if (!is_array($result) || !isset($result['data'])
        || !is_array($result['data'])
        || ($result['meta']['status'] ?? '') !== 'success') {
        throw new Exception('Respons ongkir tidak valid.');
    }

    return $result['data'];
}


function cariLokasiOngkir($keyword)
{
    $config = require __DIR__ . '/ongkir_config.local.php';

    $apiKey = $config['api_key'] ?? '';

    if (empty($apiKey) || $apiKey === 'ISI_API_KEY_RAJAONGKIR') {
        throw new Exception("API Key belum tersedia.");
    }

    $url = 'https://rajaongkir.komerce.id/api/v1/destination/domestic-destination';

    $url .= '?' . http_build_query([
        'search' => $keyword,
        'limit' => 10,
        'offset' => 0
    ]);

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'key: ' . $apiKey
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($response === false || $httpCode !== 200) {
        throw new Exception("Gagal mencari lokasi pengiriman.");
    }

    $result = json_decode($response, true);

    if (!is_array($result) ||
        ($result['meta']['status'] ?? '') !== 'success' ||
        !isset($result['data']) ||
        !is_array($result['data'])) {
        throw new Exception("Respons lokasi tidak valid.");
    }

    return $result['data'];
}
