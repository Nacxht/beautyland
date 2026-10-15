<?php
session_start();
include '../includes/conn.php';
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') { header("Location: ../login.php"); exit; }

$id = $_GET['id'];
if(isset($_POST['update_jadwal'])){
    $tanggal = $_POST['tanggal'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    $konsultan = $_POST['konsultan'];
    
    mysqli_query($conn, "UPDATE jadwal SET tanggal='$tanggal', jam_mulai='$jam_mulai', jam_selesai='$jam_selesai', konsultan='$konsultan' WHERE id_jadwal='$id'");
    header("Location: konsultasi.php");
    exit;
}
$data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM jadwal WHERE id_jadwal='$id'"));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Edit Jadwal</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'DM Sans', sans-serif;
            background: #fbf6f8;
            padding: 50px;
        }

        .form-edit {
            background: white;
            padding: 30px;
            border-radius: 20px;
            max-width: 400px;
            margin: auto;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        input {
            width: 100%;
            padding: 12px;
            margin: 10px 0 20px 0;
            border-radius: 8px;
            border: 1px solid #ddd;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 12px;
            background: #ff4f81;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="form-edit">
        <h2>Edit Jadwal</h2>
        <form method="POST">
            <label>Tanggal</label>
            <input type="date" name="tanggal" value="<?= $data['tanggal']; ?>" required>
            
            <label>Jam Mulai</label>
            <input type="time" name="jam_mulai" value="<?= $data['jam_mulai']; ?>" required>
            
            <label>Jam Selesai</label>
            <input type="time" name="jam_selesai" value="<?= $data['jam_selesai']; ?>" required>
            
            <label>Konsultan</label>
            <input type="text" name="konsultan" value="<?= $data['konsultan']; ?>" required>
            
            <button type="submit" name="update_jadwal">Simpan Perubahan</button>
        </form>
    </div>
</body>
</html>