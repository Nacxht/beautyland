<?php

require_once __DIR__ . '/config.php';

$conn = new mysqli(HOSTNAME, USERNAME, PASSWORD, DBNAME);

define("DB_CONN", $conn);

if (DB_CONN->connect_error) {
  die("Koneksi gagal: " . $conn->connect_error);
}
