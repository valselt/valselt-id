<?php
require 'config.php';

if (!isset($_SESSION['verify_email'])) {
    header("Location: register.php"); exit();
}

$email = $_SESSION['verify_email'];

if (isset($_POST['verify'])) {
    $input_otp = $_POST['otp'];
    
    $stmt = $conn->prepare("SELECT id, otp, otp_expiry FROM users WHERE email = ? AND is_verified = 0");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    if ($user) {
        $now = date('Y-m-d H:i:s');
        if ($user['otp'] == $input_otp && $user['otp_expiry'] > $now) {
            $uid = $user['id'];
            $conn->query("UPDATE users SET is_verified = 1, otp = NULL WHERE id = '$uid'");
            
            unset($_SESSION['verify_email']);
            
            $_SESSION['popup_status'] = 'success';
            $_SESSION['popup_message'] = 'Akun terverifikasi! Silakan Login.';
            header("Location: login.php"); 
            exit();
        } else {
            $_SESSION['popup_status'] = 'error';
            $_SESSION['popup_message'] = 'Kode OTP salah/kadaluarsa!';
        }
    } else {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Akun tidak ditemukan.';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Verifikasi - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body style="display:flex; align-items:center; justify-content:center; height:100vh; background:var(--bg-light);">

    <div class="auth-box" style="background:white; padding:40px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.05); max-width:400px; text-align:center;">
        <img src="https://cdn.ivanaldorino.web.id/valselt/valselt_black.png" alt="Valselt" style="height:30px; margin-bottom:20px;">
        
        <h2 style="font-family:var(--font-serif); font-weight:400; font-size:2rem; margin-bottom:10px;">Verification</h2>
        <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:30px;">
            Enter the 6-digit code sent to<br><b><?php echo htmlspecialchars($email); ?></b>
        </p>

        <form method="POST">
            <div class="form-group">
                <input type="text" name="otp" class="form-control" placeholder="000000" maxlength="6" style="text-align:center; font-size:2rem; letter-spacing:8px; height:60px; font-family:var(--font-sans); font-weight:700;" required>
            </div>
            <button type="submit" name="verify" class="btn btn-primary">Verify Account</button>
        </form>
        
        <div style="margin-top:20px; font-size:0.85rem; color:var(--text-muted);">
            Didn't receive code? <a href="#" style="text-decoration:underline;">Resend</a>
        </div>
    </div>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>