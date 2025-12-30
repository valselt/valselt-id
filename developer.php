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

    if (empty($appName) || empty($appDomain)) {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Semua field wajib diisi!';
    } else {
        $cleanName = preg_replace('/[^a-z0-9]+/i', '-', strtolower($appName));
        $cleanName = trim($cleanName, '-');
        
        $randomNum = rand(100000, 999999);
        $clientId = $cleanName . '-' . $randomNum . '-valselt-id';
        $clientSecret = bin2hex(random_bytes(32)); 

        $stmt = $conn->prepare("INSERT INTO oauth_clients (user_id, client_id, client_secret, app_name, app_domain) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $user_id, $clientId, $clientSecret, $appName, $appDomain);

        if ($stmt->execute()) {
            $_SESSION['popup_status'] = 'success';
            $_SESSION['popup_message'] = 'Aplikasi berhasil dibuat!';
        } else {
            if ($conn->errno == 1062) {
                 $_SESSION['popup_status'] = 'error';
                 $_SESSION['popup_message'] = 'Gagal membuat ID unik. Silakan coba lagi.';
            } else {
                 $_SESSION['popup_status'] = 'error';
                 $_SESSION['popup_message'] = 'Gagal membuat aplikasi.';
            }
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
        body { background: #ffffff !important; }

        /* Navbar Style */
        .dev-navbar { display: flex; justify-content: space-between; align-items: center; padding: 15px 30px; background: white; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; z-index: 100; }
        .btn-home { text-decoration: none; color: #ffffff; background: #000000; font-weight: 600; display: flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 30px; transition: all 0.3s ease; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .btn-home:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.2); }

        /* Profile */
        .nav-profile { width: 42px; height: 42px; border-radius: 50%; position: relative; display: flex; justify-content: center; align-items: center; z-index: 1; margin-left: 15px; cursor: default; }
        .nav-profile::before { content: ""; position: absolute; inset: -3px; border-radius: 50%; background: conic-gradient(#4c1d95, #8b5cf6, #d946ef, #8b5cf6, #4c1d95); animation: spin-ring 3s linear infinite; z-index: -1; }
        .nav-profile::after { content: ""; position: absolute; inset: 2px; background: white; border-radius: 50%; z-index: -1; }
        @keyframes spin-ring { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .nav-profile img, .nav-placeholder { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block; }
        .nav-placeholder { background: #000; color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; }

        /* Content */
        .dev-container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .dev-header-text { font-family: var(--font-serif); font-size: 2.5rem; margin-bottom: 40px; color: var(--text-main); font-weight: 700; letter-spacing: -0.5px; }

        /* Cards */
        .app-card { background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 25px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; transition: border-color 0.2s; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .app-card:hover { border-color: #d1d5db; }
        .app-info h4 { margin: 0 0 5px 0; font-size: 1.1rem; font-weight: 700; }
        .app-info p { margin: 0; font-size: 0.9rem; color: var(--text-muted); }

        /* UI Elements */
        .code-display-wrapper { display: inline-flex; align-items: center; justify-content: space-between; gap: 8px; background: #f3f4f6; padding: 8px 12px; border-radius: 6px; border: 1px solid #e5e7eb; width: 61.8%; min-width: 250px; box-sizing: border-box; }
        .code-text { font-family: monospace; font-size: 0.9rem; color: #374151; overflow: hidden; text-overflow: ellipsis; }
        
        .client-secret-wrapper { height: 0; opacity: 0; overflow: hidden; transition: height 0.35s cubic-bezier(0.4, 0.0, 0.2, 1), opacity 0.35s ease-in-out; width: 61.8%; min-width: 250px; }
        .client-secret-box { background: #f9fafb; padding: 12px; border-radius: 8px; font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; margin-top: 10px; border: 1px solid #e5e7eb; color: #b91c1c; filter: blur(8px); transform: scale(0.95); transform-origin: top left; transition: filter 0.35s ease, transform 0.35s ease; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .client-secret-box span { word-break: break-all; }
        .client-secret-wrapper.open { opacity: 1; }
        .client-secret-wrapper.open .client-secret-box { filter: blur(0); transform: scale(1); }

        .toggle-secret { cursor: pointer; color: #9ca3af; font-size: 1.2rem; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; line-height: 1; vertical-align: middle; width: 16px; height: 16px; border-radius: 6px; transition: all 0.2s ease; margin-left: 8px; }
        .toggle-secret:hover { color: #000; background: #e5e7eb; }
        .secret-label-container { display: flex; align-items: center; margin-bottom: 5px; }
        .section-label { font-size: 0.75rem; text-transform: uppercase; color: #9ca3af; font-weight: 700; }
        
        .btn-copy { background: transparent; border: none; cursor: pointer; color: #9ca3af; font-size: 1.2rem; padding: 4px; border-radius: 6px; transition: all 0.2s; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .btn-copy:hover { color: #000; background: #e5e7eb; }

        /* === UPDATE: TUTORIAL & CODE SNIPPET STYLE === */
        .tutorial-tabs { display: flex; gap: 10px; border-bottom: 1px solid #e5e7eb; padding-bottom: 15px; margin-bottom: 20px; }
        .tab-btn { background: transparent; border: none; font-weight: 600; color: var(--text-muted); cursor: pointer; padding: 8px 16px; border-radius: 30px; transition: 0.2s; }
        .tab-btn.active { background: #000; color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .tab-btn:hover:not(.active) { background: #f3f4f6; color: #000; }
        
        .code-snippet {
            background: #1e1e1e; 
            color: #d4d4d4; 
            padding: 20px; 
            border-radius: 8px;
            font-family: 'Consolas', 'Monaco', monospace; 
            font-size: 0.85rem; 
            overflow-x: auto;
            margin: 15px 0; 
            border: 1px solid #333;
            /* PENTING: Menjaga format baris baru dan spasi */
            white-space: pre; 
            line-height: 1.5;
            tab-size: 4;
        }
        
        /* Pewarnaan Kode Sederhana */
        .code-var { color: #9cdcfe; }
        .code-str { color: #ce9178; }
        .code-com { color: #6a9955; }
        .code-kwd { color: #569cd6; }
        .code-func { color: #dcdcaa; }

        .step-title { font-weight: 700; margin-bottom: 5px; color: var(--text-main); font-size: 0.95rem; margin-top: 25px; }
        .download-btn { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; background: #2563eb; color: white; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; margin-bottom: 20px; transition: 0.2s; }
        .download-btn:hover { background: #1d4ed8; transform: translateY(-2px); }
        
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 600px) { .code-display-wrapper, .client-secret-wrapper { width: 100%; } }
    </style>
</head>
<body>

    <nav class="dev-navbar">
        <a href="index.php" class="btn-home"><i class='bx bx-arrow-back'></i> Home</a>
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
                        <div style="display: flex; gap: 10px;">
                            <button onclick="openModal('modalTutorial')" class="btn" style="width:auto; padding: 10px 14px; font-size:0.9rem; background:#fff; color:#000; border: 1px solid #e5e7eb; border-radius: 8px;">
                                <i class='bx bx-book-open' style="font-size: 1.2rem; vertical-align: middle; margin-right: 4px;"></i> Tutorial
                            </button>
                            <button onclick="openModal('modalCreateApp')" class="btn" style="width:auto; padding: 10px 14px; font-size:0.9rem; background:#000; color:white; border-radius: 8px;">
                                <i class='bx bx-plus' style="font-size: 1.2rem; vertical-align: middle;"></i> New App
                            </button>
                        </div>
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
                                            <img src="<?php echo $directFavicon; ?>" alt="Icon" style="width:28px; height:28px; object-fit:contain;" onerror="this.onerror=null; this.src='<?php echo $backupFavicon; ?>';">
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
                                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($app['client_id']); ?>', this)" class="btn-copy" title="Copy Client ID"><i class='bx bx-copy'></i></button>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 15px;">
                                        <div class="secret-label-container">
                                            <div class="section-label">Client Secret</div>
                                            <div class="toggle-secret" onclick="toggleSecret(this, '<?php echo $app['client_id']; ?>_secret_wrap')"><i class='bx bx-hide'></i></div>
                                        </div>
                                        <div class="client-secret-wrapper" id="<?php echo $app['client_id']; ?>_secret_wrap">
                                            <div class="client-secret-box">
                                                <span><?php echo htmlspecialchars($app['client_secret']); ?></span>
                                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($app['client_secret']); ?>', this)" class="btn-copy" title="Copy Client Secret"><i class='bx bx-copy'></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button onclick="openDeleteAppModal('<?php echo $app['client_id']; ?>')" class="btn" style="background:#fee2e2; color:#ef4444; padding:10px; width:40px; height: 40px; border:none; cursor:pointer; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: 0.2s; margin-left: 15px;">
                                    <i class='bx bx-trash' style="font-size:1.2rem;"></i>
                                </button>
                            </div>
                        <?php endwhile; else: ?>
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
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" onclick="closeModal('modalCreateApp')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
                    <button type="submit" name="create_app" class="popup-btn success">Create App</button>
                </div>
            </form>
        </div>
    </div>

    <div class="popup-overlay" id="modalDeleteApp" style="display:none; opacity:0; transition: opacity 0.3s;">
        <div class="popup-box">
            <div class="popup-icon-box error"><i class='bx bx-trash'></i></div>
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

    <div class="popup-overlay" id="modalTutorial" style="display:none; opacity:0; transition: opacity 0.3s;">
        <div class="popup-box" style="width: 700px; max-width: 95%; max-height: 85vh; overflow-y: auto;">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 class="popup-title" style="margin:0; text-align:left;">Integration Tutorial</h3>
                <button onclick="closeModal('modalTutorial')" style="background:none; border:none; cursor:pointer; font-size:1.5rem;"><i class='bx bx-x'></i></button>
            </div>

            <div class="tutorial-tabs">
                <button class="tab-btn active" onclick="switchTab('php')"><i class='bx bxl-php'></i> PHP</button>
                <button class="tab-btn" onclick="switchTab('node')"><i class='bx bxl-nodejs'></i> Node.js</button>
                <button class="tab-btn" onclick="switchTab('python')"><i class='bx bxl-python'></i> Python</button>
            </div>

            <div id="tab-php" class="tab-content active" style="text-align:left;">
                <p class="popup-message" style="text-align:left; margin-bottom:15px;">Integrate easily with our PHP Single-File SDK.</p>
                
                <div class="step-title">1. Download SDK</div>
                <a href="https://cdn.ivanaldorino.web.id/valselt/Valselt.php" class="download-btn" download>
                    <i class='bx bx-download'></i> Download Valselt.php
                </a>

                <div class="step-title">2. Implementation (login.php)</div>
                <div class="code-snippet">&lt;?php
require <span class="code-str">'Valselt.php'</span>;

<span class="code-com">// Configuration</span>
$client_id     = <span class="code-str">"YOUR_CLIENT_ID"</span>;
$client_secret = <span class="code-str">"YOUR_CLIENT_SECRET"</span>;
$redirect_uri  = <span class="code-str">"http://yourdomain.com/login.php"</span>;

<span class="code-com">// Initialize & Login</span>
$sso = <span class="code-kwd">new</span> Valselt($client_id, $client_secret, $redirect_uri);
$user = $sso->getUser(); 

<span class="code-com">// --- Login Success ---</span>
session_start();
$_SESSION[<span class="code-str">'user'</span>] = $user;

header(<span class="code-str">"Location: dashboard.php"</span>);
exit();
?&gt;</div>
            </div>

            <div id="tab-node" class="tab-content" style="text-align:left;">
                <p class="popup-message" style="text-align:left; margin-bottom:15px;">Use our JavaScript Module for Express/Node apps.</p>
                
                <div class="step-title">1. Download Module</div>
                <a href="https://cdn.ivanaldorino.web.id/valselt/Valselt.js" class="download-btn" download>
                    <i class='bx bx-download'></i> Download Valselt.js
                </a>
                <p style="font-size:0.85rem; color:#666; margin-top:5px;">Required: <code>npm install axios express-session</code></p>

                <div class="step-title">2. Implementation</div>
<div class="code-snippet"><span class="code-kwd">const</span> Valselt = require(<span class="code-str">'./Valselt'</span>);

<span class="code-com">// Initialize</span>
<span class="code-kwd">const</span> sso = <span class="code-kwd">new</span> Valselt(
    <span class="code-str">'YOUR_CLIENT_ID'</span>, 
    <span class="code-str">'YOUR_CLIENT_SECRET'</span>, 
    <span class="code-str">'http://localhost:3000/login'</span>
);

app.get(<span class="code-str">'/login'</span>, <span class="code-kwd">async</span> (req, res) => {
    <span class="code-kwd">try</span> {
        <span class="code-kwd">const</span> user = <span class="code-kwd">await</span> sso.getUser(req, res);
        
        <span class="code-kwd">if</span> (user) {
            req.session.user = user;
            res.redirect(<span class="code-str">'/dashboard'</span>);
        }
    } <span class="code-kwd">catch</span> (err) {
        res.send(<span class="code-str">"Error: "</span> + err.message);
    }
});</div>
            </div>

            <div id="tab-python" class="tab-content" style="text-align:left;">
                <p class="popup-message" style="text-align:left; margin-bottom:15px;">Simple integration class for Python Flask/Django.</p>
                
                <div class="step-title">1. Download Class</div>
                <a href="https://cdn.ivanaldorino.web.id/valselt/valselt.py" class="download-btn" download>
                    <i class='bx bx-download'></i> Download valselt.py
                </a>
                <p style="font-size:0.85rem; color:#666; margin-top:5px;">Required: <code>pip install requests flask</code></p>

                <div class="step-title">2. Implementation</div>
<div class="code-snippet"><span class="code-kwd">from</span> valselt <span class="code-kwd">import</span> Valselt
<span class="code-kwd">from</span> flask <span class="code-kwd">import</span> session, redirect

sso = Valselt(
    <span class="code-str">'YOUR_CLIENT_ID'</span>, 
    <span class="code-str">'YOUR_CLIENT_SECRET'</span>, 
    <span class="code-str">'http://localhost:5000/login'</span>
)

@app.route(<span class="code-str">'/login'</span>)
<span class="code-kwd">def</span> <span class="code-func">login</span>():
    result = sso.get_user()
    
    <span class="code-kwd">if</span> isinstance(result, dict):
        session[<span class="code-str">'user'</span>] = result
        <span class="code-kwd">return</span> redirect(<span class="code-str">'/dashboard'</span>)
        
    <span class="code-kwd">return</span> result</div>
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
                const fullHeight = content.scrollHeight; content.style.height = fullHeight + "px"; 
                requestAnimationFrame(() => { content.style.height = "0px"; content.style.opacity = "0"; header.classList.remove("open"); });
                content.addEventListener("transitionend", () => { if (!content.classList.contains("open")) { content.style.height = ""; } }, { once: true });
                content.classList.remove("open");
            } else {
                content.classList.add("open"); header.classList.add("open");
                const fullHeight = content.scrollHeight; content.style.height = "0px"; content.style.opacity = "0";
                requestAnimationFrame(() => { content.style.height = fullHeight + "px"; content.style.opacity = "1"; });
                content.addEventListener("transitionend", () => { content.style.height = "auto"; }, { once: true });
            }
        }
        function openModal(id) {
            const el = document.getElementById(id); const box = el.querySelector('.popup-box');
            el.style.display = 'flex';
            requestAnimationFrame(() => { el.style.opacity = '1'; el.style.backdropFilter = 'blur(5px)'; box.style.transform = 'scale(1) translateY(0)'; box.style.opacity = '1'; });
        }
        function closeModal(id) {
            const el = document.getElementById(id); const box = el.querySelector('.popup-box');
            el.style.opacity = '0'; box.style.transform = 'scale(0.95) translateY(10px)'; setTimeout(() => el.style.display = 'none', 300);
        }
        function openDeleteAppModal(clientId) { document.getElementById('delete_target_id').value = clientId; openModal('modalDeleteApp'); }
        function toggleSecret(btn, wrapperId) {
            const wrapper = document.getElementById(wrapperId); const isOpen = wrapper.classList.contains('open');
            if (isOpen) { wrapper.style.height = wrapper.scrollHeight + "px"; wrapper.offsetHeight; wrapper.classList.remove('open'); requestAnimationFrame(() => { wrapper.style.height = "0px"; wrapper.style.opacity = "0"; }); btn.innerHTML = "<i class='bx bx-hide'></i>"; } 
            else { wrapper.classList.add("open"); const targetHeight = wrapper.scrollHeight; wrapper.style.height = targetHeight + "px"; wrapper.style.opacity = "1"; btn.innerHTML = "<i class='bx bx-show'></i>"; }
        }
        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => { const originalHTML = btn.innerHTML; btn.innerHTML = "<i class='bx bx-check'></i>"; btn.style.color = "#166534"; btn.style.background = "#dcfce7"; setTimeout(() => { btn.innerHTML = originalHTML; btn.style.color = ""; btn.style.background = ""; }, 2000); }).catch(err => { console.error('Gagal menyalin: ', err); alert("Gagal menyalin teks."); });
        }
        function switchTab(lang) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('tab-' + lang).classList.add('active');
            event.target.closest('.tab-btn').classList.add('active');
        }
    </script>
    
    <?php include 'popupcustom.php'; ?>
</body>
</html>