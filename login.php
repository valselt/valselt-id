<?php
require 'config.php'; 

// --- AJAX HANDLER FORGOT PASSWORD ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // 1. KIRIM OTP KE EMAIL
    if ($_POST['ajax_action'] == 'send_reset_otp') {
        $email = $conn->real_escape_string($_POST['email']);
        
        $q = $conn->query("SELECT id FROM users WHERE email='$email'");
        if ($q->num_rows > 0) {
            $user = $q->fetch_assoc();
            $uid = $user['id'];
            
            $otp = rand(100000, 999999);
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            $conn->query("UPDATE users SET otp='$otp', otp_expiry='$expiry' WHERE id='$uid'");
            
            if (sendOTPEmail($email, $otp)) {
                echo json_encode(['status' => 'success', 'uid' => $uid]); 
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal mengirim email.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Email tidak terdaftar.']);
        }
        exit();
    }
    
    // 2. VERIFIKASI OTP SAJA (Untuk lanjut ke popup password)
    elseif ($_POST['ajax_action'] == 'verify_otp_reset') {
        $uid = $conn->real_escape_string($_POST['uid']);
        $otp = $conn->real_escape_string($_POST['otp']);
        $now = date('Y-m-d H:i:s');

        $q = $conn->query("SELECT id FROM users WHERE id='$uid' AND otp='$otp' AND otp_expiry > '$now'");
        
        if ($q->num_rows > 0) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Kode OTP salah atau kadaluarsa!']);
        }
        exit();
    }

    // 3. SIMPAN PASSWORD BARU
    elseif ($_POST['ajax_action'] == 'save_reset_password') {
        $uid = $conn->real_escape_string($_POST['uid']);
        $otp = $conn->real_escape_string($_POST['otp']); // Kirim OTP lagi untuk keamanan ganda
        $new_pass = $_POST['new_password'];
        
        // Cek OTP lagi (agar tidak ditembak API langsung tanpa OTP)
        $q = $conn->query("SELECT id FROM users WHERE id='$uid' AND otp='$otp'");
        if ($q->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Sesi tidak valid/OTP salah.']);
            exit();
        }

        // Validasi Password
        $uppercase = preg_match('@[A-Z]@', $new_pass);
        $number    = preg_match('@[0-9]@', $new_pass);
        $symbol    = preg_match('@[^\w]@', $new_pass);

        if (strlen($new_pass) < 6 || !$uppercase || !$number || !$symbol) {
            echo json_encode(['status' => 'error', 'message' => 'Password tidak memenuhi syarat.']);
            exit();
        }
        
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password='$hash', otp=NULL WHERE id='$uid'");
        
        $_SESSION['popup_status'] = 'success';
        $_SESSION['popup_message'] = 'Password berhasil direset! Silakan login.';
        
        echo json_encode(['status' => 'success']);
        exit();
    }
}

$github_auth_url = "https://github.com/login/oauth/authorize?client_id=" . $github_client_id . "&scope=user:email";

if(isset($_GET['redirect_to'])){
    $_SESSION['sso_redirect_to'] = $_GET['redirect_to'];
}

// 1. TANGKAP TUJUAN SSO
$redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

// --- DECODE BASE64 ---
// Jika parameter ada isinya tapi TIDAK dimulai dengan http, berarti itu Base64
if (!empty($redirect_to) && strpos($redirect_to, 'http') !== 0) {
    $decoded = base64_decode($redirect_to, true);
    // Pastikan hasil decode valid
    if ($decoded !== false) {
        $redirect_to = $decoded;
    }
}
// ---------------------

if(isset($_GET['redirect_to'])){
    // Simpan yang sudah di-decode ke session
    $_SESSION['sso_redirect_to'] = $redirect_to; 
}

// 2. LOGIKA JIKA TOMBOL "LANJUTKAN SEBAGAI..." DITEKAN
if (isset($_POST['confirm_sso']) && isset($_SESSION['valselt_user_id'])) {
    processSSORedirect($conn, $_SESSION['valselt_user_id'], $redirect_to);
}

