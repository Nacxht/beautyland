<?php
require_once __DIR__ . "/config.php";

// Memuat Composer autoloader
$autoloadPath = __DIR__ . "/../vendor/autoload.php";

if (!file_exists($autoloadPath)) {
  throw new RuntimeException("Composer autoload tidak ditemukan.");
}

require_once $autoloadPath;

// Mengambil kredensial dari .env
$serverKey = $_ENV["MIDTRANS_SERVER_KEY"] ?? "";
$clientKey = $_ENV["MIDTRANS_CLIENT_KEY"] ?? "";

$isProduction = filter_var(
  $_ENV["MIDTRANS_IS_PRODUCTION"] ?? "false",
  FILTER_VALIDATE_BOOLEAN,
);

// Validasi konfigurasi
if (empty($serverKey) || empty($clientKey)) {
  throw new RuntimeException("Kredensial Midtrans belum dikonfigurasi di .env");
}

// Konfigurasi Midtrans
\Midtrans\Config::$serverKey = $serverKey;
\Midtrans\Config::$clientKey = $clientKey;
\Midtrans\Config::$isProduction = $isProduction;

// Pengaturan keamanan
\Midtrans\Config::$isSanitized = true;
\Midtrans\Config::$is3ds = true;
