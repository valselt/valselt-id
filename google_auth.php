<?php
require 'config.php';

if (isset($_GET['code'])) {
    // 1. Tukar Code dengan Token
    $token = $google_client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (!isset($token['error'])) {
        $google_client->setAccessToken($token['access_token']);
        
        // 2. Ambil Data User dari Google
        $google_service = new Google\Service\Oauth2($google_client);
        $data = $google_service->userinfo->get();

        $g_id = $data['id'];
        $g_email = $data['email'];
        $g_name = $data['name']; // Nama lengkap
        $g_picture = $data['picture']; // URL Foto Profil Google

        // Cek redirect_to (untuk SSO)
        $redirect_to = isset($_SESSION['sso_redirect_to']) ? $_SESSION['sso_redirect_to'] : '';

        // --- SKENARIO 1: USER SUDAH LOGIN (LINKING ACCOUNT) ---
        if (isset($_SESSION['valselt_user_id'])) {
            $uid = $_SESSION['valselt_user_id'];
            // Update DB untuk link google_id
            $stmt = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
            $stmt->bind_param("si", $g_id, $uid);
            
            if ($stmt->execute()) {
                logActivity($conn, $uid, "Akun Google Berhasil Ditautkan di Perangkat " . getDeviceName());
                $_SESSION['popup_status'] = 'success';
                $_SESSION['popup_message'] = 'Akun Google berhasil ditautkan!';
            } else {
                $_SESSION['popup_status'] = 'error';
                $_SESSION['popup_message'] = 'Gagal menautkan akun (Mungkin email Google ini sudah dipakai akun lain).';
            }
            header("Location: ./");
            exit();
        }

        // --- SKENARIO 2: LOGIN / REGISTER ---
        
        // Cek apakah Google ID sudah ada?
        $stmt = $conn->prepare("SELECT * FROM users WHERE google_id = ?");
        $stmt->bind_param("s", $g_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            // A. LOGIN: User ditemukan via Google ID
            $user = $res->fetch_assoc();
            loginUser($user, $redirect_to, $conn);
        } else {
            // Cek apakah Email sudah ada? (Linking otomatis via Email)
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->bind_param("s", $g_email);
            $stmt->execute();
            $res_email = $stmt->get_result();

            if ($res_email->num_rows > 0) {
                // B. LINKING: Email ada, tapi Google ID belum -> Update Google ID
                $user = $res_email->fetch_assoc();
                
                // Update Google ID ke user tersebut
                $conn->query("UPDATE users SET google_id = '$g_id' WHERE id = '{$user['id']}'");
                
                loginUser($user, $redirect_to, $conn);
            } else {
                // C. REGISTER BARU: User benar-benar baru
                // Buat username dari email (sebelum @) + random angka
                $parts = explode('@', $g_email);
                $base_username = $parts[0];
                $new_username = $base_username . rand(100, 999);
                
                // Password Random (User tidak tahu, login via Google seterusnya)
                $random_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                
                // INSERT (is_verified = 1 karena Google Email sudah pasti verified)
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, google_id, profile_pic, is_verified, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
                $stmt->bind_param("sssss", $new_username, $g_email, $random_pass, $g_id, $g_picture);
                
                if ($stmt->execute()) {
                    $new_uid = $conn->insert_id;
                    // Login user baru
                    $_SESSION['valselt_user_id'] = $new_uid;
                    $_SESSION['valselt_username'] = $new_username;

                    // Opsi jika ingin dipisah (Menghasilkan 2 baris log di database)
                    logActivity($conn, $new_uid, "Pendaftaran Akun Baru via Google Berhasil");
                    logActivity($conn, $new_uid, "Login Berhasil menggunakan Google oleh perangkat " . getDeviceName());
                    logUserDevice($conn, $new_uid);
                    handleRememberMe($conn, $new_uid);
                    processSSORedirect($conn, $new_uid, $redirect_to);
                } else {
                    $_SESSION['popup_status'] = 'error';
                    $_SESSION['popup_message'] = 'Gagal Register via Google.';
                    header("Location: login");
                }
            }
        }
    } else {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Gagal Login Google.';
        header("Location: login");
    }
}

