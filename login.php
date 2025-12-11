<?php
require 'config.php'; 

// 1. TANGKAP TUJUAN SSO
$redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

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
        header("Location: index.php");
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
                $_SESSION['popup_redirect'] = 'verify.php';
            } else {
                $_SESSION['valselt_user_id'] = $row['id'];
                $_SESSION['valselt_username'] = $row['username'];
                processSSORedirect($conn, $row['id'], $redirect_to);
            }
        } else {
            $_SESSION['popup_status'] = 'error'; $_SESSION['popup_message'] = 'Password salah!';
        }
    } else {
        $_SESSION['popup_status'] = 'error'; $_SESSION['popup_message'] = 'Akun tidak ditemukan!';
    }
}

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
                        <a href="logout.php?continue=<?php echo urlencode('login.php?redirect_to='.$redirect_to); ?>">
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
                            <label><input type="checkbox"> Remember me</label>
                            <a href="#" style="color:var(--text-main); font-weight:600;">Forgot Password?</a>
                        </div>

                        <button type="submit" name="login" class="btn btn-primary">Sign In</button>
                    </form>

                    <div class="auth-links">
                        Don't have an account? <a href="register.php">Sign Up</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
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
    </script>
    <?php include 'popupcustom.php'; ?>
</body>
</html>