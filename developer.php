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
        // Generate Client ID & Secret
        $clientId = bin2hex(random_bytes(16)); // 32 chars
        $clientSecret = bin2hex(random_bytes(32)); // 64 chars

        // PERBAIKAN: Simpan user_id (pemilik) ke database
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
    
    // PERBAIKAN: Hanya hapus jika user_id cocok (Keamanan agar tidak menghapus punya orang lain)
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
        .btn-home {
            text-decoration: none;
            color: var(--text-main);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            transition: 0.2s;
        }
        .btn-home:hover { background: #f3f4f6; }
        .nav-profile {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        .nav-profile img { width: 100%; height: 100%; object-fit: cover; }
        .nav-placeholder {
            width: 100%; height: 100%;
            background: #000; color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
        }

        /* Container */
        .dev-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .dev-header-text {
            font-family: var(--font-serif);
            font-size: 2rem;
            margin-bottom: 30px;
            color: var(--text-main);
        }

        /* App Card Style (Mirip Passkey List) */
        .app-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .app-info h4 { margin: 0 0 5px 0; font-size: 1rem; }
        .app-info p { margin: 0; font-size: 0.85rem; color: var(--text-muted); }
        
        .client-secret-box {
            background: #f9fafb;
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.85rem;
            margin-top: 10px;
            border: 1px dashed #d1d5db;
            word-break: break-all;
            display: none; /* Hidden by default */
        }
        
        .toggle-secret {
            cursor: pointer;
            color: var(--primary);
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 5px;
            display: inline-block;
        }
    </style>
</head>
<body style="background:#f9fafb;">

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
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class='bx bx-code-alt' style="font-size:1.5rem; color:var(--text-main);"></i>
                    OAuth Applications
                </div>
                <i class='bx bx-chevron-right indicator'></i>
            </div>
            
            <div class="accordion-content" id="dev1-content">
                <div class="accordion-content-inside">
                    
                    <div style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center; justify-content:space-between;">
                        <div>
                            <h4>My Applications</h4>
                            <p style="font-size:0.75rem; color:var(--text-muted); font-weight:400; margin-top:2px;">Manage Client ID & Secret for your apps.</p>
                        </div>
                        <button onclick="openModal('modalCreateApp')" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#000; color:white;">
                            <i class='bx bx-plus'></i>
                        </button>
                    </div>

                    <div class="app-list">
                        <?php
                        // PERBAIKAN: Hanya ambil aplikasi milik user yang sedang login
                        $stmt = $conn->prepare("SELECT * FROM oauth_clients WHERE user_id = ?");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $q_apps = $stmt->get_result();
                        
                        if ($q_apps && $q_apps->num_rows > 0):
                            while($app = $q_apps->fetch_assoc()):
                        ?>
                            <div class="app-card">
                                <div class="app-info" style="flex: 1;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:32px; height:32px; background:#dbeafe; color:#2563eb; border-radius:6px; display:flex; align-items:center; justify-content:center;">
                                            <i class='bx bx-cube'></i>
                                        </div>
                                        <div>
                                            <h4><?php echo htmlspecialchars($app['app_name']); ?></h4>
                                            <p><?php echo htmlspecialchars($app['app_domain']); ?></p>
                                        </div>
                                    </div>

                                    <div style="margin-top:15px;">
                                        <div style="font-size:0.8rem; color:#6b7280; margin-bottom:2px;">Client ID</div>
                                        <div style="font-family:monospace; background:#f3f4f6; padding:5px 8px; border-radius:4px; display:inline-block;">
                                            <?php echo htmlspecialchars($app['client_id']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="toggle-secret" onclick="toggleSecret(this)">Show Client Secret</div>
                                    <div class="client-secret-box">
                                        <?php echo htmlspecialchars($app['client_secret']); ?>
                                    </div>
                                    
                                    <div style="margin-top:10px; font-size:0.8rem; color:#6b7280;">
                                        <strong>Redirect URI:</strong><br>
                                        <?php echo htmlspecialchars($app['redirect_uri']); ?>
                                    </div>
                                </div>

                                <form method="POST" onsubmit="return confirm('Hapus aplikasi ini? Aksi ini tidak dapat dibatalkan.');">
                                    <input type="hidden" name="delete_app_id" value="<?php echo $app['client_id']; ?>">
                                    <button type="submit" class="btn" style="background:transparent; color:#ef4444; padding:5px; width:auto; border:none; cursor:pointer;">
                                        <i class='bx bx-trash' style="font-size:1.2rem;"></i>
                                    </button>
                                </form>
                            </div>
                        <?php 
                            endwhile; 
                        else: 
                        ?>
                            <div style="text-align:center; padding:30px; color:var(--text-muted); border: 1px dashed #e5e7eb; border-radius:12px;">
                                Belum ada aplikasi yang dibuat.
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
        // Copy fungsi Accordion dari index.php
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
            if (secretBox.style.display === 'block') {
                secretBox.style.display = 'none';
                el.innerText = 'Show Client Secret';
            } else {
                secretBox.style.display = 'block';
                el.innerText = 'Hide Client Secret';
            }
        }
    </script>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>