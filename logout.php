<?php
require 'config.php'; // Load config untuk koneksi DB

// --- [BARU] HAPUS DEVICE DARI DATABASE ---
// Ambil ID sesi saat ini sebelum dihancurkan
$current_session_id = session_id();

if (!empty($current_session_id)) {
    // Jangan DELETE, tapi UPDATE is_active = 0
    $stmt = $conn->prepare("UPDATE user_devices SET is_active = 0 WHERE session_id = ?");
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

if (isset($_GET['continue'])) {
    $target = base64_decode($_GET['continue'], true);

    // Validasi hasil decode
    if ($target !== false && strpos($target, 'login') === 0) {
        header("Location: " . $target);
        exit();
    }
}

// fallback aman
header("Location: login");
exit();