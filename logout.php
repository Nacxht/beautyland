<?php
session_start();

// Hapus semua data session logout
session_unset();

// Hancurkan session
session_destroy();

// Kembali ke login
header("Location:login.php");
exit;
?>