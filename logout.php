<?php
require 'config.php'; // Load config untuk koneksi DB

// --- [BARU] HAPUS DEVICE DARI DATABASE ---
// Ambil ID sesi saat ini sebelum dihancurkan
$current_session_id = session_id();

if (!empty($current_session_id)) {
    // Hapus data device yang sesuai dengan session_id ini
    $stmt = $conn->prepare("DELETE FROM user_devices WHERE session_id = ?");
    $stmt->bind_param("s", $current_session_id);
    $stmt->execute();
}
// -----------------------------------------

// Hapus token "Remember Me" di Database (Tabel Users)
if (isset($_SESSION['valselt_user_id'])) {
    $uid = $_SESSION['valselt_user_id'];
    $conn->query("UPDATE users SET remember_token=NULL WHERE id='$uid'");
}

// Hapus Session PHP
session_destroy(); 

// Hapus Cookie "Remember Me" di Browser
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, "/");
    unset($_COOKIE['remember_token']);
}

// Cek apakah ada permintaan redirect khusus setelah logout
if (isset($_GET['continue'])) {
    header("Location: " . $_GET['continue']);
} else {
    header("Location: login.php");
}
exit();
?>