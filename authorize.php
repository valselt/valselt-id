<?php
require 'config.php';

// 1. Validasi Parameter Request
$client_id = isset($_GET['client_id']) ? $_GET['client_id'] : '';
$redirect_uri = isset($_GET['redirect_uri']) ? $_GET['redirect_uri'] : '';
$state = isset($_GET['state']) ? $_GET['state'] : ''; 

if (empty($client_id)) { die("Error: Missing client_id."); }

// 2. CEK MASTER APLIKASI
$stmt = $conn->prepare("SELECT * FROM oauth_clients WHERE client_id = ?");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();

if (!$app) { die("Error: Unknown Application (Invalid Client ID)."); }

// Validasi Redirect URI
if ($redirect_uri !== $app['redirect_uri']) {
    die("Error: Redirect URI mismatch.");
}

// 3. Cek Status Login User
if (!isset($_SESSION['valselt_user_id'])) {
    // Jika belum login, redirect ke login
    $current_url = "authorize.php?" . $_SERVER['QUERY_STRING'];
    header("Location: login?redirect_to=" . base64_encode($current_url));
    exit();
}

// AMBIL DATA USER
$user_id = $_SESSION['valselt_user_id'];
$u_res = $conn->query("SELECT username, email, profile_pic FROM users WHERE id='$user_id'");
$user_info = $u_res->fetch_assoc();


// 4. JIKA TOMBOL "IZINKAN" DITEKAN (POST)
if (isset($_POST['confirm_access'])) {
    
    // --- CATAT KE authorized_apps ---
    $check = $conn->prepare("SELECT id FROM authorized_apps WHERE user_id = ? AND client_id = ?");
    $check->bind_param("is", $user_id, $client_id);
    $check->execute();
    $exist = $check->get_result()->fetch_assoc();

    if ($exist) {
        $stmt = $conn->prepare("UPDATE authorized_apps SET last_accessed = NOW(), app_name=?, app_domain=? WHERE id=?");
        $stmt->bind_param("ssi", $app['app_name'], $app['app_domain'], $exist['id']);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO authorized_apps (user_id, client_id, app_name, app_domain, redirect_uri, last_accessed) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("issss", $user_id, $client_id, $app['app_name'], $app['app_domain'], $app['redirect_uri']);
        $stmt->execute();
    }

    // 5. Generate Authorization Code
    $auth_code = bin2hex(random_bytes(16));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 minute'));

    // Simpan Code
    $stmt = $conn->prepare("INSERT INTO oauth_codes (code, client_id, user_id, redirect_uri, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiss", $auth_code, $client_id, $user_id, $redirect_uri, $expiry);
    $stmt->execute();

    // 6. Redirect Balik ke Spencal
    $return_url = $redirect_uri . (strpos($redirect_uri, '?') === false ? '?' : '&') . 'code=' . $auth_code . '&state=' . $state;
    header("Location: " . $return_url);
    exit();
}

$auth_url = 'authorize.php?' . $_SERVER['QUERY_STRING'];
$login_target = 'login?redirect_to=' . base64_encode($auth_url);
$logout_target = base64_encode($login_target);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize - Valselt ID</title>
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

                <div class="auth-header">
                    <h2>Authorize App</h2>
                    <p><strong><?php echo htmlspecialchars($app['app_name']); ?></strong> ingin mengakses akun Anda.</p>
                </div>
                
                <form method="POST">
                    <div class="account-chooser-card" style="margin-bottom: 20px; cursor: default;">
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

                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 30px; text-align: center; line-height: 1.6;">
                        Aplikasi ini akan dapat melihat <strong>profil publik</strong> dan <strong>alamat email</strong> Anda.
                    </div>

                    <div style="display: flex; gap: 15px;">
                        <a href="<?php echo htmlspecialchars($redirect_uri . '?error=access_denied'); ?>" class="btn" style="flex: 1; background: white; border: 1px solid #e5e7eb; color: var(--text-main); text-decoration: none; padding: 12px 0; border-radius: 12px; font-weight: 600; display: flex; justify-content: center; align-items: center; transition: 0.2s;">
                            Batal
                        </a>

                        <button type="submit" name="confirm_access" class="btn-continue" style="flex: 1; margin: 0; width: auto; justify-content: center;">
                            Izinkan <i class='bx bx-check'></i>
                        </button>
                    </div>
                </form>

                <div class="auth-links" style="margin-top: 30px;">
                    <a href="logout?continue=<?php echo $logout_target; ?>">
                        Bukan Anda? <span style="text-decoration: underline; color: var(--primary);">Ganti Akun</span>
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script>
        // --- SCRIPT CAROUSEL LOGIC (SAMA DENGAN LOGIN.PHP) ---
        document.addEventListener("DOMContentLoaded", function() {
            const slides = document.querySelectorAll('.carousel-slide');
            const indicatorsContainer = document.getElementById('carousel-indicators');
            let currentIndex = 0;
            const intervalTime = 5000;

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
</body>
</html>