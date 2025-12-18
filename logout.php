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

$target = 'login'; // Default fallback

if (isset($_GET['continue'])) {
    $cont = $_GET['continue'];
    
    // Cek 1: Apakah ini Base64? (Tidak ada http di awal)
    if (strpos($cont, 'http') === false) {
        $decoded = base64_decode($cont, true);
        // Cek 2: Hasil decode valid & aman (diawali 'login')
        if ($decoded !== false && strpos($decoded, 'login') === 0) {
            $target = $decoded;
        }
    } 
    // Fallback: Jika string biasa (tapi ini yg bikin error, jadi kita utamakan base64)
    elseif (strpos($cont, 'login') === 0) {
        $target = $cont;
    }
}

header("Location: " . $target);
exit();