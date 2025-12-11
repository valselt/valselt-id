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
                $_SESSION['popup_status'] = 'success';
                $_SESSION['popup_message'] = 'Akun Google berhasil ditautkan!';
            } else {
                $_SESSION['popup_status'] = 'error';
                $_SESSION['popup_message'] = 'Gagal menautkan akun (Mungkin email Google ini sudah dipakai akun lain).';
            }
            header("Location: index.php");
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
                    
                    processSSORedirect($conn, $new_uid, $redirect_to);
                } else {
                    $_SESSION['popup_status'] = 'error';
                    $_SESSION['popup_message'] = 'Gagal Register via Google.';
                    header("Location: login.php");
                }
            }
        }
    } else {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Gagal Login Google.';
        header("Location: login.php");
    }
}

// Helper Function Login
function loginUser($user, $redirect_to, $conn) {
    $_SESSION['valselt_user_id'] = $user['id'];
    $_SESSION['valselt_username'] = $user['username'];
    processSSORedirect($conn, $user['id'], $redirect_to);
}

// Helper Function SSO (Sama dengan di login.php)
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