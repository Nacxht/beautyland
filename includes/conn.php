<?php
$conn = new mysqli("127.0.0.1", "root", "ZkyZors-177013__", "beautyland");

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}
