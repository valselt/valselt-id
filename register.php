<?php
// HAPUS session_start() DISINI
require 'config.php'; 

if (isset($_SESSION['user_id'])) {
    header("Location: index.php"); // Ganti ../index.php jadi index.php
    exit();
}

$email_val = '';
$username_val = '';

// --- PROSES REGISTRASI ---
if (isset($_POST['register'])) {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $username = htmlspecialchars($_POST['username']);
    $password = $_POST['password']; 
    $recaptcha_response = $_POST['g-recaptcha-response'];
    
    $email_val = $email;
    $username_val = $username;

    // ... Validasi Regex & Recaptcha (Sama seperti sebelumnya) ...
    $uppercase = preg_match('@[A-Z]@', $password);
    $number    = preg_match('@[0-9]@', $password);
    $symbol    = preg_match('@[^\w]@', $password); 
    
    // Bypass Recaptcha for Dev (Opsional)
    $captcha_success = true; 

    if (!$captcha_success) {
         $_SESSION['popup_status'] = 'error';
         $_SESSION['popup_message'] = 'Verifikasi Robot Gagal! Silakan coba lagi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $_SESSION['popup_status'] = 'error';
         $_SESSION['popup_message'] = 'Format email tidak valid!';
    } elseif(!$uppercase || !$number || !$symbol || strlen($password) < 6) {
         $_SESSION['popup_status'] = 'error';
         $_SESSION['popup_message'] = 'Password tidak memenuhi syarat keamanan!';
    } else {
        // PERBAIKAN: Gunakan $conn, BUKAN $conn_valselt
        $cek = $conn->query("SELECT id FROM users WHERE username='$username' OR email='$email'");
        
        if($cek->num_rows > 0){
             $_SESSION['popup_status'] = 'error';
             $_SESSION['popup_message'] = 'Username atau Email sudah terdaftar!';
        } else {
             $password_hash = password_hash($password, PASSWORD_DEFAULT);
             $otp = rand(100000, 999999);
             $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

             // PERBAIKAN: Gunakan $conn
             $stmt = $conn->prepare("INSERT INTO users (username, email, password, otp, otp_expiry, is_verified) VALUES (?, ?, ?, ?, ?, 0)");
             $stmt->bind_param("sssss", $username, $email, $password_hash, $otp, $expiry);
             
             if ($stmt->execute()) {
                 // Tidak perlu seedCategories disini (karena Valselt tidak urus kategori)
                 
                 if(sendOTPEmail($email, $otp)) {
                     $_SESSION['verify_email'] = $email;
                     $_SESSION['popup_status'] = 'success';
                     $_SESSION['popup_message'] = 'Registrasi Berhasil! Kode OTP telah dikirim ke email Anda.';
                     header("Location: verify.php"); // Path relatif benar
                     exit();
                 } else {
                     $_SESSION['popup_status'] = 'error';
                     $_SESSION['popup_message'] = 'Gagal mengirim email OTP. Coba lagi.';
                 }
             } else {
                 $_SESSION['popup_status'] = 'error';
                 $_SESSION['popup_message'] = 'Terjadi kesalahan sistem database.';
             }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link rel="icon" href="https://cdn.ivanaldorino.web.id/spencal/spencal_favicon.png" type="image/png">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-brand" style="color:#4f46e5;">valselt<span>.id</span></div>
            <h4 class="auth-title">Buat akun baru</h4>

            <form method="POST" style="text-align:left;" id="regForm">
                <div class="form-group">
                    <label class="form-label">Alamat Email</label>
                    <div class="input-wrapper">
                        <input type="email" name="email" id="email" class="form-control" required placeholder="nama@email.com" value="<?php echo htmlspecialchars($email_val); ?>">
                        <i class='bx bx-loader-alt validation-icon loading-icon' id="email-loading"></i>
                        <i class='bx bx-check validation-icon valid' id="email-check"></i>
                        <i class='bx bx-x validation-icon invalid' id="email-cross" title="Email sudah terdaftar"></i>
                    </div>
                </div>
                 <div class="form-group">
                    <label class="form-label">Username</label>
                    <div class="input-wrapper">
                        <input type="text" name="username" id="username" class="form-control" required placeholder="Username unik" value="<?php echo htmlspecialchars($username_val); ?>">
                        <i class='bx bx-loader-alt validation-icon loading-icon' id="username-loading"></i>
                        <i class='bx bx-check validation-icon valid' id="username-check"></i>
                        <i class='bx bx-x validation-icon invalid' id="username-cross" title="Username sudah dipakai"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control" required placeholder="Buat password kuat">
                    <div class="password-requirements" id="pwd-req-box">
                        <div class="req-item" id="req-len"><i class='bx bx-check'></i> Minimal 6 Karakter</div>
                        <div class="req-item" id="req-upper"><i class='bx bx-check'></i> 1 Huruf Besar (A-Z)</div>
                        <div class="req-item" id="req-num"><i class='bx bx-check'></i> 1 Angka (0-9)</div>
                        <div class="req-item" id="req-sym"><i class='bx bx-check'></i> 1 Simbol (!@#$...)</div>
                    </div>
                </div>

                <div class="captcha-wrapper">
                    <div class="g-recaptcha" data-sitekey="<?php echo $recaptcha_site_key; ?>"></div>
                </div>
                
                <button type="submit" name="register" id="btn-submit" class="btn btn-primary">Daftar Akun Baru</button>
            </form>

            <div style="margin-top: 20px; font-size: 0.9rem; color: var(--text-muted);">
                Sudah punya akun? <a href="login.php" style="color: var(--primary); font-weight: 600;">Masuk disini</a>
            </div>
        </div>
    </div>
    
    <script>
        function hideAllIcons(type) {
            // Helper ini sekarang fleksibel menerima 'email' atau 'username'
            if(document.getElementById(type + '-loading')) document.getElementById(type + '-loading').style.display = 'none';
            if(document.getElementById(type + '-check')) document.getElementById(type + '-check').style.display = 'none';
            if(document.getElementById(type + '-cross')) document.getElementById(type + '-cross').style.display = 'none';
        }

        // --- 1. LIVE CHECK FUNCTION ---
        function checkAvailability(type, valueToCheck) {
            const loading = document.getElementById(type + '-loading');
            const check = document.getElementById(type + '-check');
            const cross = document.getElementById(type + '-cross');
            const inputField = document.getElementById(type === 'email' ? 'email' : 'username');

            if(inputField.value !== valueToCheck) return; 
            if(valueToCheck.length < 3) return;

            loading.style.display = 'block';

            fetch('check_availability.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: type, value: valueToCheck })
            })
            .then(response => response.json())
            .then(data => {
                if(inputField.value !== valueToCheck) return;

                loading.style.display = 'none';
                
                if(data.status === 'available') {
                    check.style.display = 'block';
                } else {
                    cross.style.display = 'block';
                }
            })
            .catch(err => {
                console.error(err);
                loading.style.display = 'none';
            });
        }

        // --- Event Listeners ---
        let emailTimer, userTimer;

        document.getElementById('email').addEventListener('keyup', function() {
            clearTimeout(emailTimer);
            hideAllIcons('email');
            const val = this.value;
            if(val.length >= 3) {
                emailTimer = setTimeout(() => { checkAvailability('email', val); }, 800);
            }
        });

        // PERBAIKAN LISTENER USERNAME
        document.getElementById('username').addEventListener('keyup', function() {
            clearTimeout(userTimer);
            hideAllIcons('username'); // PENTING: Gunakan 'username', bukan 'user'
            const val = this.value;
            if(val.length >= 3) {
                userTimer = setTimeout(() => { checkAvailability('username', val); }, 800);
            }
        });

        // --- 2. PASSWORD CHECKER (Animated) ---
        const pwdInput = document.getElementById('password');
        const reqBox = document.getElementById('pwd-req-box');
        
        pwdInput.addEventListener('focus', function() {
            checkPasswordValidity(this.value);
        });
        
        pwdInput.addEventListener('blur', function() {
            // Tutup animasi dengan menghapus class .show
            reqBox.classList.remove('show');
        });
        
        pwdInput.addEventListener('keyup', function() {
            checkPasswordValidity(this.value);
        });

        function checkPasswordValidity(val) {
            const hasUpper = /[A-Z]/.test(val);
            const hasNum   = /[0-9]/.test(val);
            const hasSym   = /[^\w]/.test(val); 
            const hasLen   = val.length >= 6;
            
            const allValid = hasUpper && hasNum && hasSym && hasLen;

            updateReq('req-len', hasLen);
            updateReq('req-upper', hasUpper);
            updateReq('req-num', hasNum);
            updateReq('req-sym', hasSym);

            if (allValid) {
                // Jika valid semua, tutup animasi
                reqBox.classList.remove('show');
            } else {
                // Jika belum valid dan sedang fokus, buka animasi
                if(document.activeElement === pwdInput) {
                    reqBox.classList.add('show');
                }
            }
        }

        function updateReq(elementId, isValid) {
            const el = document.getElementById(elementId);
            const icon = el.querySelector('i');
            if(isValid) {
                el.classList.add('valid');
                el.classList.remove('invalid');
                icon.className = 'bx bx-check';
                el.style.color = 'var(--success)';
            } else {
                el.classList.remove('valid');
                el.classList.add('invalid');
                icon.className = 'bx bx-check';
                el.style.color = 'var(--text-muted)';
            }
        }

        // --- 3. AUTO CHECK ON LOAD ---
        document.addEventListener("DOMContentLoaded", function() {
            const emailIn = document.getElementById('email');
            const userIn = document.getElementById('username');

            if(emailIn.value.length >= 3) checkAvailability('email', emailIn.value);
            if(userIn.value.length >= 3) checkAvailability('username', userIn.value);
        });
    </script>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>