// 3. CEK STATUS LOGIN
$is_logged_in = isset($_SESSION['valselt_user_id']);
$user_info = null;

if ($is_logged_in) {
    $uid = $_SESSION['valselt_user_id'];
    $q = $conn->query("SELECT * FROM users WHERE id='$uid'");
    $user_info = $q->fetch_assoc();
    
    if (empty($redirect_to)) {
        header("Location: ./");
        exit();
    }
}

// 4. PROSES LOGIN BIASA
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
                $_SESSION['popup_redirect'] = 'verify';
            } else {
                // ==========================================
                // MODIFIKASI LOGIKA 2FA DI SINI
                // ==========================================
                
                $uid = $row['id'];
                
                // 1. Cek apakah User mengaktifkan 2FA?
                if ($row['is_2fa_enabled'] == 1) {
                    
                    // 2. Cek apakah Device ini TRUSTED?
                    if (checkTrustedDevice($conn, $uid)) {
                        // SKIP 2FA -> Login Langsung
                        doLogin($row, $redirect_to, $conn);
                    } else {
                        // WAJIB 2FA -> Redirect ke verify2fa.php
                        $_SESSION['pre_2fa_user_id'] = $uid; // Simpan ID sementara
                        $_SESSION['login_method'] = 'manual'; // Tandai metode login
                        
                        // Simpan status remember me user (untuk dieksekusi nanti setelah lolos 2FA)
                        if (isset($_POST['remember_me'])) {
                            $_SESSION['login_remember_me'] = true;
                        }
                        
                        // Catat Device Dulu (Agar bisa diupdate tokennya nanti)
                        logUserDevice($conn, $uid); 
                        logActivity($conn, $uid, "Login Manual: Meminta Verifikasi 2FA");
                        
                        header("Location: verify2fa");
                        exit();
                    }
                } else {
                    // Tidak pakai 2FA -> Login Langsung
                    
                    // Handle Remember Me disini jika tidak ada 2FA
                    if (isset($_POST['remember_me'])) {
                        handleRememberMe($conn, $uid);
                    }
                    
                    doLogin($row, $redirect_to, $conn);
                }
            }
        } else {
            $_SESSION['popup_status'] = 'error'; $_SESSION['popup_message'] = 'Username/Email atau Password salah!';
        }
    } else {
        $_SESSION['popup_status'] = 'error'; $_SESSION['popup_message'] = 'Akun tidak ditemukan!';
    }
}

