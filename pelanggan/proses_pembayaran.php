<?php
include '../includes/conn.php';

// Handle cancel action: set status to 'Dibatalkan' and redirect to produk_user.php
if (isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $id_cancel = $_POST['id_transaksi'];
    mysqli_query($conn, "UPDATE transaksi SET status_pembayaran='Dibatalkan' WHERE id_transaksi='$id_cancel'");
    header('Location: produk_user.php');
    exit;
}

$id = $_POST['id_transaksi'];

// Ambil data file gambar yang diupload
$nama_file = $_FILES['bukti']['name'];
$tmp       = $_FILES['bukti']['tmp_name'];
$error     = $_FILES['bukti']['error'];

if ($error === 0) {
    // Ambil ekstensi asli file (misal: jpg, png, jpeg)
    $ekstensi = pathinfo($nama_file, PATHINFO_EXTENSION);
    
    // Generate nama file baru yang acak & unik agar file di VS Code tidak saling menimpa
    $nama_file_baru = uniqid() . '.' . $ekstensi;

    // Pindahkan file fisik ke folder assets/bukti/ yang baru kamu buat
    $upload = move_uploaded_file(
        $tmp,
        "../assets/bukti/" . $nama_file_baru
    );

    if ($upload) {
        // Simpan nama file baru yang unik ke database beserta update status
        mysqli_query($conn, "
            UPDATE transaksi
            SET
            bukti_pembayaran='$nama_file_baru',
            status_pembayaran='Menunggu Verifikasi'
            WHERE id_transaksi='$id'
        ");

        echo "
        <script>
        alert('Bukti pembayaran berhasil dikirim!');
        window.location='pesanan_user.php';
        </script>
        ";
    } else {
        echo "<script>alert('Gagal memindahkan file ke folder assets/bukti/. Pastikan folder sudah ada!'); window.history.back();</script>";
    }
} else {
    echo "<script>alert('Terjadi kesalahan saat mengunggah gambar.'); window.history.back();</script>";
}
?>