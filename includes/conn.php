<?php

require_once __DIR__ . '/config.php';

$conn = new mysqli(HOSTNAME, USERNAME, PASSWORD, DBNAME);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}
