<?php
require 'config.php';

// Cek Login
if (!isset($_SESSION['valselt_user_id'])) {
    header("Location: login"); exit();
}

$user_id = $_SESSION['valselt_user_id'];
$u_res = $conn->query("SELECT * FROM users WHERE id='$user_id'");
$user_data = $u_res->fetch_assoc();

// --- AJAX HANDLER: CREATE NEW APP ---
if (isset($_POST['create_app'])) {
    $appName = htmlspecialchars(trim($_POST['app_name']));
    $appDomain = htmlspecialchars(trim($_POST['app_domain']));
    $redirectUri = htmlspecialchars(trim($_POST['redirect_uri']));

    if (empty($appName) || empty($appDomain) || empty($redirectUri)) {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Semua field wajib diisi!';
    } else {
        $clientId = bin2hex(random_bytes(16)); // 32 chars
        $clientSecret = bin2hex(random_bytes(32)); // 64 chars

        $stmt = $conn->prepare("INSERT INTO oauth_clients (user_id, client_id, client_secret, app_name, app_domain, redirect_uri) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $user_id, $clientId, $clientSecret, $appName, $appDomain, $redirectUri);

        if ($stmt->execute()) {
            $_SESSION['popup_status'] = 'success';
            $_SESSION['popup_message'] = 'Aplikasi berhasil dibuat!';
        } else {
            $_SESSION['popup_status'] = 'error';
            $_SESSION['popup_message'] = 'Gagal membuat aplikasi.';
        }
    }
    header("Location: developer.php");
    exit();
}

// --- AJAX HANDLER: DELETE APP ---
if (isset($_POST['delete_app_id'])) {
    $clientId = $_POST['delete_app_id'];
    
    $stmt = $conn->prepare("DELETE FROM oauth_clients WHERE client_id = ? AND user_id = ?");
    $stmt->bind_param("si", $clientId, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['popup_status'] = 'success';
        $_SESSION['popup_message'] = 'Aplikasi berhasil dihapus.';
    } else {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Gagal menghapus aplikasi.';
    }
    header("Location: developer.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Options - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Body Putih */
        body {
            background: #ffffff !important; 
        }

        /* Navbar Style */
        .dev-navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        /* Tombol Home Hitam */
        .btn-home {
            text-decoration: none;
            color: #ffffff; /* Teks Putih */
            background: #000000; /* BG Hitam */
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 30px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .btn-home:hover { 
            background: #333333; 
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.2);
        }

        /* --- PERUBAHAN DISINI: Animasi Ring Profile Ungu --- */
        .nav-profile {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1;
            margin-left: 15px;
            cursor: default;
        }

        /* Elemen Gradient Berputar di Belakang */
        .nav-profile::before {
            content: "";
            position: absolute;
            inset: -3px; /* Mengatur ketebalan ring */
            border-radius: 50%;
            
            /* UPDATE: Gradient Aksen Ungu */
            background: conic-gradient(
                #4c1d95, /* Purple gelap */
                #8b5cf6, /* Violet terang */
                #d946ef, /* Fuchsia accent */
                #8b5cf6, /* Violet terang */
                #4c1d95  /* Loop kembali ke Purple gelap agar mulus */
            );
            
            /* Animasi Putar Saja */
            animation: spin-ring 3s linear infinite; 
            z-index: -1;
        }

        /* Layer Putih Pemisah antara Ring dan Foto */
        .nav-profile::after {
            content: "";
            position: absolute;
            inset: 2px; /* Jarak antara ring warna dan foto */
            background: white;
            border-radius: 50%;
            z-index: -1;
        }

        @keyframes spin-ring {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        /* -------------------------------------------------- */

        .nav-profile img, .nav-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }

        .nav-placeholder {
            background: #000; color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Container */
        .dev-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .dev-header-text {
            font-family: var(--font-serif);
            font-size: 2.5rem;
            margin-bottom: 40px;
            color: var(--text-main);
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        /* App Card Style */
        .app-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            transition: border-color 0.2s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }
        .app-card:hover {
            border-color: #d1d5db;
        }
        .app-info h4 { margin: 0 0 5px 0; font-size: 1.1rem; font-weight: 700; }
        .app-info p { margin: 0; font-size: 0.9rem; color: var(--text-muted); }
        
        .client-secret-box {
            background: #f9fafb;
            padding: 12px;
            border-radius: 8px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9rem;
            margin-top: 10px;
            border: 1px solid #e5e7eb;
            word-break: break-all;
            display: none;
            color: #b91c1c;
        }
        
        .toggle-secret {
            cursor: pointer;
            color: var(--text-main);
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: underline;
        }
        .toggle-secret:hover { color: #000; }
    </style>
</head>
<body>

    <nav class="dev-navbar">
        <a href="index.php" class="btn-home">
            <i class='bx bx-arrow-back'></i> Home
        </a>
        <div class="nav-profile">
            <?php if($user_data['profile_pic']): ?>
                <img src="<?php echo $user_data['profile_pic']; ?>">
            <?php else: ?>
                <div class="nav-placeholder"><?php echo strtoupper(substr($user_data['username'], 0, 2)); ?></div>
            <?php endif; ?>
        </div>
    </nav>

    <div class="dev-container">
        <h1 class="dev-header-text">Developer Options</h1>

        <div id="accordionContainer" class="accordionContainer">
            <div class="accordion-header" id="dev1-header" onclick="toggleAccordion('dev1-header')">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="background: #eff6ff; padding: 8px; border-radius: 8px; color: #2563eb;">
                        <i class='bx bx-code-alt' style="font-size:1.4rem;"></i>
                    </div>
                    <span style="font-weight: 600; font-size: 1.1rem;">OAuth Applications</span>
                </div>
                <i class='bx bx-chevron-right indicator'></i>
            </div>
            
            <div class="accordion-content" id="dev1-content">
                <div class="accordion-content-inside">
                    
                    <div style="margin-bottom: 25px; font-weight:600; display:flex; align-items:center; justify-content:space-between;">
                        <div>
                            <h4 style="font-size: 1rem; margin-bottom: 4px;">My Applications</h4>
                            <p style="font-size:0.8rem; color:var(--text-muted); font-weight:400;">Manage Client ID & Secret to connect your apps.</p>
                        </div>
                        <button onclick="openModal('modalCreateApp')" class="btn" style="width:auto; padding: 10px 14px; font-size:0.9rem; background:#000; color:white; border-radius: 8px;">
                            <i class='bx bx-plus' style="font-size: 1.2rem;"></i>
                        </button>
                    </div>

                    <div class="app-list">
                        <?php
                        // Filter berdasarkan User ID
                        $stmt = $conn->prepare("SELECT * FROM oauth_clients WHERE user_id = ?");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $q_apps = $stmt->get_result();
                        
                        if ($q_apps && $q_apps->num_rows > 0):
                            while($app = $q_apps->fetch_assoc()):
                        ?>
                            <div class="app-card">
                                <div class="app-info" style="flex: 1;">
                                    <div style="display:flex; align-items:center; gap:15px; margin-bottom: 20px;">
                                        <div style="width:48px; height:48px; background:#000; color:#fff; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size: 1.5rem;">
                                            <i class='bx bx-cube-alt'></i>
                                        </div>
                                        <div>
                                            <h4><?php echo htmlspecialchars($app['app_name']); ?></h4>
                                            <p style="color: #2563eb;"><?php echo htmlspecialchars($app['app_domain']); ?></p>
                                        </div>
                                    </div>

                                    <div style="margin-top:15px;">
                                        <div style="font-size:0.75rem; text-transform:uppercase; color:#9ca3af; font-weight: 700; margin-bottom:5px;">Client ID</div>
                                        <div style="font-family:monospace; background:#f3f4f6; padding:8px 12px; border-radius:6px; display:inline-block; font-size: 0.9rem; color: #374151; border: 1px solid #e5e7eb;">
                                            <?php echo htmlspecialchars($app['client_id']); ?>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 15px;">
                                        <div style="font-size:0.75rem; text-transform:uppercase; color:#9ca3af; font-weight: 700; margin-bottom:5px;">Client Secret</div>
                                        <div class="toggle-secret" onclick="toggleSecret(this)">
                                            <i class='bx bx-hide'></i> Show Secret
                                        </div>
                                        <div class="client-secret-box">
                                            <?php echo htmlspecialchars($app['client_secret']); ?>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top:20px; border-top: 1px solid #f3f4f6; padding-top: 15px;">
                                        <div style="font-size:0.75rem; text-transform:uppercase; color:#9ca3af; font-weight: 700; margin-bottom:5px;">Callback URL</div>
                                        <div style="font-size:0.9rem; color:#4b5563; font-family: monospace;">
                                            <?php echo htmlspecialchars($app['redirect_uri']); ?>
                                        </div>
                                    </div>
                                </div>

                                <form method="POST" onsubmit="return confirm('Hapus aplikasi ini? Aksi ini tidak dapat dibatalkan.');" style="margin-left: 15px;">
                                    <input type="hidden" name="delete_app_id" value="<?php echo $app['client_id']; ?>">
                                    <button type="submit" class="btn" style="background:#fee2e2; color:#ef4444; padding:10px; width:40px; height: 40px; border:none; cursor:pointer; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: 0.2s;">
                                        <i class='bx bx-trash' style="font-size:1.2rem;"></i>
                                    </button>
                                </form>
                            </div>
                        <?php 
                            endwhile; 
                        else: 
                        ?>
                            <div style="text-align:center; padding:40px; color:var(--text-muted); border: 2px dashed #e5e7eb; border-radius:16px; background: #fafafa;">
                                <i class='bx bx-code-block' style="font-size: 3rem; margin-bottom: 10px; color: #d1d5db;"></i>
                                <p>You haven't created any applications yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <div class="popup-overlay" id="modalCreateApp" style="display:none; opacity:0; transition: opacity 0.3s;">
        <div class="popup-box" style="width: 500px; max-width: 95%;">
            <div class="popup-icon-box success"><i class='bx bx-layer-plus'></i></div>
            <h3 class="popup-title">Create New App</h3>
            <p class="popup-message" style="margin-bottom:20px;">Register your application to use Valselt SSO.</p>
            
            <form method="POST">
                <div class="form-group" style="text-align:left;">
                    <label style="font-size:0.85rem; font-weight:600; margin-bottom:5px; display:block;">App Name</label>
                    <input type="text" name="app_name" class="form-control" placeholder="e.g. My Awesome App" required>
                </div>

                <div class="form-group" style="text-align:left;">
                    <label style="font-size:0.85rem; font-weight:600; margin-bottom:5px; display:block;">App Domain</label>
                    <input type="text" name="app_domain" class="form-control" placeholder="example.com" required>
                </div>

                <div class="form-group" style="text-align:left;">
                    <label style="font-size:0.85rem; font-weight:600; margin-bottom:5px; display:block;">Redirect URI (Callback)</label>
                    <input type="url" name="redirect_uri" class="form-control" placeholder="https://example.com/callback" required>
                    <small style="color:var(--text-muted); font-size:0.75rem;">Must be HTTPS and exact match.</small>
                </div>

                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" onclick="closeModal('modalCreateApp')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
                    <button type="submit" name="create_app" class="popup-btn success">Create App</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleAccordion(headerId) {
            const header = document.getElementById(headerId);
            const contentId = headerId.replace('header', 'content');
            const content = document.getElementById(contentId);
            const isOpen = content.classList.contains('open');

            if (isOpen) {
                const fullHeight = content.scrollHeight;
                content.style.height = fullHeight + "px"; 
                requestAnimationFrame(() => {
                    content.style.height = "0px";
                    content.style.opacity = "0";
                    header.classList.remove("open");
                });
                content.addEventListener("transitionend", () => {
                    if (!content.classList.contains("open")) { content.style.height = ""; }
                }, { once: true });
                content.classList.remove("open");
            } else {
                content.classList.add("open");
                header.classList.add("open");
                const fullHeight = content.scrollHeight;
                content.style.height = "0px";
                content.style.opacity = "0";
                requestAnimationFrame(() => {
                    content.style.height = fullHeight + "px";
                    content.style.opacity = "1";
                });
                content.addEventListener("transitionend", () => {
                    content.style.height = "auto";
                }, { once: true });
            }
        }

        // Modal Logic
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

        function toggleSecret(el) {
            const secretBox = el.nextElementSibling;
            const icon = el.querySelector('i');
            
            if (secretBox.style.display === 'block') {
                secretBox.style.display = 'none';
                el.innerHTML = "<i class='bx bx-hide'></i> Show Secret";
            } else {
                secretBox.style.display = 'block';
                el.innerHTML = "<i class='bx bx-show'></i> Hide Secret";
            }
        }
    </script>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>