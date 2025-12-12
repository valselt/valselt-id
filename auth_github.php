<?php
require 'config.php';

// Jika tidak ada code, tendang kembali
if (!isset($_GET['code'])) {
    header("Location: login.php");
    exit();
}

$code = $_GET['code'];

// 1. TUKAR CODE DENGAN ACCESS TOKEN
$token_url = "https://github.com/login/oauth/access_token";
$post_data = [
    'client_id'     => $github_client_id,
    'client_secret' => $github_client_secret,
    'code'          => $code,
    'redirect_uri'  => $github_redirect_uri
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$access_token = $data['access_token'] ?? null;

if (!$access_token) {
    die("Gagal mendapatkan token GitHub.");
}

// 2. AMBIL DATA USER DARI GITHUB API
$user_url = "https://api.github.com/user";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $user_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: token $access_token",
    "User-Agent: Valselt-App" 
]);
$user_response = curl_exec($ch);
curl_close($ch);

$github_user = json_decode($user_response, true);
$github_id = $github_user['id'];
$username  = $github_user['login']; 
$avatar    = $github_user['avatar_url'];

// Ambil Email (Untuk keperluan register baru / login via email match)
$email = $github_user['email'];
if (empty($email)) {
    $email_url = "https://api.github.com/user/emails";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $email_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token $access_token",
        "User-Agent: Valselt-App"
    ]);
    $emails_response = curl_exec($ch);
    curl_close($ch);
    
    $emails = json_decode($emails_response, true);
    if (is_array($emails)) {
        foreach ($emails as $e) {
            if ($e['primary'] == true && $e['verified'] == true) {
                $email = $e['email'];
                break;
            }
        }
    }
}

// ============================================================================
// LOGIKA UTAMA: MEMBEDAKAN ANTARA "LOGIN" DAN "LINKING AKUN"
// ============================================================================

// KASUS A: PENGGUNA SEDANG LOGIN (INGIN MENAUTKAN AKUN)
if (isset($_SESSION['valselt_user_id'])) {
    $current_user_id = $_SESSION['valselt_user_id'];

    // 1. Cek Keamanan: Apakah akun GitHub ini SUDAH dipakai oleh user LAIN?
    $check_used = $conn->query("SELECT id FROM users WHERE github_id='$github_id' AND id != '$current_user_id'");
    
    if ($check_used->num_rows > 0) {
        // Error: Akun GitHub ini milik orang lain
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Akun GitHub ini sudah terhubung ke pengguna lain!';
        header("Location: index.php");
        exit();
    }

    // 2. Lakukan Tautan (Update Database)
    // Kita TIDAK peduli emailnya beda. Kita percaya user yang sedang login ini pemiliknya.
    $conn->query("UPDATE users SET github_id='$github_id' WHERE id='$current_user_id'");

    // 3. Log dan Redirect
    logActivity($conn, $current_user_id, "Berhasil menautkan akun GitHub ($username)");
    
    $_SESSION['popup_status'] = 'success';
    $_SESSION['popup_message'] = 'Akun GitHub berhasil ditautkan!';
    header("Location: index.php");
    exit();
}

// ============================================================================

// KASUS B: PENGGUNA BELUM LOGIN (LOGIN / REGISTER)
else {
    // 1. Cek apakah GitHub ID sudah ada di database? (Login Langsung)
    $q = $conn->query("SELECT id, username FROM users WHERE github_id='$github_id'");
    
    if ($q->num_rows > 0) {
        // LOGIN BERHASIL
        $row = $q->fetch_assoc();
        $_SESSION['valselt_user_id'] = $row['id'];
        $_SESSION['valselt_username'] = $row['username'];
        
        logActivity($conn, $row['id'], "Login via GitHub");
        logUserDevice($conn, $row['id']);
        
        header("Location: index.php");
        exit();
    } 
    
    // 2. Jika GitHub ID belum ada, cek apakah Emailnya sama dengan user yang ada? (Auto-Link Legacy)
    else {
        $q_email = $conn->query("SELECT id FROM users WHERE email='$email'");
        
        if ($q_email->num_rows > 0) {
            // LINK BY EMAIL (Opsional: Bisa dihapus jika ingin strict)
            $row = $q_email->fetch_assoc();
            $uid = $row['id'];
            
            $conn->query("UPDATE users SET github_id='$github_id' WHERE id='$uid'");
            
            $_SESSION['valselt_user_id'] = $uid;
            $_SESSION['valselt_username'] = $username; // Opsional
            
            logActivity($conn, $uid, "Login GitHub (Linked by Email Match)");
            logUserDevice($conn, $uid);
            
            header("Location: index.php");
            exit();
        } 
        
        // 3. REGISTER PENGGUNA BARU
        else {
            $random_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
            
            // Handle username duplikat
            $check_user = $conn->query("SELECT id FROM users WHERE username='$username'");
            if ($check_user->num_rows > 0) {
                $username = $username . rand(100, 999);
            }

            $stmt = $conn->prepare("INSERT INTO users (username, email, password, github_id, profile_pic, is_verified) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->bind_param("sssss", $username, $email, $random_pass, $github_id, $avatar);
            
            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                $_SESSION['valselt_user_id'] = $new_id;
                $_SESSION['valselt_username'] = $username;
                
                logActivity($conn, $new_id, "Register via GitHub");
                logUserDevice($conn, $new_id);
                
                header("Location: index.php");
                exit();
            } else {
                die("Gagal mendaftar ke database. Error: " . $conn->error);
            }
        }
    }
}
?>