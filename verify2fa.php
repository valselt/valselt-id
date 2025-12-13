<?php
require 'config.php';
// use PragmaRX\Google2FA\Google2FA; // Pastikan library ini diload jika belum di config.php

// Jika user belum login (di tahap pre-2fa), tendang ke login
if (!isset($_SESSION['pre_2fa_user_id'])) {
    header("Location: login.php");
    exit();
}

$error_msg = "";

if (isset($_POST['verify_2fa'])) {
    $code = $_POST['otp_code'];
    $uid = $_SESSION['pre_2fa_user_id'];
    
    // Ambil secret dari DB
    $q = $conn->query("SELECT two_factor_secret, is_2fa_enabled FROM users WHERE id='$uid'");
    $u = $q->fetch_assoc();
    
    $google2fa = new \PragmaRX\Google2FA\Google2FA();
    
    if ($google2fa->verifyKey($u['two_factor_secret'], $code)) {
        // --- KODE BENAR ---
        
        // 1. Pindahkan Session Sementara ke Session Utama (Login Resmi)
        $_SESSION['valselt_user_id'] = $uid;
        logUserDevice($conn, $uid);
        // Ambil data user lengkap untuk session lain (username, dll) jika perlu
        $qu = $conn->query("SELECT username FROM users WHERE id='$uid'");
        $userData = $qu->fetch_assoc();
        $_SESSION['valselt_username'] = $userData['username'];
        
        // 2. Hapus Session Sementara
        unset($_SESSION['pre_2fa_user_id']);
        unset($_SESSION['pre_2fa_remember']); // Hapus flag remember 2fa
        
        // 3. Cek "Trust This Device" (1 Bulan / 6 Bulan)
        if (isset($_POST['trust_device'])) {
            $days = 30; // Default 1 bulan (Login Biasa)
            if (isset($_SESSION['login_method']) && ($_SESSION['login_method'] == 'google' || $_SESSION['login_method'] == 'github')) {
                $days = 180; // 6 bulan untuk SSO
            }
            
            // Buat Token Cookie Khusus 2FA
            $token2fa = bin2hex(random_bytes(32));
            $expiry = time() + (86400 * $days);
            
            // Simpan token di tabel user_devices (atau tabel khusus 2fa_tokens jika mau dipisah)
            // Disini kita simpan di tabel user_devices kolom 'two_factor_token' (PERLU NAMBAH KOLOM)
            // ATAU Cara Simpel: Simpan di cookie browser saja dengan hash user_id (Kurang aman tapi umum)
            
            // Cara Lebih Aman: Update tabel user_devices yang baru saja dibuat saat login
            // Kita butuh ID device terakhir. Karena logic logUserDevice dijalankan saat pre-login, 
            // kita asumsikan device terakhir adalah yang aktif.
            
            // Set cookie di browser
            setcookie('valselt_2fa_trusted', $token2fa, $expiry, "/", "", false, true);
            
            // Simpan hash token di DB (Tabel users atau user_devices)
            // Untuk kemudahan, kita update kolom di user_devices yang sesi-nya aktif
            $sess_id = session_id();
            $conn->query("UPDATE user_devices SET two_factor_token='$token2fa' WHERE session_id='$sess_id'");
        }
        
        // 4. Handle Remember Me (Login Biasa)
        // Jika user mencentang "Remember Me" di halaman login awal
        if (isset($_SESSION['login_remember_me']) && $_SESSION['login_remember_me'] == true) {
             handleRememberMe($conn, $uid);
             unset($_SESSION['login_remember_me']);
        }
        
        // 5. Redirect ke Index (atau target awal)
        $target = isset($_SESSION['sso_redirect_to']) ? $_SESSION['sso_redirect_to'] : 'index.php';
        
        // Jika SSO Redirect, proses token dulu
        if (isset($_SESSION['sso_redirect_to'])) {
             processSSORedirect($conn, $uid, $target); 
        } else {
             header("Location: index.php");
        }
        exit();
        
    } else {
        $error_msg = "Kode salah! Silakan coba lagi.";
    }
}

// Tentukan Durasi Trust Device untuk Teks UI
$trustDuration = "1 bulan";
if (isset($_SESSION['login_method']) && ($_SESSION['login_method'] == 'google' || $_SESSION['login_method'] == 'github')) {
    $trustDuration = "6 bulan";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi 2FA - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f9fafb; margin: 0; font-family: 'Inter Tight', sans-serif; }
        h2 {font-family: "Instrument Serif", serif;}
        .auth-card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 400px; text-align: center; border: 1px solid #e5e7eb; }
        .auth-icon { width: 60px; height: 60px; background: #f7f2be; color: #7d480d; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 20px; }
        .otp-input { letter-spacing: 8px; font-size: 1.5rem; text-align: center; font-weight: 700; }
        .btn-verify { width: 100%; padding: 12px; background: #000; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 20px; transition: 0.2s; }
        .btn-verify:hover { background: #333; }
        .trust-option { margin-top: 15px; font-size: 0.9rem; color: #4b5563; display: flex; align-items: center; justify-content: center; gap: 8px; }
    </style>
</head>
<body>

    <div class="auth-card">
        <div class="auth-icon"><i class='bx bx-shield-quarter'></i></div>
        <h2 style="margin-bottom: 10px; font-weight: 700;">Verifikasi 2 Langkah</h2>
        <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 30px;">
            Masukkan 6 digit kode dari aplikasi Authenticator Anda.
        </p>

        <?php if($error_msg): ?>
            <div style="background: #fef2f2; color: #b91c1c; padding: 10px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 20px; border: 1px solid #fecaca;">
                <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="otp_code" class="form-control otp-input" placeholder="000000" maxlength="6" required autofocus>
            
            <label class="trust-option">
                <input type="checkbox" name="trust_device" value="1" checked>
                Jangan tanya lagi di perangkat ini selama <?php echo $trustDuration; ?>.
            </label>

            <button type="submit" name="verify_2fa" class="btn-verify">Verifikasi</button>
        </form>
        
        <div style="margin-top: 20px;">
            <a href="login.php" style="color: #6b7280; font-size: 0.85rem; text-decoration: none;">Kembali ke Login</a>
        </div>
    </div>

</body>
</html>