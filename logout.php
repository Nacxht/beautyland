<?php
session_start();

// Hapus semua session
session_unset();

// Hancurkan session
session_destroy();

// Kembali ke login
header("Location:login.php");
exit;
?>