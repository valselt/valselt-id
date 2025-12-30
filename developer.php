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
        $clientId = bin2hex(random_bytes(20)); // 40 chars
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
            color: #ffffff; 
            background: #000000;
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

        /* Animasi Ring Profile Ungu */
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

        .nav-profile::before {
            content: "";
            position: absolute;
            inset: -3px; 
            border-radius: 50%;
            background: conic-gradient(
                #4c1d95, #8b5cf6, #d946ef, #8b5cf6, #4c1d95 
            );
            animation: spin-ring 3s linear infinite; 
            z-index: -1;
        }

        .nav-profile::after {
            content: "";
            position: absolute;
            inset: 2px;
            background: white;
            border-radius: 50%;
            z-index: -1;
        }

        @keyframes spin-ring {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }

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
        
        /* Golden Ratio Layouts */
        .code-display-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            background: #f3f4f6;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            width: 61.8%; 
            min-width: 250px;
            box-sizing: border-box;
        }
        .code-text {
            font-family: monospace;
            font-size: 0.9rem;
            color: #374151;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Secret Box Transition */
        .client-secret-wrapper {
            height: 0;           
            opacity: 0;          
            overflow: hidden;    
            transition: height 0.35s cubic-bezier(0.4, 0.0, 0.2, 1), opacity 0.35s ease-in-out;
            width: 61.8%;
            min-width: 250px;
        }
        
        .client-secret-box {
            background: #f9fafb;
            padding: 12px;
            border-radius: 8px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9rem;
            border: 1px solid #e5e7eb;
            color: #b91c1c;
            filter: blur(8px);
            transform: scale(0.95);
            transform-origin: top left;
            transition: filter 0.35s ease, transform 0.35s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        
        .client-secret-box span { word-break: break-all; }

        .client-secret-wrapper.open { opacity: 1; }
        .client-secret-wrapper.open .client-secret-box { filter: blur(0); transform: scale(1); }
        
        /* === UPDATE: Icon-Only Toggle Secret === */
        .toggle-secret {
            cursor: pointer;
            color: #9ca3af; /* Warna abu-abu default (seperti tombol copy) */
            font-size: 1.2rem;
            
            /* Layout & Centering */
            display: inline-flex;
            align-items: center;
            justify-content: center;
            
            /* Style */
            text-decoration: none;
            line-height: 1;
            vertical-align: middle;
            
            /* Ukuran Kotak Kecil */
            width: 16px;
            height: 16px;
            border-radius: 6px;
            transition: all 0.2s ease;
            
            /* Posisi relatif terhadap judul */
            margin-left: 8px;
        }
        
        .toggle-secret:hover { 
            color: #000; 
            
        }
        
        /* Judul Section (Flexbox untuk sejajarkan teks & tombol) */
        .secret-label-container {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .section-label {
            font-size: 0.75rem; 
            text-transform: uppercase; 
            color: #9ca3af; 
            font-weight: 700;
        }
        /* ======================================== */

        /* Copy Button */
        .btn-copy {
            background: transparent;
            border: none;
            cursor: pointer;
            color: #9ca3af;
            font-size: 1.2rem;
            padding: 4px;
            border-radius: 6px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .btn-copy:hover { color: #000; background: #e5e7eb; }
        
        @media (max-width: 600px) {
            .code-display-wrapper, .client-secret-wrapper { width: 100%; }
        }
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
                        $stmt = $conn->prepare("SELECT * FROM oauth_clients WHERE user_id = ?");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $q_apps = $stmt->get_result();
                        
                        if ($q_apps && $q_apps->num_rows > 0):
                            while($app = $q_apps->fetch_assoc()):
                                $appDomain = htmlspecialchars($app['app_domain']);
                                $directFavicon = "https://" . $appDomain . "/favicon.ico?v=" . time();
                                $backupFavicon = "https://www.google.com/s2/favicons?domain=" . $appDomain . "&sz=64";
                        ?>
                            <div class="app-card">
                                <div class="app-info" style="flex: 1;">
                                    <div style="display:flex; align-items:center; gap:15px; margin-bottom: 20px;">
                                        <div style="width:48px; height:48px; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                            <img src="<?php echo $directFavicon; ?>" 
                                                 alt="Icon" 
                                                 style="width:28px; height:28px; object-fit:contain;"
                                                 onerror="this.onerror=null; this.src='<?php echo $backupFavicon; ?>';">
                                        </div>

                                        <div>
                                            <h4><?php echo htmlspecialchars($app['app_name']); ?></h4>
                                            <p style="color: #2563eb;"><?php echo $appDomain; ?></p>
                                        </div>
                                    </div>

                                    <div style="margin-top:15px;">
                                        <div class="section-label" style="margin-bottom:5px;">Client ID</div>
                                        <div class="code-display-wrapper">
                                            <span class="code-text"><?php echo htmlspecialchars($app['client_id']); ?></span>
                                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($app['client_id']); ?>', this)" class="btn-copy" title="Copy Client ID">
                                                <i class='bx bx-copy'></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 15px;">
                                        <div class="secret-label-container">
                                            <div class="section-label">Client Secret</div>
                                            <div class="toggle-secret" onclick="toggleSecret(this, '<?php echo $app['client_id']; ?>_secret_wrap')">
                                                <i class='bx bx-hide'></i>
                                            </div>
                                        </div>
                                        
                                        <div class="client-secret-wrapper" id="<?php echo $app['client_id']; ?>_secret_wrap">
                                            <div class="client-secret-box">
                                                <span><?php echo htmlspecialchars($app['client_secret']); ?></span>
                                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($app['client_secret']); ?>', this)" class="btn-copy" title="Copy Client Secret">
                                                    <i class='bx bx-copy'></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top:20px; border-top: 1px solid #f3f4f6; padding-top: 15px;">
                                        <div class="section-label" style="margin-bottom:5px;">Callback URL</div>
                                        <div style="font-size:0.9rem; color:#4b5563; font-family: monospace;">
                                            <?php echo htmlspecialchars($app['redirect_uri']); ?>
                                        </div>
                                    </div>
                                </div>

                                <button onclick="openDeleteAppModal('<?php echo $app['client_id']; ?>')" class="btn" style="background:#fee2e2; color:#ef4444; padding:10px; width:40px; height: 40px; border:none; cursor:pointer; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: 0.2s; margin-left: 15px;">
                                    <i class='bx bx-trash' style="font-size:1.2rem;"></i>
                                </button>
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

    <div class="popup-overlay" id="modalDeleteApp" style="display:none; opacity:0; transition: opacity 0.3s;">
        <div class="popup-box">
            <div class="popup-icon-box error">
                <i class='bx bx-trash'></i>
            </div>
            
            <h3 class="popup-title">Delete Application?</h3>
            <p class="popup-message">Are you sure you want to delete this app? Any active connections will be revoked.</p>
            
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="button" onclick="closeModal('modalDeleteApp')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
                
                <form method="POST" style="width:100%; margin:0;">
                    <input type="hidden" name="delete_app_id" id="delete_target_id">
                    <button type="submit" class="popup-btn error">Yes, Delete</button>
                </form>
            </div>
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

        function openDeleteAppModal(clientId) {
            document.getElementById('delete_target_id').value = clientId;
            openModal('modalDeleteApp');
        }

        // UPDATE: Toggle Secret (Icon Only, Target by ID)
        function toggleSecret(btn, wrapperId) {
            const wrapper = document.getElementById(wrapperId);
            const isOpen = wrapper.classList.contains('open');
            
            if (isOpen) {
                // CLOSE
                wrapper.style.height = wrapper.scrollHeight + "px";
                wrapper.offsetHeight; 
                wrapper.classList.remove('open'); 

                requestAnimationFrame(() => {
                    wrapper.style.height = "0px";
                    wrapper.style.opacity = "0";
                });
                
                // Ubah ikon jadi mata tertutup (Hide)
                btn.innerHTML = "<i class='bx bx-hide'></i>";

            } else {
                // OPEN
                wrapper.classList.add("open");
                const targetHeight = wrapper.scrollHeight;
                wrapper.style.height = targetHeight + "px";
                wrapper.style.opacity = "1";
                
                // Ubah ikon jadi mata terbuka (Show)
                btn.innerHTML = "<i class='bx bx-show'></i>";
            }
        }

        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = "<i class='bx bx-check'></i>";
                btn.style.color = "#166534"; 
                btn.style.background = "#dcfce7"; 

                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.color = "";
                    btn.style.background = "";
                }, 2000);
            }).catch(err => {
                console.error('Gagal menyalin: ', err);
                alert("Gagal menyalin teks.");
            });
        }
    </script>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>