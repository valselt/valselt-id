<?php
require 'config.php'; // Load config untuk koneksi DB

// Hapus token di Database jika user sedang login
if (isset($_SESSION['valselt_user_id'])) {
    $uid = $_SESSION['valselt_user_id'];
    $conn->query("UPDATE users SET remember_token=NULL WHERE id='$uid'");
}

// Hapus Session
session_destroy(); 

// Hapus Cookie di Browser
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