function doLogin($row, $redirect_to, $conn) {
    $_SESSION['valselt_user_id'] = $row['id'];
    $_SESSION['valselt_username'] = $row['username'];
    
    logUserDevice($conn, $row['id']); 
    $deviceInfo = getDeviceName(); 
    logActivity($conn, $row['id'], "Login Berhasil (Manual) di perangkat $deviceInfo");
    
    processSSORedirect($conn, $row['id'], $redirect_to);
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
    
    <div class="split-screen">
        <div class="left-pane">
            <div class="carousel-wrapper" id="bg-carousel">
                <div class="carousel-slide active" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/1.png');"></div>
                <div class="carousel-slide" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/2.png');"></div>
                <div class="carousel-slide" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/3.png');"></div>
                <div class="carousel-slide" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/4.png');"></div>
                <div class="carousel-slide" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/5.png');"></div>
                <div class="carousel-slide" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/6.png');"></div>
                <div class="carousel-slide" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/7.png');"></div>
                <div class="carousel-slide" style="background-image: url('https://cdn.ivanaldorino.web.id/valselt/carousel-left-pane/8.png');"></div>
            </div>

            <div class="left-content">
                <div>
                    <img src="https://cdn.ivanaldorino.web.id/valselt/valselt_white.png" alt="Valselt Logo" style="height: 40px;">
                </div>
                
                <div class="carousel-indicators" id="carousel-indicators"></div>

                <div class="hero-text">
                    <div class="quote-badge">A Wise Quote</div>
                    <h1>Build The Life<br>You Imagine.</h1>
                    <p>The moment you commit to your vision, the world begins arranging itself to help you achieve it.</p>
                </div>
            </div>
        </div>

        <div class="right-pane">
            <div class="auth-box">

                <?php if ($is_logged_in && !empty($redirect_to)): ?>
                    <div class="auth-header">
                        <h2>Lanjutkan?</h2>
                        <p>Klik di bawah untuk masuk ke aplikasi tujuan.</p>
                    </div>
                    
                    <form method="POST">
                        <div class="account-chooser-card" onclick="document.getElementById('btnConfirm').click()">
                            <?php if($user_info['profile_pic']): ?>
                                <img src="<?php echo $user_info['profile_pic']; ?>" class="ac-avatar">
                            <?php else: ?>
                                <div class="ac-avatar avatar-placeholder" style="width:70px; height:70px; display:flex; align-items:center; justify-content:center; font-size:1.5rem;">
                                    <?php echo strtoupper(substr($user_info['username'], 0, 2)); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="ac-username"><?php echo htmlspecialchars($user_info['username']); ?></div>
                            <div class="ac-email"><?php echo htmlspecialchars($user_info['email']); ?></div>
                        </div>

                        <button type="submit" name="confirm_sso" id="btnConfirm" class="btn-continue">
                            Lanjutkan <i class='bx bx-right-arrow-alt'></i>
                        </button>
                    </form>

                    <div class="auth-links">
                        <?php
                        $continue = base64_encode('login?redirect_to='.$redirect_to);
                        ?>
                        <?php
                            // 1. Siapkan URL tujuan: 'login?redirect_to=...'
                            // Kita encode $redirect_to lagi agar aman saat dikirim kembali ke login
                            $next_target = 'login';
                            if (!empty($redirect_to)) {
                                // Encode redirect_to agar aman di URL
                                $next_target .= '?redirect_to=' . base64_encode($redirect_to);
                            }
                            
                            // 2. Encode SELURUH URL tujuan logout agar server tidak memblokir
                            $encoded_continue = base64_encode($next_target);
                        ?>
                        <a href="logout?continue=<?php echo $encoded_continue; ?>">
                            Gunakan Akun Lain
                        </a>
                    </div>

                <?php else: ?>
                    <div class="auth-header">
                        <h2>Welcome Back</h2>
                        <p>Enter your email and password to access your account.</p>
                    </div>

                    <form method="POST">
                        <div class="form-group">
                            <label class="form-label">Email atau Username</label>
                            <input type="text" name="user_input" class="form-control" placeholder="Enter your email" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                        </div>
                        
                        <div style="display:flex; justify-content:space-between; margin-bottom:20px; font-size:0.9rem; color:var(--text-muted);">
                            <label><input type="checkbox" name="remember_me" value="1"> Remember Me</label>
                            <a href="#" onclick="openForgotModal()" style="color:var(--text-main); font-weight:600;">Forgot Password?</a>
                        </div>

                        <button type="submit" name="login" class="btn btn-primary">Sign In</button>
                    </form>

                    <div style="text-align:center; margin: 20px 0;">
                        <span style="background:white; padding:0 10px; color:var(--text-muted); position:relative; z-index:1;">OR</span>
                        <hr style="margin-top:-10px; border:0; border-top:1px solid #e5e7eb;">
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="<?php echo $google_client->createAuthUrl(); ?>" class="btn-google">
                            <img src="https://www.svgrepo.com/show/475656/google-color.svg" alt="G" style="width:20px; margin-right:10px;">
                            Sign in with Google
                        </a>

                        <a href="<?php echo $github_auth_url; ?>" class="btn-social">
                            <i class='bx bxl-github' style="font-size:1.4rem; color:#24292e; margin-right:10px;"></i>
                            Sign in with GitHub
                        </a>
                        <button type="button" onclick="loginPasskey()" class="btn-social" style="font-size:1rem;">
                            <i class='bx bx-fingerprint' style="font-size:1.4rem; color:#24292e; margin-right:10px;"></i>
                            Sign in with Passkey
                        </button>
                    </div>
                    
                    <div class="auth-links">
                        Don't have an account? <a href="register">Sign Up</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="popup-overlay" id="modalForgotEmail" style="display:none; opacity:0; transition: opacity 0.3s; z-index: 9999;">
        <div class="popup-box" style="width: 400px; max-width: 90%;">
            <div class="popup-icon-box warning"><i class='bx bx-envelope'></i></div>
            <h3 class="popup-title">Forgot Password?</h3>
            <p class="popup-message">Enter your registered email address to receive the OTP code.</p>
            
            <input type="email" id="reset_email_input" class="form-control" placeholder="nama@email.com" style="margin-bottom:10px;">
            
            <p id="email_error" style="color:#ef4444; font-size:0.85rem; margin-bottom:15px; display:none; font-weight:500; text-align:center;">
                Email not registered.
            </p>
            
            <div style="display:flex; gap:10px;">
                <button onclick="closeModal('modalForgotEmail')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
                <button onclick="processSendOTP()" id="btnSendOTP" class="popup-btn warning">Send OTP</button>
            </div>
        </div>
    </div>

    <div class="popup-overlay" id="modalForgotOTP" style="display:none; opacity:0; transition: opacity 0.3s; z-index: 9999;">
        <div class="popup-box" style="width: 400px; max-width: 90%;">
            <div class="popup-icon-box warning"><i class='bx bx-message-dots'></i></div>
            <h3 class="popup-title">Verifikasi OTP</h3>
            <p class="popup-message">Masukkan 6 digit kode yang dikirim ke email Anda.</p>
            
            <input type="text" id="reset_otp_input" class="form-control" placeholder="000000" maxlength="6" style="margin-bottom:15px; text-align:center; letter-spacing:5px; font-size:1.2rem;">
            
            <div style="display:flex; gap:10px;">
                <button onclick="closeModal('modalForgotOTP')" class="popup-btn" style="background:#f3f4f6; color:#111;">Batal</button>
                <button onclick="processVerifyOTP()" id="btnVerifyOTP" class="popup-btn warning">Verifikasi</button>
            </div>
        </div>
    </div>

    <div class="popup-overlay" id="modalResetPass" style="display:none; opacity:0; transition: opacity 0.3s; z-index: 9999;">
        <div class="popup-box" style="width: 400px; max-width: 90%;">
            <div class="popup-icon-box success"><i class='bx bx-lock-alt'></i></div>
            <h3 class="popup-title">Password Baru</h3>
            <p class="popup-message">Silakan buat password baru Anda.</p>
            
            <input type="password" id="reset_new_pass" class="form-control" placeholder="Password Baru" style="margin-bottom:10px;">
            
            <div class="password-requirements" id="pwd-req-box-modal" style="text-align:left; background:#f9fafb; padding:10px; border-radius:8px; border:1px solid #e5e7eb; margin-bottom:15px; font-size:0.85rem; display:none;">
                <div class="req-item invalid" id="req-len" style="margin-bottom:2px; display:flex; align-items:center; gap:5px;"><i class='bx bx-x'></i> 6+ Characters</div>
                <div class="req-item invalid" id="req-upper" style="margin-bottom:2px; display:flex; align-items:center; gap:5px;"><i class='bx bx-x'></i> Uppercase Letters (A-Z)</div>
                <div class="req-item invalid" id="req-num" style="margin-bottom:2px; display:flex; align-items:center; gap:5px;"><i class='bx bx-x'></i> Numbers (0-9)</div>
                <div class="req-item invalid" id="req-sym" style="margin-bottom:2px; display:flex; align-items:center; gap:5px;"><i class='bx bx-x'></i> Symbols (!@#$)</div>
            </div>

            <div style="display:flex; gap:10px;">
                <button onclick="closeModal('modalResetPass')" class="popup-btn" style="background:#f3f4f6; color:#111;">Batal</button>
                <button onclick="processSaveNewPass()" id="btnSavePassReset" class="popup-btn success" disabled style="opacity:0.6; cursor:not-allowed;">Simpan Password</button>
            </div>
        </div>
    </div>

    
    <script src="webauthn.js"></script>
    <script>
        // --- SCRIPT CAROUSEL LOGIC ---
        document.addEventListener("DOMContentLoaded", function() {
            const slides = document.querySelectorAll('.carousel-slide');
            const indicatorsContainer = document.getElementById('carousel-indicators');
            let currentIndex = 0;
            const intervalTime = 5000; // 5 Detik

            // 1. Buat indikator otomatis
            slides.forEach((slide, index) => {
                const dot = document.createElement('div');
                dot.classList.add('indicator-dot');
                if (index === 0) dot.classList.add('active');
                
                dot.addEventListener('click', () => {
                    goToSlide(index);
                    resetTimer();
                });
                
                indicatorsContainer.appendChild(dot);
            });

            const dots = document.querySelectorAll('.indicator-dot');

            function showSlide(index) {
                slides.forEach(slide => slide.classList.remove('active'));
                dots.forEach(dot => dot.classList.remove('active'));
                
                slides[index].classList.add('active');
                dots[index].classList.add('active');
            }

            function nextSlide() {
                currentIndex = (currentIndex + 1) % slides.length;
                showSlide(currentIndex);
            }

            function goToSlide(index) {
                currentIndex = index;
                showSlide(currentIndex);
            }

            let slideInterval = setInterval(nextSlide, intervalTime);

            function resetTimer() {
                clearInterval(slideInterval);
                slideInterval = setInterval(nextSlide, intervalTime);
            }
        });

        document.getElementById('reset_email_input').addEventListener('input', function() {
            document.getElementById('email_error').style.display = 'none';
        });

        // --- VARIABLES ---
        let resetUserId = null;
        let resetUserOTP = null;

        // --- BUKA MODAL PERTAMA ---
        function openForgotModal() {
            openModal('modalForgotEmail');
            document.getElementById('reset_email_input').value = '';
        }

        // --- STEP 1: KIRIM EMAIL -> BUKA MODAL OTP ---
        function processSendOTP() {
            const emailInput = document.getElementById('reset_email_input');
            const email = emailInput.value;
            const btn = document.getElementById('btnSendOTP');
            const errorMsg = document.getElementById('email_error');
            
            // Reset error
            errorMsg.style.display = 'none';
            
            if(!email) { 
                errorMsg.innerText = "Enter your email address.";
                errorMsg.style.display = 'block';
                return; 
            }
            
            btn.innerText = "Sending OTP..."; btn.disabled = true;
            
            const formData = new FormData();
            formData.append('ajax_action', 'send_reset_otp');
            formData.append('email', email);
            
            fetch('./', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                btn.innerText = "Send OTP"; btn.disabled = false;
                
                if(data.status === 'success') {
                    resetUserId = data.uid; // Simpan UID
                    closeModal('modalForgotEmail'); // Tutup Modal Email
                    
                    // Buka Modal OTP dengan sedikit delay agar animasi smooth
                    setTimeout(() => {
                        openModal('modalForgotOTP');
                        document.getElementById('reset_otp_input').value = '';
                        document.getElementById('reset_otp_input').focus();
                    }, 300);
                } else {
                    // Tampilkan pesan error di bawah input (BUKAN ALERT)
                    errorMsg.innerText = data.message;
                    errorMsg.style.display = 'block';
                    emailInput.focus();
                }
            })
            .catch(err => {
                // Handle error koneksi
                btn.innerText = "Send OTP"; btn.disabled = false;
                errorMsg.innerText = "Connection error occurred.";
                errorMsg.style.display = 'block';
            });
        }

        // --- STEP 2: VERIFIKASI OTP -> BUKA MODAL PASSWORD ---
        function processVerifyOTP() {
            const otp = document.getElementById('reset_otp_input').value;
            const btn = document.getElementById('btnVerifyOTP');
            
            if(!otp || otp.length < 6) { alert("Enter the 6-digit code!"); return; }
            
            btn.innerText = "Verifying..."; btn.disabled = true;
            
            const formData = new FormData();
            formData.append('ajax_action', 'verify_otp_reset');
            formData.append('uid', resetUserId);
            formData.append('otp', otp);
            
            fetch('./', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                btn.innerText = "Verify"; btn.disabled = false;
                
                if(data.status === 'success') {
                    resetUserOTP = otp; // Simpan OTP untuk verifikasi akhir
                    closeModal('modalForgotOTP');
                    
                    // Buka Modal Password Baru
                    setTimeout(() => {
                        openModal('modalResetPass');
                        document.getElementById('reset_new_pass').value = '';
                        document.getElementById('pwd-req-box-modal').style.display = 'none'; // Sembunyikan req box dulu
                    }, 300);
                } else {
                    alert(data.message);
                }
            });
        }

        // --- STEP 3: LOGIC PASSWORD CHECKER (REQ USER) ---
        const newPassInput = document.getElementById('reset_new_pass');
        const reqBoxModal  = document.getElementById('pwd-req-box-modal');
        const btnSavePass  = document.getElementById('btnSavePassReset');

        newPassInput.addEventListener('focus', function() { checkPwd(this.value); });
        newPassInput.addEventListener('keyup', function() { checkPwd(this.value); });
        // newPassInput.addEventListener('blur', function() { reqBoxModal.style.display = 'none'; }); // Opsional: Hide saat blur

        function checkPwd(val) {
            // Tampilkan box saat ngetik
            reqBoxModal.style.display = 'block';

            const isLen   = val.length >= 6;
            const isUpper = /[A-Z]/.test(val);
            const isNum   = /[0-9]/.test(val);
            const isSym   = /[^\w]/.test(val);

            updateReqUI("req-len", isLen);
            updateReqUI("req-upper", isUpper);
            updateReqUI("req-num", isNum);
            updateReqUI("req-sym", isSym);

            const isValid = isLen && isUpper && isNum && isSym;

            if (isValid) {
                btnSavePass.disabled = false;
                btnSavePass.style.opacity = "1";
                btnSavePass.style.cursor = "pointer";
            } else {
                btnSavePass.disabled = true;
                btnSavePass.style.opacity = "0.6";
                btnSavePass.style.cursor = "not-allowed";
            }
        }

        function updateReqUI(id, isValid) {
            const el = document.getElementById(id);
            const icon = el.querySelector("i");

            if (isValid) {
                el.classList.add("valid");
                el.classList.remove("invalid");
                // Icon Hijau
                icon.className = "bx bx-check"; 
                el.style.color = "#166534"; // Green text
            } else {
                el.classList.add("invalid");
                el.classList.remove("valid");
                // Icon Merah/Silang
                icon.className = "bx bx-x"; 
                el.style.color = "#b91c1c"; // Red text
            }
        }

        // --- STEP 4: SIMPAN PASSWORD ---
        function processSaveNewPass() {
            const newPass = document.getElementById('reset_new_pass').value;
            const btn = document.getElementById('btnSavePassReset');
            
            btn.innerText = "Saving..."; btn.disabled = true;
            
            const formData = new FormData();
            formData.append('ajax_action', 'save_reset_password');
            formData.append('uid', resetUserId);
            formData.append('otp', resetUserOTP); // Kirim OTP lagi untuk validasi server
            formData.append('new_password', newPass);
            
            fetch('./', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if(data.status === 'success') {
                    location.reload(); // Refresh untuk tampilkan popup sukses
                } else {
                    alert(data.message);
                    btn.innerText = "Save Password"; btn.disabled = false;
                }
            });
        }

        // --- HELPER MODAL ---
        function openModal(id) {
            const el = document.getElementById(id);
            const box = el.querySelector('.popup-box');
            el.style.display = 'flex';
            requestAnimationFrame(() => {
                el.style.opacity = '1';
                el.style.backdropFilter = 'blur(5px)';
                box.style.transform = 'scale(1) translateY(0)';
                box.style.opacity = '1';
            });
        }

        function closeModal(id) {
            const el = document.getElementById(id);
            const box = el.querySelector('.popup-box');
            el.style.opacity = '0';
            box.style.transform = 'scale(0.95) translateY(10px)';
            setTimeout(() => el.style.display = 'none', 300);
        }
    </script>
    <?php include 'popupcustom.php'; ?>
</body>
</html>