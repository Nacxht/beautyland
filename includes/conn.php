<?php
$conn = new mysqli("localhost", "root", "", "beautyland");

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}
