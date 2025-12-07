<?php
require 'config.php';

if (!isset($_SESSION['verify_email'])) {
    header("Location: register.php"); exit();
}

$email = $_SESSION['verify_email'];

if (isset($_POST['verify'])) {
    $input_otp = $_POST['otp'];
    
    // Cek OTP di Database
    $stmt = $conn->prepare("SELECT id, otp, otp_expiry FROM users WHERE email = ? AND is_verified = 0");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    if ($user) {
        $now = date('Y-m-d H:i:s');
        if ($user['otp'] == $input_otp && $user['otp_expiry'] > $now) {
            // SUKSES VERIFIKASI
            $uid = $user['id'];
            $conn->query("UPDATE users SET is_verified = 1, otp = NULL WHERE id = '$uid'");
            
            unset($_SESSION['verify_email']); // Hapus session temp
            
            $_SESSION['popup_status'] = 'success';
            $_SESSION['popup_message'] = 'Akun terverifikasi! Silakan Login.';
            header("Location: login.php"); 
            exit();
        } else {
            $_SESSION['popup_status'] = 'error';
            $_SESSION['popup_message'] = 'Kode OTP salah atau sudah kadaluarsa!';
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
    <title>Verifikasi OTP - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-brand">spencal<span>.</span></div>
            <h4 class="auth-title">Verifikasi Email</h4>
            <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:20px;">
                Masukkan 6 digit kode yang dikirim ke <br><b><?php echo htmlspecialchars($email); ?></b>
            </p>

            <form method="POST">
                <div class="form-group">
                    <input type="text" name="otp" class="form-control" placeholder="123456" maxlength="6" style="text-align:center; font-size:1.5rem; letter-spacing:5px;" required>
                </div>
                <button type="submit" name="verify" class="btn btn-primary">Verifikasi</button>
            </form>
        </div>
    </div>
    <?php include '../popupcustom.php'; ?>
</body>
</html>