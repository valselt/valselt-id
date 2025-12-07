<?php
require 'config.php'; 

// 1. TANGKAP TUJUAN SSO
// Kita simpan parameter redirect_to agar tidak hilang saat post form
$redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

// 2. LOGIKA JIKA TOMBOL "LANJUTKAN SEBAGAI..." DITEKAN
if (isset($_POST['confirm_sso']) && isset($_SESSION['valselt_user_id'])) {
    processSSORedirect($conn, $_SESSION['valselt_user_id'], $redirect_to);
}

// 3. CEK STATUS LOGIN USER SAAT INI
$is_logged_in = isset($_SESSION['valselt_user_id']);
$user_info = null;

if ($is_logged_in) {
    // Ambil data user untuk ditampilkan di kartu (Foto & Nama)
    $uid = $_SESSION['valselt_user_id'];
    $q = $conn->query("SELECT * FROM users WHERE id='$uid'");
    $user_info = $q->fetch_assoc();
    
    // Jika user login manual (bukan dari Spencal), langsung masuk dashboard Valselt
    if (empty($redirect_to)) {
        header("Location: index.php");
        exit();
    }
}

// 4. PROSES LOGIN BIASA (USERNAME & PASSWORD)
if (isset($_POST['login'])) {
    $user_input = $conn->real_escape_string($_POST['user_input']);
    $password = $_POST['password'];
    
    $result = $conn->query("SELECT * FROM users WHERE username='$user_input' OR email='$user_input'");
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            if ($row['is_verified'] == 0) {
                $_SESSION['verify_email'] = $row['email'];
                $_SESSION['popup_status'] = 'warning';
                $_SESSION['popup_message'] = 'Akun belum aktif. Masukkan OTP.';
                $_SESSION['popup_redirect'] = 'verify.php';
            } else {
                // Set Session
                $_SESSION['valselt_user_id'] = $row['id'];
                $_SESSION['valselt_username'] = $row['username'];
                
                // Login sukses langsung redirect (tanpa konfirmasi lagi karena baru saja ketik password)
                processSSORedirect($conn, $row['id'], $redirect_to);
            }
        } else {
            $_SESSION['popup_status'] = 'error'; $_SESSION['popup_message'] = 'Password salah!';
        }
    } else {
        $_SESSION['popup_status'] = 'error'; $_SESSION['popup_message'] = 'Akun tidak ditemukan!';
    }
}

// Fungsi Helper Redirect
function processSSORedirect($conn, $uid, $target) {
    if (!empty($target)) {
        $token = bin2hex(random_bytes(32));
        $conn->query("UPDATE users SET auth_token='$token' WHERE id='$uid'");
        header("Location: " . $target . "?token=" . $token);
    } else {
        header("Location: index.php");
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-brand" style="color:#4f46e5;">valselt<span>.id</span></div>

            <?php if ($is_logged_in && !empty($redirect_to)): ?>
                <h4 class="auth-title">Lanjutkan ke Aplikasi?</h4>
                
                <form method="POST">
                    <div class="account-chooser-card" onclick="document.getElementById('btnConfirm').click()">
                        <?php if($user_info['profile_pic']): ?>
                            <img src="<?php echo $user_info['profile_pic']; ?>" class="ac-avatar">
                        <?php else: ?>
                            <div class="ac-avatar" style="background:#4f46e5; color:white; display:flex; align-items:center; justify-content:center; font-size:1.5rem;">
                                <?php echo strtoupper(substr($user_info['username'], 0, 2)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="ac-username"><?php echo htmlspecialchars($user_info['username']); ?></div>
                        <div class="ac-email"><?php echo htmlspecialchars($user_info['email']); ?></div>
                    </div>

                    <button type="submit" name="confirm_sso" id="btnConfirm" class="btn-continue">
                        Lanjutkan sebagai <?php echo htmlspecialchars($user_info['username']); ?> <i class='bx bx-right-arrow-alt'></i>
                    </button>
                </form>

                <a href="logout.php?continue=<?php echo urlencode('login.php?redirect_to='.$redirect_to); ?>" class="link-switch-account">
                    Keluar & Gunakan Akun Lain
                </a>

            <?php else: ?>

                <h4 class="auth-title">Satu akun untuk semua.</h4>
                
                <form method="POST" style="text-align:left;">
                    <div class="form-group">
                        <label class="form-label">Username / Email</label>
                        <input type="text" name="user_input" class="form-control" required placeholder="user@valselt.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required placeholder="******">
                    </div>
                    <button type="submit" name="login" class="btn btn-primary">Masuk</button>
                </form>

                <div style="margin-top: 20px; font-size: 0.9rem; color: var(--text-muted);">
                    Belum punya akun? <a href="register.php" style="color: var(--primary); font-weight: 600;">Daftar disini</a>
                </div>

            <?php endif; ?>

        </div>
    </div>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>