// Helper Function Login (DIMODIFIKASI UNTUK 2FA)
function loginUser($user, $redirect_to, $conn) {
    $uid = $user['id'];
    
    // 1. Cek Status 2FA User
    // Kita query ulang untuk memastikan dapat status is_2fa_enabled terbaru
    $q_cek = $conn->query("SELECT is_2fa_enabled FROM users WHERE id='$uid'");
    $u_cek = $q_cek->fetch_assoc();
    
    // 2. Logika Percabangan 2FA
    if ($u_cek['is_2fa_enabled'] == 1) {
        
        // Cek apakah Device ini TRUSTED?
        if (checkTrustedDevice($conn, $uid)) {
            // TRUSTED -> Login Langsung
            executeLogin($user, $redirect_to, $conn, 'google');
        } else {
            // TIDAK TRUSTED -> Redirect ke verify2fa
            $_SESSION['pre_2fa_user_id'] = $uid;
            $_SESSION['login_method'] = 'google'; // Set durasi trust 6 bulan
            
            // Simpan redirect target jika ada
            if(!empty($redirect_to)) { 
                $_SESSION['sso_redirect_to'] = $redirect_to; 
            }
            
            // Catat device (untuk nanti diupdate token-nya)
            logUserDevice($conn, $uid);
            logActivity($conn, $uid, "Login Google: Meminta Verifikasi 2FA");
            
            header("Location: verify2fa");
            exit();
        }
    } else {
        // TIDAK ADA 2FA -> Login Langsung
        executeLogin($user, $redirect_to, $conn, 'google');
    }
}

// Fungsi Eksekusi Login Final (Login Sukses)
// Fungsi Eksekusi Login Final (Login Sukses via Google Popup)
function executeLogin($user, $redirect_to, $conn, $method) {
    // 1. Set Session Utama
    $_SESSION['valselt_user_id'] = $user['id'];
    $_SESSION['valselt_username'] = $user['username'];
    
    // 2. Catat Log & Device
    handleRememberMe($conn, $user['id']);
    logActivity($conn, $user['id'], "Login Berhasil menggunakan $method di perangkat " . getDeviceName());
    logUserDevice($conn, $user['id']);
    
    // 3. Simpan Redirect URL ke Session (PENTING untuk SSO)
    // Agar saat window induk reload, dia tahu harus lanjut kemana
    if (!empty($redirect_to)) {
        $_SESSION['sso_redirect_to'] = $redirect_to;
    }

    // 4. Output Script Penutup Popup
    // Script ini akan me-reload halaman 'login.php' yang ada di belakang popup.
    // Karena session sudah terbentuk di langkah 1, saat login.php reload, 
    // dia akan otomatis masuk ke dashboard atau halaman "Lanjutkan SSO".
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login Success</title>
        <style>
            body { 
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background-color: #f9fafb; 
                height: 100vh; 
                margin: 0; 
                display: flex; 
                flex-direction: column; 
                align-items: center; 
                justify-content: center; 
                color: #374151;
            }
            .loader {
                border: 3px solid #e5e7eb;
                border-top: 3px solid #10b981;
                border-radius: 50%;
                width: 24px;
                height: 24px;
                animation: spin 1s linear infinite;
                margin-bottom: 15px;
            }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>
    </head>
    <body>
        <div class="loader"></div>
        <p style="font-size: 0.9rem; font-weight: 500;">Signing you in...</p>
        
        <script>
            // Cek apakah halaman ini dibuka di dalam popup (memiliki opener)
            if (window.opener) {
                // 1. Reload halaman induk (login.php)
                // Ini akan memicu cek session di login.php -> User masuk
                window.opener.location.reload();
                
                // 2. Tutup popup ini
                window.close();
            } else {
                // Fallback: Jika user membuka link ini di tab baru (bukan popup)
                // Redirect langsung ke dashboard
                window.location.href = "./"; 
            }
        </script>
    </body>
    </html>
    ';
    exit();
}

?>