<?php
session_start();
include '../includes/conn.php';

if(isset($_POST['simpan_jadwal'])){
    $tanggal = $_POST['tanggal'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    $konsultan = $_POST['konsultan'];
    
    mysqli_query($conn, "INSERT INTO jadwal (tanggal, jam_mulai, jam_selesai, konsultan, keterangan) VALUES ('$tanggal', '$jam_mulai', '$jam_selesai', '$konsultan', 'Aktif')");
    
    header("Location: konsultasi.php"); // Kembali ke halaman utama setelah simpan
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tambah Jadwal</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <style>
    body { 
        font-family: 'DM Sans', sans-serif; 
        background: #fbf6f8; 
        padding: 40px; 
        display: flex; 
        justify-content: center; 
        align-items: flex-start;
        height: 100vh; 
        margin: 0; 
    }
    .form-box { 
        background: white; 
        border-radius: 20px; 
        padding: 20px; /* Padding lebih besar */
        width: 100%; 
        max-width: 600px; /* Dibuat lebih lebar dari sebelumnya */
        box-shadow: 0 10px 30px rgba(0,0,0,0.05); 
    }
    h2 { margin-top: 0; color: #1a0a12; }
    .input-field { 
        width: 100%; 
        padding: 15px; 
        margin-bottom: 15px; 
        border: 1px solid #eee; 
        border-radius: 12px; 
        box-sizing: border-box; /* Penting agar padding tidak merusak lebar */
        font-size: 14px;
    }
    .btn-pink { 
        background: #ff4f81; 
        color: white; 
        padding: 15px; 
        border-radius: 12px; 
        border: none; 
        width: 100%; 
        font-weight: bold; 
        cursor: pointer; 
        transition: 0.3s;
    }
    .btn-pink:hover { background: #e03b6a; }
</style>
</head>
<body>
    <div class="form-box">
        <h2>Tambah Jadwal Baru</h2>
        <form method="POST">
    <input type="date" name="tanggal" class="input-field" required>
    
    <div style="display:flex; gap:15px;">
        <input type="time" name="jam_mulai" class="input-field" required>
        <input type="time" name="jam_selesai" class="input-field" required>
    </div>
    
    <input type="text" name="konsultan" placeholder="Nama Konsultan" class="input-field" required>
    
    <button type="submit" name="simpan_jadwal" class="btn-pink">Simpan Jadwal</button>
    <a href="konsultasi.php" style="display:block; text-align:center; margin-top:20px; color:#ff4f81; text-decoration:none;">Batal</a>
</form>
    </div>
</body>
</html>