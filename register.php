<?php
require 'config.php'; 

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
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

    $uppercase = preg_match('@[A-Z]@', $password);
    $number    = preg_match('@[0-9]@', $password);
    $symbol    = preg_match('@[^\w]@', $password); 
    
    $captcha_success = true; // Set true utk dev

    if (!$captcha_success) {
         $_SESSION['popup_status'] = 'error';
         $_SESSION['popup_message'] = 'Verifikasi Robot Gagal!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $_SESSION['popup_status'] = 'error';
         $_SESSION['popup_message'] = 'Format email tidak valid!';
    } elseif(!$uppercase || !$number || !$symbol || strlen($password) < 6) {
         $_SESSION['popup_status'] = 'error';
         $_SESSION['popup_message'] = 'Password lemah!';
    } else {
        $cek = $conn->query("SELECT id FROM users WHERE username='$username' OR email='$email'");
        
        if($cek->num_rows > 0){
             $_SESSION['popup_status'] = 'error';
             $_SESSION['popup_message'] = 'Username atau Email sudah terdaftar!';
        } else {
             $password_hash = password_hash($password, PASSWORD_DEFAULT);
             $otp = rand(100000, 999999);
             $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

             $stmt = $conn->prepare("INSERT INTO users (username, email, password, otp, otp_expiry, is_verified) VALUES (?, ?, ?, ?, ?, 0)");
             $stmt->bind_param("sssss", $username, $email, $password_hash, $otp, $expiry);
             
             if ($stmt->execute()) {
                 if(sendOTPEmail($email, $otp)) {
                      $_SESSION['verify_email'] = $email;
                      $_SESSION['popup_status'] = 'success';
                      $_SESSION['popup_message'] = 'Registrasi Berhasil! Cek Email.';
                      header("Location: verify.php");
                      exit();
                 } else {
                      $_SESSION['popup_status'] = 'error';
                      $_SESSION['popup_message'] = 'Gagal kirim email OTP.';
                 }
             } else {
                 $_SESSION['popup_status'] = 'error';
                 $_SESSION['popup_message'] = 'Database Error.';
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
    <title>Daftar - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>

    <div class="split-screen">
        <div class="left-pane">
            <div class="left-pane-bg"></div>
            <div class="left-content">
                <div>
                    <img src="https://cdn.ivanaldorino.web.id/valselt/valselt_white.png" alt="Valselt Logo" style="height: 40px;">
                </div>
                <div class="hero-text">
                    <div class="quote-badge">Join Us Today</div>
                    <h1>Create Your<br>Legacy</h1>
                    <p>Start your journey with us and discover a world of possibilities tailored just for you.</p>
                </div>
            </div>
        </div>

        <div class="right-pane">
            <div class="auth-box">
                <img src="https://cdn.ivanaldorino.web.id/valselt/valselt_black.png" alt="Logo" class="logo-auth">

                <div class="auth-header">
                    <h2>Create Account</h2>
                    <p>Register to get started.</p>
                </div>

                <form method="POST" id="regForm">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" id="email" class="form-control" required placeholder="name@example.com" value="<?php echo htmlspecialchars($email_val); ?>">
                            <i class='bx bx-loader-alt validation-icon loading-icon' id="email-loading"></i>
                            <i class='bx bx-check validation-icon valid' id="email-check"></i>
                            <i class='bx bx-x validation-icon invalid' id="email-cross" title="Email sudah terdaftar"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <div class="input-wrapper">
                            <input type="text" name="username" id="username" class="form-control" required placeholder="Unique username" value="<?php echo htmlspecialchars($username_val); ?>">
                            <i class='bx bx-loader-alt validation-icon loading-icon' id="username-loading"></i>
                            <i class='bx bx-check validation-icon valid' id="username-check"></i>
                            <i class='bx bx-x validation-icon invalid' id="username-cross" title="Username sudah dipakai"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control" required placeholder="Strong password">
                        <div class="password-requirements" id="pwd-req-box">
                            <div class="req-item" id="req-len"><i class='bx bx-check'></i> 6+ Characters</div>
                            <div class="req-item" id="req-upper"><i class='bx bx-check'></i> Uppercase (A-Z)</div>
                            <div class="req-item" id="req-num"><i class='bx bx-check'></i> Number (0-9)</div>
                            <div class="req-item" id="req-sym"><i class='bx bx-check'></i> Symbol (!@#$)</div>
                        </div>
                    </div>

                    <div class="captcha-wrapper">
                        <div class="g-recaptcha" data-sitekey="<?php echo $recaptcha_site_key; ?>"></div>
                    </div>
                    
                    <button type="submit" name="register" id="btn-submit" class="btn btn-primary">Sign Up</button>
                </form>

                <div class="auth-links">
                    Already have an account? <a href="login.php">Sign In</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // --- LOGIC VALIDASI INPUT ---

        // Fungsi Sentral untuk Mengatur Ikon
        // state options: 'hidden', 'loading', 'valid', 'invalid'
        function updateIconState(type, state) {
            const loading = document.getElementById(type + '-loading');
            const check = document.getElementById(type + '-check');
            const cross = document.getElementById(type + '-cross');

            // 1. Reset: Sembunyikan SEMUA ikon terlebih dahulu
            loading.style.display = 'none';
            check.style.display = 'none';
            cross.style.display = 'none';

            // 2. Tampilkan sesuai state yang diminta
            if (state === 'loading') {
                loading.style.display = 'block';
            } else if (state === 'valid') {
                check.style.display = 'block';
            } else if (state === 'invalid') {
                cross.style.display = 'block';
            }
            // Jika state === 'hidden', tidak ada yang dinyalakan (tetap reset)
        }

        function checkAvailability(type, value) {
            // Jangan cek jika input kosong/pendek
            if (value.length < 3) {
                updateIconState(type, 'hidden');
                return;
            }

            // Tampilkan Loading
            updateIconState(type, 'loading');

            fetch('check_availability.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: type, value: value })
            })
            .then(res => res.json())
            .then(data => {
                // Pastikan nilai input belum berubah saat response diterima
                const currentVal = document.getElementById(type).value;
                if (currentVal !== value) return;

                if (data.status === 'available') {
                    updateIconState(type, 'valid');
                } else {
                    updateIconState(type, 'invalid');
                }
            })
            .catch(err => {
                console.error(err);
                updateIconState(type, 'hidden'); // Sembunyikan jika error
            });
        }

        let emailTimer, userTimer;

        // --- Event Listener Email ---
        document.getElementById('email').addEventListener('keyup', function() {
            clearTimeout(emailTimer);
            
            // Saat mengetik, sembunyikan semua ikon agar bersih
            updateIconState('email', 'hidden'); 
            
            const val = this.value;
            if(val.length >= 3) {
                emailTimer = setTimeout(() => { checkAvailability('email', val); }, 800);
            }
        });

        // --- Event Listener Username ---
        document.getElementById('username').addEventListener('keyup', function() {
            clearTimeout(userTimer);
            updateIconState('username', 'hidden');
            
            const val = this.value;
            if(val.length >= 3) {
                userTimer = setTimeout(() => { checkAvailability('username', val); }, 800);
            }
        });

        // --- Password Checker (Visual Only) ---
        const pwdInput = document.getElementById('password');
        const reqBox = document.getElementById('pwd-req-box');
        
        pwdInput.addEventListener('focus', function() { checkPwd(this.value); });
        pwdInput.addEventListener('blur', function() { reqBox.classList.remove('show'); });
        pwdInput.addEventListener('keyup', function() { checkPwd(this.value); });

        function checkPwd(val) {
            const valid = val.length >= 6 && /[A-Z]/.test(val) && /[0-9]/.test(val) && /[^\w]/.test(val);
            
            updateReq('req-len', val.length >= 6);
            updateReq('req-upper', /[A-Z]/.test(val));
            updateReq('req-num', /[0-9]/.test(val));
            updateReq('req-sym', /[^\w]/.test(val));

            if (valid) reqBox.classList.remove('show');
            else if(document.activeElement === pwdInput) reqBox.classList.add('show');
        }

        function updateReq(id, isValid) {
            const el = document.getElementById(id);
            const icon = el.querySelector('i');
            if(isValid) {
                el.className = 'req-item valid';
                icon.className = 'bx bx-check';
            } else {
                el.className = 'req-item invalid';
                icon.className = 'bx bx-check'; // Tetap check tapi abu-abu
            }
        }
    </script>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>