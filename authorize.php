<?php
require 'config.php';

// 1. Validasi Parameter Request
$client_id = isset($_GET['client_id']) ? $_GET['client_id'] : '';
$redirect_uri = isset($_GET['redirect_uri']) ? $_GET['redirect_uri'] : '';
$state = isset($_GET['state']) ? $_GET['state'] : ''; 

if (empty($client_id)) {
    die("Error: Missing client_id.");
}

// 2. CEK MASTER APLIKASI (Tabel oauth_clients)
// Ini mencari apakah "Spencal" terdaftar di sistem Valselt
$stmt = $conn->prepare("SELECT * FROM oauth_clients WHERE client_id = ?");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();

if (!$app) {
    die("Error: Unknown Application (Invalid Client ID).");
}

// Validasi Redirect URI (Wajib sama persis dengan yang didaftarkan di Master)
if ($redirect_uri !== $app['redirect_uri']) {
    die("Error: Redirect URI mismatch. Registered: " . $app['redirect_uri'] . " vs Request: " . $redirect_uri);
}

// 3. Cek Status Login User
if (!isset($_SESSION['valselt_user_id'])) {
    $current_url = "authorize.php?" . $_SERVER['QUERY_STRING'];
    header("Location: login?redirect_to=" . base64_encode($current_url));
    exit();
}

// 4. User Sudah Login -> PROSES OTORISASI
$user_id = $_SESSION['valselt_user_id'];

// --- [PENTING] CATAT KE authorized_apps (Agar user bisa Revoke nanti) ---
// Cek apakah user ini sudah pernah connect ke aplikasi ini sebelumnya?
$check = $conn->prepare("SELECT id FROM authorized_apps WHERE user_id = ? AND client_id = ?");
$check->bind_param("is", $user_id, $client_id);
$check->execute();
$exist = $check->get_result()->fetch_assoc();

if ($exist) {
    // Update last_accessed
    $stmt = $conn->prepare("UPDATE authorized_apps SET last_accessed = NOW(), app_name=?, app_domain=? WHERE id=?");
    $stmt->bind_param("ssi", $app['app_name'], $app['app_domain'], $exist['id']);
    $stmt->execute();
} else {
    // Insert Baru (User baru pertama kali pake Spencal)
    // PERBAIKAN: Menambahkan kolom redirect_uri ke dalam query insert
    $stmt = $conn->prepare("INSERT INTO authorized_apps (user_id, client_id, app_name, app_domain, redirect_uri, last_accessed) VALUES (?, ?, ?, ?, ?, NOW())");
    
    // Bind param ditambahkan 's' satu lagi (jadi "issss") dan variabel $app['redirect_uri']
    $stmt->bind_param("issss", $user_id, $client_id, $app['app_name'], $app['app_domain'], $app['redirect_uri']);
    $stmt->execute();
}
// ------------------------------------------------------------------------

// 5. Generate Authorization Code
$auth_code = bin2hex(random_bytes(16));
$expiry = date('Y-m-d H:i:s', strtotime('+1 minute'));

// Simpan Code ke tabel oauth_codes
$stmt = $conn->prepare("INSERT INTO oauth_codes (code, client_id, user_id, redirect_uri, expires_at) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("ssiss", $auth_code, $client_id, $user_id, $redirect_uri, $expiry);
$stmt->execute();

// 6. Redirect Balik ke Spencal
$return_url = $redirect_uri . (strpos($redirect_uri, '?') === false ? '?' : '&') . 'code=' . $auth_code . '&state=' . $state;
header("Location: " . $return_url);
exit();
?>