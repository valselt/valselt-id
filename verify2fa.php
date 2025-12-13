<?php
require 'config.php';
// use PragmaRX\Google2FA\Google2FA; 

if (!isset($_SESSION['pre_2fa_user_id'])) {
    header("Location: login"); exit();
}

$error_msg = "";
$show_backup_input = false; // Flag untuk toggle tampilan

// Cek Mode Input (OTP atau Backup)
if (isset($_GET['use_backup'])) {
    $show_backup_input = true;
}

if (isset($_POST['verify_2fa'])) {
    $uid = $_SESSION['pre_2fa_user_id'];
    
    // Ambil secret dan backup code dari DB
    $q = $conn->query("SELECT two_factor_secret, two_factor_backup FROM users WHERE id='$uid'");
    $u = $q->fetch_assoc();
    
    $is_valid = false;
    
    // 1. Verifikasi Kode Backup (Jika input backup diisi)
    if (isset($_POST['backup_code']) && !empty($_POST['backup_code'])) {
        $input_backup = trim($_POST['backup_code']);
        
        // Bandingkan kode backup (Case-sensitive atau tidak, tergantung preferensi. Biasanya Case-Sensitive)
        if ($input_backup === $u['two_factor_backup']) {
            $is_valid = true;
            // Opsi: Regenerate backup code baru setelah dipakai (One-time use) - Disini kita biarkan tetap (Multi-use)
            // Atau log spesifik: "Login menggunakan Kode Backup"
            logActivity($conn, $uid, "Login menggunakan Kode Backup 2FA");
        }
    } 
    
    // 2. Verifikasi Authenticator (Jika input OTP diisi)
    elseif (isset($_POST['otp_code']) && !empty($_POST['otp_code'])) {
        $code = $_POST['otp_code'];
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        if ($google2fa->verifyKey($u['two_factor_secret'], $code)) {
            $is_valid = true;
        }
    }

    if ($is_valid) {
        // --- LOGIN SUKSES ---
        $_SESSION['valselt_user_id'] = $uid;
        logUserDevice($conn, $uid);
        
        $qu = $conn->query("SELECT username FROM users WHERE id='$uid'");
        $userData = $qu->fetch_assoc();
        $_SESSION['valselt_username'] = $userData['username'];
        
        unset($_SESSION['pre_2fa_user_id']);
        unset($_SESSION['pre_2fa_remember']);
        
        // Trust Device Logic
        if (isset($_POST['trust_device'])) {
            $days = 30; 
            if (isset($_SESSION['login_method']) && ($_SESSION['login_method'] == 'google' || $_SESSION['login_method'] == 'github')) {
                $days = 180; 
            }
            $token2fa = bin2hex(random_bytes(32));
            $expiry = time() + (86400 * $days);
            setcookie('valselt_2fa_trusted', $token2fa, $expiry, "/", "", false, true);
            $sess_id = session_id();
            $conn->query("UPDATE user_devices SET two_factor_token='$token2fa' WHERE session_id='$sess_id'");
        }
        
        // Remember Me Logic
        if (isset($_SESSION['login_remember_me']) && $_SESSION['login_remember_me'] == true) {
             handleRememberMe($conn, $uid);
             unset($_SESSION['login_remember_me']);
        }
        
        // Redirect Logic
        $target = isset($_SESSION['sso_redirect_to']) ? $_SESSION['sso_redirect_to'] : 'index.php';
        if (isset($_SESSION['sso_redirect_to'])) {
             processSSORedirect($conn, $uid, $target); 
        } else {
             header("Location: index");
        }
        exit();
        
    } else {
        $error_msg = "Kode salah! Silakan coba lagi.";
        // Tetap di mode backup jika tadi error di mode backup
        if (isset($_POST['backup_code'])) {
            $show_backup_input = true;
        }
    }
}

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
        
        <?php if($show_backup_input): ?>
            <h2 style="margin-bottom: 10px; font-weight: 700;">Two-Step Verification</h2>
            <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 30px;">
                Enter your 32-character recovery code.
            </p>
        <?php else: ?>
            <h2 style="margin-bottom: 10px; font-weight: 700;">Two-Step Verification</h2>
            <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 30px;">
                Enter the 6-digit code from your authenticator app to continue.
            </p>
        <?php endif; ?>

        <?php if($error_msg): ?>
            <div style="background: #fef2f2; color: #b91c1c; padding: 10px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 20px; border: 1px solid #fecaca;">
                <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php if($show_backup_input): ?>
                <input type="text" name="backup_code" class="form-control" placeholder="Masukkan Kode Backup" style="text-align:center; font-size:1.1rem; letter-spacing:1px;" required autofocus>
            <?php else: ?>
                <input type="text" name="otp_code" class="form-control otp-input" placeholder="000000" maxlength="6" required autofocus>
            <?php endif; ?>
            
            <label class="trust-option">
                <input type="checkbox" name="trust_device" value="1" checked>
                Jangan tanya lagi di perangkat ini selama <?php echo $trustDuration; ?>.
            </label>

            <button type="submit" name="verify_2fa" class="btn-verify">Verifikasi</button>
        </form>
        
        <div style="margin-top: 20px; display:flex; flex-direction:column; gap:10px;">
            <?php if($show_backup_input): ?>
                <a href="verify2fa" style="color: #0284c7; font-size: 0.9rem; text-decoration: none; font-weight:600;">
                    Gunakan Authenticator App
                </a>
            <?php else: ?>
                <a href="verify2fa?use_backup=1" style="color: #0284c7; font-size: 0.9rem; text-decoration: none; font-weight:600;">
                    Gunakan Kode Backup
                </a>
            <?php endif; ?>
            
            <a href="login" style="color: #6b7280; font-size: 0.85rem; text-decoration: none;">Kembali ke Login</a>
        </div>
    </div>

</body>
</html>