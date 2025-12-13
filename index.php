<?php
require 'config.php';

if (!isset($_SESSION['valselt_user_id'])) {
    header("Location: login"); exit();
}

$user_id = $_SESSION['valselt_user_id'];
$u_res = $conn->query("SELECT * FROM users WHERE id='$user_id'");
$user_data = $u_res->fetch_assoc();

// --- AJAX HANDLER ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $uid = $_SESSION['valselt_user_id'];
    $response = ['status' => 'error', 'message' => 'Terjadi kesalahan'];

    // 1. GENERATE SECRET & QR (Langkah Awal)
    if ($_POST['ajax_action'] == 'generate_2fa') {
        try {
            $google2fa = new \PragmaRX\Google2FA\Google2FA();
            $secret = $google2fa->generateSecretKey();
            $_SESSION['temp_2fa_secret'] = $secret; // Simpan sementara
            
            $qrCodeUrl = $google2fa->getQRCodeUrl('Valselt ID', $user_data['email'], $secret);
            echo json_encode(['status' => 'success', 'qr_url' => $qrCodeUrl, 'secret' => $secret]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit();
    }

    // 2. VERIFIKASI KODE PERTAMA (Tanpa Simpan DB)
    elseif ($_POST['ajax_action'] == 'verify_2fa_temp') {
        $code = $_POST['otp_code'];
        $secret = $_SESSION['temp_2fa_secret'] ?? null;

        if (!$secret) { echo json_encode(['status' => 'error', 'message' => 'Sesi habis.']); exit(); }

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        if ($google2fa->verifyKey($secret, $code)) {
            // Jika benar, Generate Backup Code 32 Karakter
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $backupCode = substr(str_shuffle(str_repeat($chars, 5)), 0, 32);
            $_SESSION['temp_2fa_backup'] = $backupCode; // Simpan sementara

            echo json_encode(['status' => 'success', 'backup_code' => $backupCode]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Incorrect code!']);
        }
        exit();
    }

    // 3. FINALISASI (Simpan Nama & Data ke DB)
    elseif ($_POST['ajax_action'] == 'finalize_2fa') {
        $authName = strip_tags(trim($_POST['auth_name']));
        $secret = $_SESSION['temp_2fa_secret'] ?? null;
        $backup = $_SESSION['temp_2fa_backup'] ?? null;

        if (!$secret || !$backup) { echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap.']); exit(); }
        if (empty($authName)) $authName = "Authenticator Saya";

        // Simpan ke DB
        $stmt = $conn->prepare("UPDATE users SET two_factor_secret=?, two_factor_name=?, two_factor_backup=?, is_2fa_enabled=1 WHERE id=?");
        $stmt->bind_param("sssi", $secret, $authName, $backup, $uid);

        if ($stmt->execute()) {
            logActivity($conn, $uid, "Mengaktifkan 2FA: $authName");
            
            // Bersihkan sesi temp
            unset($_SESSION['temp_2fa_secret']);
            unset($_SESSION['temp_2fa_backup']);

            // SET SESSION POPUP SUKSES (Agar muncul setelah reload)
            $_SESSION['popup_status'] = 'success';
            $_SESSION['popup_message'] = 'Authenticator Berhasil Diaktifkan!';

            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan database.']);
        }
        exit();
    }

    // 4. NONAKTIFKAN 2FA (Dengan Verifikasi Kode)
    elseif ($_POST['ajax_action'] == 'disable_2fa_secure') {
        $code = trim($_POST['otp_code']);
        
        // Ambil secret & backup dari DB
        $q = $conn->query("SELECT two_factor_secret, two_factor_backup FROM users WHERE id='$uid'");
        $u = $q->fetch_assoc();
        
        $isValid = false;
        $google2fa = new \PragmaRX\Google2FA\Google2FA();

        // Cek apakah ini Kode Backup (Panjang 32) atau OTP (6 Digit)
        if (strlen($code) == 32 && $code === $u['two_factor_backup']) {
            $isValid = true;
            logActivity($conn, $uid, "Menonaktifkan 2FA menggunakan Kode Backup");
        } elseif ($google2fa->verifyKey($u['two_factor_secret'], $code)) {
            $isValid = true;
            logActivity($conn, $uid, "Menonaktifkan 2FA menggunakan Authenticator");
        }

        if ($isValid) {
            // Kode Benar -> Hapus Data
            $conn->query("UPDATE users SET two_factor_secret=NULL, two_factor_name=NULL, two_factor_backup=NULL, is_2fa_enabled=0 WHERE id='$uid'");
            
            $_SESSION['popup_status'] = 'success';
            $_SESSION['popup_message'] = 'Authenticator berhasil dimatikan.';
            
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Kode salah!']);
        }
        exit();
    }

    elseif ($_POST['ajax_action'] == 'verify_2fa_general') {
        $code = trim($_POST['otp_code']);
        
        // Ambil secret & backup dari DB
        $q = $conn->query("SELECT two_factor_secret, two_factor_backup FROM users WHERE id='$uid'");
        $u = $q->fetch_assoc();
        
        if (empty($u['two_factor_secret'])) {
            echo json_encode(['status' => 'success']); 
            exit();
        }

        $isValid = false;
        $google2fa = new \PragmaRX\Google2FA\Google2FA();

        // Cek Backup Code atau OTP
        if (strlen($code) == 32 && $code === $u['two_factor_backup']) {
            $isValid = true;
        } elseif ($google2fa->verifyKey($u['two_factor_secret'], $code)) {
            $isValid = true;
        }

        if ($isValid) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Kode salah!']);
        }
        exit();
    }

    elseif ($_POST['ajax_action'] == 'verify_old_password') {
        $old_pass = $_POST['old_password'];
        $q = $conn->query("SELECT password FROM users WHERE id='$uid'");
        $row = $q->fetch_assoc();
        if (password_verify($old_pass, $row['password'])) {
            $response = ['status' => 'success'];
        } else {
            $response = ['status' => 'error', 'message' => 'Password lama salah!'];
        }
    }

    elseif ($_POST['ajax_action'] == 'send_otp_pass') {
        $current_time = time();
        
        // Inisialisasi Session Rate Limit jika belum ada
        if (!isset($_SESSION['otp_attempts'])) {
            $_SESSION['otp_attempts'] = 0;
            $_SESSION['otp_next_allowed'] = 0;
        }

        // Cek apakah masih dalam masa tunggu (Cooldown)
        if ($current_time < $_SESSION['otp_next_allowed']) {
            $wait_seconds = $_SESSION['otp_next_allowed'] - $current_time;
            $response = [
                'status' => 'error', 
                'message' => 'Tunggu ' . ceil($wait_seconds/60) . ' menit lagi sebelum kirim ulang.',
                'wait' => $wait_seconds // Kirim sisa waktu ke JS
            ];
            echo json_encode($response);
            exit();
        }

        // Cek Batas Maksimal 5 Kali
        if ($_SESSION['otp_attempts'] >= 5) {
            // Hukuman 1 Jam (3600 detik)
            $_SESSION['otp_next_allowed'] = $current_time + 3600;
            // Reset attempt agar setelah 1 jam mulai dari 0 lagi (opsional, atau biarkan tetap 5)
            $_SESSION['otp_attempts'] = 0; 

            $response = [
                'status' => 'error', 
                'message' => 'Terlalu banyak percobaan. Silakan coba lagi dalam 1 jam.',
                'wait' => 3600
            ];
            echo json_encode($response);
            exit();
        }

        // --- PROSES KIRIM EMAIL ---
        $q = $conn->query("SELECT email FROM users WHERE id='$uid'");
        $row = $q->fetch_assoc();
        $email = $row['email'];
        $otp = rand(100000, 999999);
        $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        $conn->query("UPDATE users SET otp='$otp', otp_expiry='$expiry' WHERE id='$uid'");
        
        if (sendOTPEmail($email, $otp)) {
            // BERHASIL KIRIM -> HITUNG WAKTU TUNGGU BERIKUTNYA
            $attempts = $_SESSION['otp_attempts'];
            
            // Logika Backoff: 1 menit, 2 menit, 4 menit, dst.
            // Rumus: 60 detik * (2 pangkat percobaan)
            $next_wait = 60 * pow(2, $attempts); 
            
            $_SESSION['otp_next_allowed'] = $current_time + $next_wait;
            $_SESSION['otp_attempts']++;

            $response = [
                'status' => 'success', 
                'message' => 'OTP terkirim.',
                'next_wait' => $next_wait // Beritahu JS berapa lama timer harus berjalan
            ];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal mengirim email.'];
        }
    }

    elseif ($_POST['ajax_action'] == 'verify_otp_pass') {
        $input_otp = $_POST['otp_code'];
        $now = date('Y-m-d H:i:s');
        $q = $conn->query("SELECT otp, otp_expiry FROM users WHERE id='$uid'");
        $user = $q->fetch_assoc();

        if ($user['otp'] == $input_otp && $user['otp_expiry'] > $now) {
            $conn->query("UPDATE users SET otp=NULL WHERE id='$uid'"); // Hanguskan OTP
            $response = ['status' => 'success'];
        } else {
            $response = ['status' => 'error', 'message' => 'Kode OTP salah atau kadaluarsa!'];
        }
    }
    echo json_encode($response);
    exit(); // Stop eksekusi agar tidak memuat HTML
}

// --- LOGIC SIMPAN PASSWORD BARU (POST BIASA) ---
if (isset($_POST['save_new_password'])) {
    $new_pass = $_POST['new_password'];
    
    // Validasi Standar Register (Length, Upper, Number, Symbol)
    $uppercase = preg_match('@[A-Z]@', $new_pass);
    $number    = preg_match('@[0-9]@', $new_pass);
    $symbol    = preg_match('@[^\w]@', $new_pass);

    if (strlen($new_pass) < 6 || !$uppercase || !$number || !$symbol) {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Password lemah! Harus 6+ karakter, ada Huruf Besar, Angka, dan Simbol.';
    } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password='$hash' WHERE id='$user_id'");
        
        logActivity($conn, $user_id, "Berhasil mengganti password baru");

        $_SESSION['popup_status'] = 'success';
        $_SESSION['popup_message'] = 'Password berhasil diganti!';
    }
    header("Location: ./"); exit();
}





if (isset($_POST['update_profile'])) {
    $new_username = htmlspecialchars($_POST['username']);
    $new_email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    // (Password di form utama sudah dihapus HTML-nya, tapi logic PHP tetap dijaga untuk keamanan back-end)
    $new_pass = isset($_POST['password']) ? $_POST['password'] : '';
    
    // AMBIL DATA LAMA UNTUK PERBANDINGAN LOG
    $q_curr = $conn->query("SELECT username, email FROM users WHERE id='$user_id'");
    $curr_data = $q_curr->fetch_assoc();

    // 1. LOGIC GANTI FOTO
    if (!empty($_POST['cropped_image'])) {
        $data = $_POST['cropped_image'];
        if (strpos($data, 'base64') !== false) {
            list($type, $data) = explode(';', $data);
            list(, $data)      = explode(',', $data);
            $data = base64_decode($data);
            $image = imagecreatefromstring($data);
            
            if ($image !== false) {
                ob_start();
                imagewebp($image, null, 80);
                $webp_data = ob_get_contents();
                ob_end_clean();
                imagedestroy($image);

                $timestamp = date('Y-m-d_H-i-s');
                $s3_key = "photoprofile/{$timestamp}_{$user_id}.webp";

                try {
                    $result = $s3->putObject([
                        'Bucket' => $minio_bucket,
                        'Key'    => $s3_key,
                        'Body'   => $webp_data,
                        'ContentType' => 'image/webp',
                        'ACL'    => 'public-read'
                    ]);
                    $foto_url = $result['ObjectURL'];
                    $conn->query("UPDATE users SET profile_pic='$foto_url' WHERE id='$user_id'");
                    
                    // LOG PERGANTIAN FOTO
                    logActivity($conn, $user_id, "Foto Profil diubah");

                } catch (AwsException $e) {
                    $_SESSION['popup_status'] = 'error';
                    $_SESSION['popup_message'] = "Upload Gagal: " . $e->getMessage();
                }
            }
        }
    }

    // 2. LOGIC GANTI USERNAME/EMAIL
    $conn->query("UPDATE users SET username='$new_username', email='$new_email' WHERE id='$user_id'");
    
    // LOG PERUBAHAN DATA
    if ($curr_data['username'] != $new_username) {
        logActivity($conn, $user_id, "Username Diganti dari " . $curr_data['username'] . " menjadi " . $new_username);
    }
    if ($curr_data['email'] != $new_email) {
        logActivity($conn, $user_id, "Email Diganti dari " . $curr_data['email'] . " menjadi " . $new_email);
    }

    // 3. LOGIC GANTI PASSWORD (VIA FORM PROFIL - LEGACY)
    if (!empty($new_pass)) {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password='$hash' WHERE id='$user_id'");
        
        // LOG PASSWORD
        logActivity($conn, $user_id, "Mengganti password melalui edit profil");
    }
    
    $_SESSION['valselt_username'] = $new_username;
    $_SESSION['popup_status'] = 'success';
    $_SESSION['popup_message'] = 'Profil berhasil diperbarui!';
    header("Location: ./");
    exit();
}

// --- LOGIC HAPUS PASSKEY (BARU) ---
if (isset($_POST['delete_passkey'])) {
    $pk_id = intval($_POST['pk_id']);
    
    // Pastikan passkey milik user yang sedang login
    $stmt = $conn->prepare("DELETE FROM user_passkeys WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $pk_id, $user_id);
    
    if ($stmt->execute()) {
        logActivity($conn, $user_id, "Menghapus Passkey");
        $_SESSION['popup_status'] = 'success';
        $_SESSION['popup_message'] = 'Passkey berhasil dihapus!';
    } else {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Gagal menghapus passkey.';
    }
    header("Location: ./"); exit();
}

// --- LOGIC REVOKE APP ACCESS ---
if (isset($_POST['revoke_app_id'])) {
    $app_id = intval($_POST['revoke_app_id']);
    
    // Pastikan app milik user yang login
    $stmt = $conn->prepare("DELETE FROM authorized_apps WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $app_id, $user_id);
    
    if ($stmt->execute()) {
        logActivity($conn, $user_id, "Mencabut akses aplikasi (Revoke Access)");
        $_SESSION['popup_status'] = 'success';
        $_SESSION['popup_message'] = 'Akses aplikasi berhasil dicabut.';
    } else {
        $_SESSION['popup_status'] = 'error';
        $_SESSION['popup_message'] = 'Gagal mencabut akses.';
    }
    header("Location: ./"); exit();
}

// --- HAPUS AKUN ---
if (isset($_POST['delete_account'])) {
    // LOG SEBELUM MENGHAPUS
    logActivity($conn, $user_id, "Melakukan penghapusan akun permanen");

    $conn->query("DELETE FROM users WHERE id='$user_id'");
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, "/");
        unset($_COOKIE['remember_token']);
    }
    session_destroy();
    header("Location: login.php"); exit();
}

// ... (Kode sebelumnya: delete_account, delete_passkey, dll)

// --- LOGIC KIRIM LOGS KE EMAIL (CSV) ---
// --- LOGIC KIRIM LOGS KE EMAIL (CSV) ---
if (isset($_POST['send_logs_email'])) {
    // Ganti activity_logs menjadi logsuser
    $q_logs = $conn->query("SELECT behaviour, created_at FROM logsuser WHERE id_user='$user_id' ORDER BY id DESC");
    
    if ($q_logs && $q_logs->num_rows > 0) {
        $csv_data = "Waktu,Aktivitas\n"; // Header CSV
        while ($log = $q_logs->fetch_assoc()) {
            $date = isset($log['created_at']) ? date('Y-m-d H:i:s', strtotime($log['created_at'])) : '-';
            // Bersihkan koma agar format CSV tidak rusak
            $act  = str_replace(',', ';', $log['behaviour']); 
            $csv_data .= "$date,$act\n";
        }

        if (sendLogEmail($user_data['email'], $user_data['username'], $csv_data)) {
            $_SESSION['popup_status'] = 'success';
            $_SESSION['popup_message'] = 'Log aktivitas telah dikirim ke email Anda.';
        } else {
            $_SESSION['popup_status'] = 'error';
            $_SESSION['popup_message'] = 'Gagal mengirim email.';
        }
    } else {
        $_SESSION['popup_status'] = 'warning';
        $_SESSION['popup_message'] = 'Belum ada data log untuk dikirim.';
    }
    header("Location: ./"); exit();
}

// --- LOGIC DOWNLOAD DATA PRIBADI (JSON) ---
if (isset($_POST['download_my_data'])) {
    // 1. Siapkan Struktur Data
    $export = [
        'generated_at' => date('Y-m-d H:i:s'),
        'profile' => [
            'username' => $user_data['username'],
            'email' => $user_data['email'],
            'joined_at' => $user_data['created_at'],
            'verification_status' => (bool)$user_data['is_verified'],
            '2fa_enabled' => (bool)$user_data['is_2fa_enabled']
        ]
    ];

    // 2. Ambil Data Perangkat Aktif
    $devs = [];
    $q_d = $conn->query("SELECT device_name, ip_address, location, last_login, is_active FROM user_devices WHERE user_id='$user_id'");
    while($d = $q_d->fetch_assoc()) { $devs[] = $d; }
    $export['devices_history'] = $devs;

    // 3. Ambil Data Aplikasi Terhubung (SSO)
    $apps = [];
    $q_a = $conn->query("SELECT app_name, app_domain, last_accessed FROM authorized_apps WHERE user_id='$user_id'");
    while($a = $q_a->fetch_assoc()) { $apps[] = $a; }
    $export['connected_apps'] = $apps;

    // 4. Ambil Data Passkeys
    $pks = [];
    $q_p = $conn->query("SELECT credential_source, created_at FROM user_passkeys WHERE user_id='$user_id'");
    while($p = $q_p->fetch_assoc()) { $pks[] = $p; }
    $export['security_passkeys'] = $pks;

    // 5. Ambil Log Aktivitas (100 Terakhir)
    $logs = [];
    $q_l = $conn->query("SELECT behaviour, created_at FROM logsuser WHERE id_user='$user_id' ORDER BY id DESC LIMIT 100");
    while($l = $q_l->fetch_assoc()) { $logs[] = $l; }
    $export['recent_activity_logs'] = $logs;

    // 6. Output sebagai File JSON
    $json_data = json_encode($export, JSON_PRETTY_PRINT);
    $filename = 'valselt_data_archive_' . date('Ymd_His') . '.json';

    // Log aktivitas download
    logActivity($conn, $user_id, "Mengunduh Arsip Data Pribadi (JSON)");

    // Force Download
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json_data));
    echo $json_data;
    exit();
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akun Saya - Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body style="background:#f9fafb;"> <div class="valselt-container">
    <div class="valselt-header">
        <img src="https://cdn.ivanaldorino.web.id/valselt/valselt_black.png" alt="Valselt" class="logo-dashboard">
        <p style="color:var(--text-muted);">Account Center</p>
    </div>

    <div class="profile-card">
        <form action="./" method="POST" id="profileForm">
            <input type="hidden" name="cropped_image" id="cropped_image_data">

            <div style="display:flex; flex-direction:column; align-items:center; margin-bottom:40px;">
                <div class="avatar-wrapper">
                    <?php if($user_data['profile_pic']): ?>
                        <img src="<?php echo $user_data['profile_pic']; ?>" id="main-preview" class="avatar-img" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <div id="main-preview-placeholder" class="avatar-placeholder" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center;">
                            <?php echo strtoupper(substr($user_data['username'], 0, 2)); ?>
                        </div>
                        <img src="" id="main-preview" class="avatar-img" style="display:none; width:100%; height:100%; object-fit:cover;">
                    <?php endif; ?>
                    
                    <div class="btn-edit-avatar" onclick="document.getElementById('hidden-file-input').click()" 
                         style="position:absolute; bottom:0; right:0; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; cursor:pointer;">
                        <i class='bx bx-camera'></i>
                    </div>
                </div>
                <h2 style="font-family:var(--font-serif); font-size:2rem; font-weight:400; color:var(--text-main);"><?php echo htmlspecialchars($user_data['username']); ?></h2>
                <p style="color:var(--text-muted);"><?php echo htmlspecialchars($user_data['email']); ?></p>
            </div>

            <input type="file" id="hidden-file-input" accept="image/png, image/jpeg, image/jpg">

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
            </div>
        </form>

        <div class="accordion-main">
            <div id="accordionContainer" class="accordionContainer">
            
                <div class="accordion-header" id="acc1-header" onclick="toggleAccordion('acc1-header')">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class='bx bx-user-circle' style="font-size:1.5rem; color:var(--text-main);"></i>
                        Account & Devices
                    </div>
                    <i class='bx bx-chevron-right indicator'></i>
                </div>

                <div class="accordion-content" id="acc1-content">
                    
                    <div class="accordion-content-inside">
                        <h4 style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center;">
                            <i class='bx bx-link' style="margin-right:10px; font-size:1.2rem;"></i>
                            <div>
                                Linked Accounts
                                <p style="font-size:0.75rem; color:var(--text-muted); font-weight:400; margin-top:2px;">Link to your Social Account.</p>
                            </div>
                        </h4>
                        
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div style="display:flex; align-items:center;">
                                <img src="https://www.svgrepo.com/show/475656/google-color.svg" style="width:24px; margin-right:12px;">
                                <div>
                                    <div style="font-weight:500;">Google</div>
                                    <div style="font-size:0.85rem; color:var(--text-muted);">
                                        <?php if($user_data['google_id']): ?> Connected <?php else: ?> Not Connected <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if($user_data['google_id']): ?>
                                <button disabled class="btn" style="width:auto; padding: 8px; font-size:0.9rem; background:#dcfce7; color:#166534; cursor:default;"><i class='bx bx-check'></i></button>
                            <?php else: ?>
                                <a href="<?php echo $google_client->createAuthUrl(); ?>" class="btn" style="width:auto; padding: 8px; font-size:0.9rem; background:white; border:1px solid #d1d5db;"><i class='bx bx-link-alt'></i></a>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px;">
                            <div style="display:flex; align-items:center;">
                                <i class='bx bxl-github' style="font-size:28px; margin-right:12px; color:#333;"></i>
                                <div>
                                    <div style="font-weight:500;">GitHub</div>
                                    <div style="font-size:0.85rem; color:var(--text-muted);">
                                        <?php if($user_data['github_id']): ?> Connected <?php else: ?> Not Connected <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if($user_data['github_id']): ?>
                                <button disabled class="btn" style="width:auto; padding: 8px; font-size:0.9rem; background:#dcfce7; color:#166534; cursor:default;"><i class='bx bx-check'></i></button>
                            <?php else: ?>
                                <?php $github_link_url = "https://github.com/login/oauth/authorize?client_id=" . $github_client_id . "&scope=user:email"; ?>
                                <a href="<?php echo $github_link_url; ?>" class="btn" style="width:auto; padding: 8px; font-size:0.9rem; background:white; border:1px solid #d1d5db;"><i class='bx bx-link-alt'></i></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="accordion-content-inside">
                        <h4 style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center;">
                            <i class='bx bx-devices' style="margin-right:10px; font-size:1.2rem;"></i> 
                            <div>
                                Devices
                                <p style="font-size:0.75rem; color:var(--text-muted); font-weight:400; margin-top:2px;">Your connected devices.</p>
                            </div>
                        </h4>

                        <?php
                        $current_session = session_id();
                        $q_dev = $conn->query("SELECT * FROM user_devices WHERE user_id='$user_id' ORDER BY is_active DESC, last_login DESC");
                        
                        if ($q_dev->num_rows > 0):
                            while($dev = $q_dev->fetch_assoc()):
                                $is_this_session = ($dev['session_id'] == $current_session);
                                $isActive = $dev['is_active']; // 1 = Login, 0 = Logout
                                
                                // Icon Type
                                $iconClass = 'bx-laptop'; 
                                if (stripos($dev['device_name'], 'Android') !== false || stripos($dev['device_name'], 'iPhone') !== false) { 
                                    $iconClass = 'bx-mobile'; 
                                }

                                // Style Logika
                                if ($isActive) {
                                    // AKTIF: Hijau Gelap (#166534), Ikon Putih (#ffffff)
                                    $bgStyle = "background:#166534; color:#ffffff;";
                                    $statusText = '<span style="color:#166534; font-weight:600; font-size:0.75rem; margin-left:8px;">● Active Now</span>';
                                } else {
                                    // LOGOUT: Abu-abu (Default lama)
                                    $bgStyle = "background:#f3f4f6; color:var(--primary);";
                                    $statusText = '<span style="color:#9ca3af; font-size:0.75rem; margin-left:8px;">Signed out</span>';
                                }
                                
                                $loc_display = !empty($dev['location']) ? htmlspecialchars($dev['location']) : 'Unknown Location';
                        ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6;">
                            <div style="display:flex; align-items:center;">
                                <div style="width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-right:15px; <?php echo $bgStyle; ?>">
                                    <i class='bx <?php echo $iconClass; ?>' style="font-size:1.2rem;"></i>
                                </div>
                                
                                <div>
                                    <div style="font-weight:600; font-size:0.95rem; color:var(--text-main); display: flex; align-items: center;">
                                        <?php echo htmlspecialchars($dev['device_name']); ?>
                                        
                                        <?php if($is_this_session): ?>
                                            <span style="background:#dcfce7; color:#166534; font-size:0.7rem; padding:2px 8px; border-radius:10px; margin-left:8px; border: 1px solid #bbf7d0;">This Device</span>
                                        <?php else: ?>
                                            <?php echo $statusText; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">
                                        <i class='bx bx-map' style="font-size:0.8rem; margin-right:2px;"></i> <?php echo $loc_display; ?>
                                    </div>
                                    
                                    <div style="font-size:0.75rem; color:#9ca3af; margin-top:2px;">
                                        <?php echo date('d M Y, H:i', strtotime($dev['last_login'])); ?> • IP: <?php echo htmlspecialchars($dev['ip_address']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; else: ?>
                            <p style="color:var(--text-muted); font-size:0.9rem; text-align:center;">Belum ada data perangkat tersimpan.</p>
                        <?php endif; ?>
                    </div>
                    
                </div>
            </div>
            
            <div id="accordionContainer" class="accordionContainer">
                <div class="accordion-header" id="acc2-header" onclick="toggleAccordion('acc2-header')">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class='bx bx-lock-alt' style="font-size:1.5rem; color:var(--text-main);"></i>
                        Security
                    </div>
                    <i class='bx bx-chevron-right indicator'></i>
                </div>
                
                <div class="accordion-content" id="acc2-content">
                    
                    <div class="accordion-content-inside">
                        <div style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center; justify-content:space-between;" class="passkey-title">
                            <div class="passkey-header" style="display:flex; flex-direction:row; align-items:center;">
                                <i class='bx bx-fingerprint' style="margin-right:10px; font-size:1.2rem;"></i>
                                <div>
                                    <h4>Passkey</h4>
                                    <p style="font-size:0.75rem; color:var(--text-muted); font-weight:400; margin-top:2px;">Passwordless Login.</p>
                                </div>
                            </div>
                            <button onclick="registerPasskey()" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#000; color:white;">
                                <i class='bx bx-plus'></i>
                            </button>
                        </div>

                        <div class="passkey-list">
                            <?php
                            $q_pk = $conn->query("SELECT * FROM user_passkeys WHERE user_id='$user_id' ORDER BY created_at DESC");
                            
                            if ($q_pk->num_rows > 0):
                                while($pk = $q_pk->fetch_assoc()):
                                    $pk_date = date('d M Y, H:i', strtotime($pk['created_at']));
                                    $sourceRaw = $pk['credential_source'] ? htmlspecialchars($pk['credential_source']) : 'Passkey Credential';
                                    $source = strtolower($sourceRaw); // Untuk pencarian case-insensitive
                                    
                                    // --- 1. DEFAULT STYLE (Boxicons - Biru) ---
                                    $isSvg = false;
                                    $iconContent = "<i class='bx bx-key' style='font-size:1.4rem;'></i>";
                                    $bgColor = '#e0f2fe'; 
                                    $fgColor = '#0284c7'; // Warna Icon Boxicons

                                    // --- 2. DETEKSI PLATFORM UTAMA (PRIORITAS 1) ---
                                    // Tetap dipertahankan sesuai permintaan, icon Boxicons bawaan
                                    if (strpos($source, 'google') !== false || strpos($source, 'android') !== false) { 
                                        $iconContent = "<i class='bx bxl-google' style='font-size:1.4rem;'></i>"; 
                                        $bgColor = '#dcfce7'; $fgColor = '#166534'; 
                                    } 
                                    elseif (strpos($source, 'icloud') !== false || strpos($source, 'apple') !== false || strpos($source, 'iphone') !== false || strpos($source, 'ipad') !== false || strpos($source, 'mac') !== false) { 
                                        $iconContent = "<i class='bx bxl-apple' style='font-size:1.4rem;'></i>"; 
                                        $bgColor = '#f3f4f6'; $fgColor = '#1f2937'; 
                                    } 
                                    elseif (strpos($source, 'windows') !== false) { 
                                        $iconContent = "<i class='bx bxl-windows' style='font-size:1.4rem;'></i>"; 
                                        $bgColor = '#dbeafe'; $fgColor = '#2563eb'; 
                                    }
                                    
                                    // --- 3. DETEKSI 3RD PARTY APPS (PRIORITAS 2 - SIMPLEICONS) ---
                                    // Jika nama mengandung keyword tertentu, override icon & warna
                                    else {
                                        $slug = ''; // SimpleIcons slug
                                        
                                        if (strpos($source, 'proton') !== false) {
                                            $isSvg = true; $slug = 'proton'; $fgColor = '6D4AFF'; $bgColor = '#f0ecff';
                                        }
                                        elseif (strpos($source, '1') !== false || strpos($source, '1password') !== false) {
                                            $isSvg = true; $slug = '1password'; $fgColor = '3B66BC'; $bgColor = '#EBEFF8';
                                        }
                                        elseif (strpos($source, 'bitwarden') !== false) {
                                            $isSvg = true; $slug = 'bitwarden'; $fgColor = '175ddc'; $bgColor = '#e7eefb';
                                        }
                                        elseif (strpos($source, 'ente') !== false) {
                                            $isSvg = true; $slug = 'ente'; $fgColor = 'a75cff'; $bgColor = '#EDDEFF';
                                        }
                                        elseif (strpos($source, 'last') !== false || strpos($source, 'lastpass') !== false) {
                                            $isSvg = true; $slug = 'lastpass'; $fgColor = 'D32D27'; $bgColor = '#f6d5d5';
                                        }
                                        elseif (strpos($source, 'aegis') !== false) {
                                            $isSvg = true; $slug = 'aegisauthenticator'; $fgColor = '005E9D'; $bgColor = '#E5EEF5';
                                        }
                                        elseif (strpos($source, 'okta') !== false) {
                                            $isSvg = true; $slug = 'okta'; $fgColor = '000000'; $bgColor = '#007DC1'; // Icon hitam agar kontras di bg biru
                                        }
                                        elseif (strpos($source, 'yubi') !== false || strpos($source, 'yubikey') !== false || strpos($source, 'yubico') !== false) {
                                            $isSvg = true; $slug = 'yubico'; $fgColor = '84bd00'; $bgColor = '#F2F8E5';
                                        }
                                        elseif (strpos($source, 'keeper') !== false) {
                                            $isSvg = true; $slug = 'keeper'; $fgColor = 'FFC700'; $bgColor = '#FFF9E5';
                                        }
                                        elseif (strpos($source, 'norton') !== false) {
                                            $isSvg = true; $slug = 'norton'; $fgColor = '000000'; $bgColor = '#FFE01A';
                                        }
                                        elseif (strpos($source, 'dashlane') !== false) {
                                            $isSvg = true; $slug = 'dashlane'; $fgColor = '0E353D'; $bgColor = '#E6EAEB';
                                        }
                                        elseif (strpos($source, 'nord') !== false || strpos($source, 'nordpass') !== false) {
                                            $isSvg = true; $slug = 'nordvpn'; $fgColor = '4687FF'; $bgColor = '#ECF3FF';
                                        }
                                        elseif (strpos($source, 'enpass') !== false) {
                                            $isSvg = true; $slug = 'enpass'; $fgColor = '0D47A1'; $bgColor = '#E6ECF5';
                                        }
                                        elseif (strpos($source, 'kee') !== false || strpos($source, 'keepass') !== false || strpos($source, 'keepassxc') !== false) {
                                            $isSvg = true; $slug = 'keepassxc'; $fgColor = '6CAC4D'; $bgColor = '#F0F6ED';
                                        }
                                        elseif (strpos($source, 'avira') !== false) {
                                            $isSvg = true; $slug = 'avira'; $fgColor = 'E02027'; $bgColor = '#FBE8E9';
                                        }
                                        elseif (strpos($source, 'avast') !== false) {
                                            $isSvg = true; $slug = 'avast'; $fgColor = 'ffffff'; $bgColor = '#FF7800';
                                        }
                                        elseif (strpos($source, 'bitdefender') !== false) {
                                            $isSvg = true; $slug = 'bitdefender'; $fgColor = 'ffffff'; $bgColor = '#ED1C24';
                                        }
                                        elseif (strpos($source, 'mega') !== false) {
                                            $isSvg = true; $slug = 'mega'; $fgColor = 'ffffff'; $bgColor = '#D9272E';
                                        }
                                        elseif (strpos($source, 'vault') !== false || strpos($source, 'vaultwarden') !== false) {
                                            $isSvg = true; $slug = 'vaultwarden'; $fgColor = 'ffffff'; $bgColor = '#000000';
                                        }
                                        elseif (strpos($source, 'bolt') !== false || strpos($source, 'passbolt') !== false) {
                                            $isSvg = true; $slug = 'passbolt'; $fgColor = 'D40101'; $bgColor = '#FAE5E5';
                                        }
                                        elseif (strpos($source, 'kaspersky') !== false) {
                                            $isSvg = true; $slug = 'kaspersky'; $fgColor = 'ffffff'; $bgColor = '#006D5C';
                                        }
                                        elseif (strpos($source, 'nextcloud') !== false) {
                                            $isSvg = true; $slug = 'nextcloud'; $fgColor = '0082C9'; $bgColor = '#E5F2F9';
                                        }

                                        // Set SVG Content jika match
                                        if ($isSvg) {
                                            $iconUrl = "https://cdn.simpleicons.org/$slug/$fgColor";
                                            $iconContent = "<img src='$iconUrl' style='width:24px; height:24px; display:block;'>";
                                            // Tambah hash untuk CSS color container
                                            if(strpos($fgColor, '#') === false && strlen($fgColor) <= 6) {
                                                $fgColor = '#' . $fgColor; 
                                            }
                                        }
                                    }
                            ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6;">
                                <div style="display:flex; align-items:center;">
                                    <div style="width:40px; height:40px; background:<?php echo $bgColor; ?>; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-right:15px; color:<?php echo $fgColor; ?>;">
                                        <?php echo $iconContent; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:600; font-size:0.95rem; color:var(--text-main);"><?php echo $sourceRaw; ?></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted);">Created on: <?php echo $pk_date; ?></div>
                                    </div>
                                </div>
                                <button type="button" onclick="openDeletePasskey('<?php echo $pk['id']; ?>')" class="btn" style="width:auto; padding: 8px; font-size:0.9rem; background:transparent; color:#ef4444; border:none; cursor:pointer;" title="Hapus Passkey">
                                    <i class='bx bx-trash' style="font-size:1.2rem;"></i>
                                </button>
                            </div>
                            <?php 
                                endwhile; 
                            else: 
                            ?>
                                <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:0.9rem;">
                                    No Passkeys are saved yet. Click “+” to add one.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="accordion-content-inside">
                        <div style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center; justify-content:space-between;">
                            <div style="display:flex; flex-direction:row; align-items:center;">
                                <i class='bx bx-mobile-alt' style="margin-right:10px; font-size:1.2rem;"></i>
                                <div>
                                    <h4>Authenticator (2FA)</h4>
                                    <p style="font-size:0.75rem; color:var(--text-muted); font-weight:400; margin-top:2px;">Adds an additional layer of security to your account.</p>
                                </div>
                            </div>
                            
                            <?php if($user_data['is_2fa_enabled']): ?>
                                <button onclick="disable2FA()" class="btn" style="width:auto; padding: 8px 16px; font-size:0.85rem; background:#fee2e2; color:#b91c1c; border:1px solid #fecaca;">
                                    <i class='bx bx-power-off'></i> Disable
                                </button>
                            <?php else: ?>
                                <button onclick="open2FAModal()" class="btn" style="width:auto; padding: 8px 16px; font-size:0.85rem; background:#000; color:white;">
                                    Enable
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if($user_data['is_2fa_enabled']): ?>
                            <?php
                                // 1. Ambil Nama & Normalisasi ke huruf kecil untuk pencarian
                                $authNameRaw = $user_data['two_factor_name'] ?? 'Authenticator Saya';
                                $authName = strtolower($authNameRaw);

                                // 2. Default Style (Boxicons - Hijau)
                                $isSvg = false;
                                $iconContent = "<i class='bx bx-check-shield' style='font-size:1.4rem;'></i>";
                                $fgColor = '#166534'; // Warna Icon (untuk Boxicons)
                                $bgColor = '#dcfce7'; // Warna Background Circle

                                // 3. Logika Deteksi Nama
                                if (strpos($authName, 'google') !== false) {
                                    $isSvg = true; $slug = 'googleauthenticator'; $fgColor = '166534'; $bgColor = '#dcfce7';
                                } 
                                elseif (strpos($authName, 'proton') !== false) {
                                    $isSvg = true; $slug = 'proton'; $fgColor = '6D4AFF'; $bgColor = '#f0ecff';
                                }
                                elseif (strpos($authName, '1') !== false || strpos($authName, '1password') !== false) {
                                    $isSvg = true; $slug = '1password'; $fgColor = '3B66BC'; $bgColor = '#EBEFF8';
                                }
                                elseif (strpos($authName, 'bitwarden') !== false) {
                                    $isSvg = true; $slug = 'bitwarden'; $fgColor = '175ddc'; $bgColor = '#e7eefb';
                                }
                                elseif (strpos($authName, 'ente') !== false) {
                                    $isSvg = true; $slug = 'ente'; $fgColor = 'a75cff'; $bgColor = '#EDDEFF';
                                }
                                elseif (strpos($authName, 'last') !== false || strpos($authName, 'lastpass') !== false) {
                                    $isSvg = true; $slug = 'lastpass'; $fgColor = 'D32D27'; $bgColor = '#fcecea'; // Fixed hex typo
                                }
                                elseif (strpos($authName, 'aegis') !== false) {
                                    $isSvg = true; $slug = 'aegisauthenticator'; $fgColor = '005E9D'; $bgColor = '#E5EEF5';
                                }
                                elseif (strpos($authName, 'okta') !== false) {
                                    $isSvg = true; $slug = 'okta'; $fgColor = 'ffffff'; $bgColor = '#007DC1';
                                }
                                elseif (strpos($authName, 'yubi') !== false || strpos($authName, 'yubikey') !== false || strpos($authName, 'yubico') !== false) {
                                    $isSvg = true; $slug = 'yubico'; $fgColor = '84bd00'; $bgColor = '#F2F8E5';
                                }

                                // 4. Set Konten Icon Jika SVG
                                if ($isSvg) {
                                    $iconUrl = "https://cdn.simpleicons.org/$slug/$fgColor";
                                    $iconContent = "<img src='$iconUrl' style='width:24px; height:24px; display:block;'>";
                                    // Tambahkan # untuk color style container jika belum ada (untuk Boxicons fallback color)
                                    $fgColor = "#" . str_replace('#', '', $fgColor); 
                                }
                            ?>

                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6;">
                                <div style="display:flex; align-items:center;">
                                    <div style="width:40px; height:40px; background:<?php echo $bgColor; ?>; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-right:15px; color:<?php echo $fgColor; ?>;">
                                        <?php echo $iconContent; ?>
                                    </div>
                                    
                                    <div>
                                        <div style="font-weight:600; font-size:0.95rem; color:var(--text-main);">
                                            <?php echo htmlspecialchars($authNameRaw); ?>
                                        </div>
                                        <div style="font-size:0.8rem; color:var(--text-muted);">Status: Active</div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="accordionContainer" class="accordionContainer">
                <div class="accordion-header" id="acc3-header" onclick="toggleAccordion('acc3-header')">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="bx bx-extension" style="font-size:1.5rem; color:var(--text-main);"></i>
                        Third-party Connection
                    </div>
                    <i class='bx bx-chevron-right indicator'></i>
                </div>
                
                <div class="accordion-content" id="acc3-content">
                    
                    <div class="accordion-content-inside">
                        <div style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center; justify-content:space-between;">
                            <div style="display:flex; flex-direction:row; align-items:center;">
                                <i class='bx bx-sitemap' style="margin-right:10px; font-size:1.2rem;"></i>
                                <div>
                                    <h4>Linked Apps and Services</h4>
                                    <p style="font-size:0.75rem; color:var(--text-muted); font-weight:400; margin-top:2px;">Applications authorized to access your account.</p>
                                </div>
                            </div>
                        </div>

                        <div class="apps-list">
                            <?php
                            $q_apps = $conn->query("SELECT * FROM authorized_apps WHERE user_id='$user_id' ORDER BY last_accessed DESC");
                            
                            if ($q_apps && $q_apps->num_rows > 0):
                                while($app = $q_apps->fetch_assoc()):
                                    $appName = htmlspecialchars($app['app_name']);
                                    $appDomain = htmlspecialchars($app['app_domain']);
                                    $lastAccess = date('d M Y, H:i', strtotime($app['last_accessed']));
                                    
                                    // --- LOGIKA FAVICON OTOMATIS (GOOGLE S2) ---
                                    $directFavicon = "https://" . $appDomain . "/favicon.ico?v=" . time();
                                    $backupFavicon = "https://www.google.com/s2/favicons?domain=" . $appDomain . "&sz=64";
                            ?>
                                
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6;">
                                    <div style="display:flex; align-items:center;">
                                        
                                        <div style="width:40px; height:40px; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; display:flex; align-items:center; justify-content:center; margin-right:15px; overflow:hidden;">
                                            <img src="<?php echo $directFavicon; ?>" 
                                                 alt="Icon" 
                                                 style="width:24px; height:24px; object-fit:contain;"
                                                 onerror="this.onerror=null; this.src='<?php echo $backupFavicon; ?>';">
                                        </div>
                                        
                                        <div>
                                            <div style="font-weight:600; font-size:0.95rem; color:var(--text-main);">
                                                <?php echo $appName; ?>
                                            </div>
                                            <div style="font-size:0.75rem; color:var(--text-muted);">
                                                <?php echo $appDomain; ?>
                                            </div>
                                            <div style="font-size:0.7rem; color:#9ca3af; margin-top:2px;">
                                                Last used: <?php echo $lastAccess; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <form method="POST" onsubmit="return confirm('Revoke access for <?php echo $appName; ?>? You will need to login again next time.');">
                                        <input type="hidden" name="revoke_app_id" value="<?php echo $app['id']; ?>">
                                        <button type="submit" class="btn" style="width:auto; padding: 6px 12px; font-size:0.8rem; background:white; border:1px solid #d1d5db; color:var(--text-muted); cursor:pointer;">
                                            Revoke
                                        </button>
                                    </form>
                                </div>

                            <?php endwhile; else: ?>
                                <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:0.9rem;">
                                    <i class='bx bx-cube' style="font-size: 2rem; display:block; margin-bottom:10px; opacity:0.5;"></i>
                                    You haven't connected any apps to Valselt ID yet.
                                </div>
                            <?php endif; ?>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid #e5e7eb; margin:40px 0;">

        <div style="background: #fffbeb; padding: 25px; border-radius: 12px; border: 1px solid #fef3c7; margin-bottom: 30px;">
            <div style="display:flex; align-items:center; margin-bottom: 20px; color: #ca8a04;">
                <i class='bx bx-show' style="font-size: 1.2rem; margin-right: 10px;"></i>
                <h4 style="font-weight:600;">Privacy Zone</h4>
            </div>

            <div style="display:flex; flex-direction:column; gap:15px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <div style="font-weight:600; color: #b45309;">Account Logs</div>
                        <div style="font-size:0.85rem; color: #ca8a04; opacity: 0.8; max-width: 300px; line-height: 1.5;">
                            View your account security activity history.
                        </div>
                    </div>
                    <button type="button" onclick="openLogsModal()" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#f59e0b; color:white; border:none; transition:0.2s;">
                        <i class='bx bx-receipt' style="font-size: 1.2rem;"></i>
                    </button>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 15px;">
                    <div style="flex: 1;">
                        <div style="font-weight:600; color: #b45309;">Download Personal Data</div>
                        <div style="font-size:0.85rem; color: #ca8a04; opacity: 0.8; line-height: 1.5;">
                            Get a copy of your data (Profile, Devices, Apps) in JSON format.
                        </div>
                    </div>
                    
                    <form method="POST" target="_blank" style="margin:0;" id="formDownloadData">
                        <input type="hidden" name="download_my_data" value="1">
                        
                        <button type="button" onclick="checkSecurityAndExecute(submitDownloadForm)" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#f59e0b; color:white; border:none; transition:0.2s; border-radius:8px;" title="Download JSON Archive">
                            <i class='bx bx-download' style="font-size: 1.2rem;"></i>
                        </button>
                    </form>
                </div>
            </div>

            
        </div>

        <div style="background: #fff5f5; padding: 25px; border-radius: 12px; border: 1px solid #fed7d7;">
            <div style="display:flex; align-items:center; margin-bottom: 20px; color: #c53030;">
                <i class='bx bx-error' style="font-size: 1.2rem; margin-right: 10px;"></i>
                <h4 style="font-weight:600;">Danger Zone</h4>
            </div>
            
            <div style="display:flex; flex-direction:column; gap:15px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <div style="font-weight:600; color: #9b2c2c;">Permanently Delete Account</div>
                        <div style="font-size:0.85rem; color: #c53030; opacity: 0.8; max-width: 300px; line-height: 1.5;">
                            This action cannot be undone. All profile data and photos will be permanently deleted.
                        </div>
                    </div>

                    <button type="button" onclick="checkSecurityAndExecute(openDeleteModal)" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#e53e3e; color:white; border:none; transition:0.2s;">
                        <i class='bx bx-trash' style="font-size: 1.2rem;"></i>
                    </button>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <div style="font-weight:600; color: #9b2c2c;">Change Password</div>
                        <div style="font-size:0.85rem; color: #c53030; opacity: 0.8; max-width: 300px; line-height: 1.5;">
                            Change your account password to a new one.
                        </div>
                    </div>

                    <button type="button" onclick="checkSecurityAndExecute(openVerifyPassModal)" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#e53e3e; color:white; border:none; transition:0.2s;">
                        <i class='bx bx-key' style="font-size: 1.2rem;"></i>
                    </button>
                </div>
            </div>

            
        </div>

        

        <div style="text-align:center; margin-top: 40px;">
            <a href="logout" class="btn btn-logout" style="display:inline-flex; align-items:center; justify-content: center; gap:8px; text-decoration:none; padding:12px 30px; border-radius:50px; font-weight:600;">
                <i class='bx bx-log-out'></i> Logout
            </a>
        </div>
    </div>
</div>

<div class="popup-overlay" id="cropModal" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box" style="width: 500px; max-width: 95%;">
        <h3 class="popup-title">Adjust Photo</h3>
        <p class="popup-message" style="margin-bottom:15px;">Drag and zoom the area you want to capture.</p>
        
        <div class="crop-container">
            <img id="image-to-crop" style="max-width: 100%; display: block;">
        </div>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" onclick="closeModal('cropModal')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
            <button type="button" onclick="cropImage()" class="popup-btn success">Save</button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="deleteModal" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box error">
            <i class='bx bx-trash'></i>
        </div>
        
        <h3 class="popup-title">Delete Account?</h3>
        <p class="popup-message">Are you sure? This action is permanent and cannot be undone.</p>
        
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" onclick="closeModal('deleteModal')" class="popup-btn">Cancel</button>
            
            <form method="POST" style="width:100%;">
                <button type="submit" name="delete_account" class="popup-btn error">Yes, Delete Permanently</button>
            </form>
        </div>
    </div>
</div>

<div class="popup-overlay" id="modalSetup2FA" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box" style="width: 400px; max-width: 95%;">
        <div class="popup-icon-box success"><i class='bx bx-qr-scan'></i></div>
        <h3 class="popup-title">Connect Authenticator</h3>
        <p class="popup-message" style="margin-bottom:15px;">Scan the QR Code or enter the code manually.</p>
        
        <div id="qrcode-container" style="background:#fff; padding:10px; border:1px solid #e5e7eb; display:inline-block; margin-bottom:10px;"></div>
        
        <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:15px;">
            Manual Code: <strong id="manual-secret-code" style="color:#000; font-family:monospace;">...</strong>
        </p>

        <div class="form-group">
            <input type="text" id="2fa_setup_input" class="form-control" placeholder="Enter 6-digit code" style="text-align:center; letter-spacing:2px; font-size:1.2rem;" maxlength="6">
            
            <p id="setup_2fa_error" style="color:#ef4444; font-size:0.85rem; margin-top:8px; display:none; font-weight:500;">
                Incorrect code!
            </p>
        </div>
        
        <div style="display:flex; gap:10px; margin-top:10px;">
            <button onclick="closeModal('modalSetup2FA')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
            <button onclick="processStep1Verify()" class="popup-btn success" id="btnStep1">Next</button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="modalBackupCode" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box warning"><i class='bx bx-save'></i></div>
        <h3 class="popup-title">Backup Code</h3>
        <p class="popup-message">Save this code in a safe place! It's the only way to recover your account if you lose your phone.</p>
        
        <div style="background:#f3f4f6; padding:15px; border-radius:8px; border:1px dashed #9ca3af; margin:15px 0; word-break: break-all;">
            <strong id="display_backup_code" style="font-family:monospace; font-size:1.1rem; color:#b45309;"></strong>
        </div>
        
        <button onclick="processStep2Backup()" class="popup-btn warning">I Have Saved It</button>
    </div>
</div>

<div class="popup-overlay" id="modalAuthName" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box success"><i class='bx bx-edit'></i></div>
        <h3 class="popup-title">Authenticator Name</h3>
        <p class="popup-message">Give a name to this authenticator (e.g., Google Authenticator, Proton Authenticator, etc).</p>
        
        <input type="text" id="auth_name_input" class="form-control" placeholder="Authenticator Name" style="margin-bottom:15px; text-align:center;">
        
        <button onclick="processStep3Finalize()" class="popup-btn success" id="btnStep3">Save & Activate</button>
    </div>
</div>

<div class="popup-overlay" id="modalDisable2FA" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box error"><i class='bx bx-lock-open'></i></div>
        <h3 class="popup-title">Disable Two-Step Verification?</h3>
        
        <p class="popup-message" id="msg_disable_otp">Enter the 6-digit code from your authenticator app to confirm.</p>
        <p class="popup-message" id="msg_disable_backup" style="display:none;">Enter your 32-character Backup Code to confirm.</p>
        
        <div class="form-group">
            <input type="text" id="2fa_disable_input" class="form-control" placeholder="000000" style="text-align:center; letter-spacing:3px; font-size:1.1rem;">
            
            <p id="disable_2fa_error" style="color:#ef4444; font-size:0.85rem; margin-top:8px; display:none; font-weight:500;">
                Incorrect code!
            </p>
        </div>
        
        <div style="margin-bottom: 15px; margin-top: -10px;">
            <a href="#" onclick="toggleBackupMode('disable')" id="link_disable_backup" style="font-size:0.85rem; color:var(--primary); font-weight:600; text-decoration:none;">
                Use Backup Code Instead
            </a>
        </div>
        
        <div style="display:flex; gap:10px;">
            <button onclick="closeModal('modalDisable2FA')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
            <button onclick="confirmDisable2FA()" class="popup-btn error" id="btnDisable2FA">Disable 2FA</button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="modalVerifyPass" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box warning"><i class='bx bx-lock-alt'></i></div>
        <h3 class="popup-title">Verification</h3>
        <p class="popup-message">Enter your current password to continue.</p>
        
        <input type="password" id="old_password_input" class="form-control" placeholder="Current Password" style="margin-bottom:15px; text-align:center;">
        <p id="error_msg_pass" style="color:red; font-size:0.85rem; display:none; margin-bottom:10px;"></p>

        <button onclick="checkOldPassword()" class="popup-btn warning" id="btnCheckPass">Continue</button>
        
        <div style="margin-top:15px; font-size:0.9rem; color:var(--text-muted);">
            Forgot password? <a href="#" onclick="switchToOTP()" style="color:var(--primary); font-weight:600;">Use Email OTP</a>
        </div>
        <button onclick="closeModal('modalVerifyPass')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Batal</button>
    </div>
</div>

<div class="popup-overlay" id="modalDeletePasskey" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box error">
            <i class='bx bx-trash'></i>
        </div>
        
        <h3 class="popup-title">Delete Passkey?</h3>
        <p class="popup-message">You will no longer be able to log in using this method on the associated device.</p>
        
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" onclick="closeModal('modalDeletePasskey')" class="popup-btn">Cancel</button>
            
            <form method="POST" style="width:100%;">
                <input type="hidden" name="pk_id" id="delete_pk_id_target">
                <button type="submit" name="delete_passkey" class="popup-btn error">Yes, Delete Passkey</button>
            </form>
        </div>
    </div>
</div>

<div class="popup-overlay" id="modalPasskeyName" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box success"><i class='bx bx-fingerprint'></i></div>
        <h3 class="popup-title">Name Your Passkey</h3>
        <p class="popup-message">Your passkey has been created! Give it a name so it’s easy to recognize (e.g., Google Password Manager, Proton Pass, YubiKey).</p>
        
        <input type="text" id="passkey_name_input" class="form-control" placeholder="Passkey Name (Optional)" style="margin-bottom:15px; text-align:center;">
        
        <button onclick="submitPasskeyData()" class="popup-btn success">Save</button>
        <button onclick="closeModal('modalPasskeyName')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Cancel</button>
    </div>
</div>

<div class="popup-overlay" id="modalVerifyOTP" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box warning"><i class='bx bx-envelope'></i></div>
        <h3 class="popup-title">OTP Code</h3>
        <p class="popup-message">We have sent a code to your email.</p>
        
        <input type="text" id="otp_input" class="form-control" placeholder="000000" style="margin-bottom:15px; text-align:center; letter-spacing:5px; font-size:1.2rem;">
        <p id="error_msg_otp" style="color:red; font-size:0.85rem; display:none; margin-bottom:10px;"></p>

        <button onclick="checkOTP()" class="popup-btn warning" id="btnCheckOTP">Verify OTP</button>
        
        <div style="margin-top:15px; font-size:0.9rem; color:var(--text-muted);">
            Didn't receive the code? 
            <span id="timer_display" style="color:var(--text-muted);">Resend in 60s</span>
            <a href="#" id="btn_resend" onclick="resendOTP()" style="display:none; color:var(--primary); font-weight:600;">Resend</a>
        </div>
        <button onclick="closeModal('modalVerifyOTP')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Cancel</button>
    </div>
</div>

<div class="popup-overlay" id="modalNewPass" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box success"><i class='bx bx-key'></i></div>
        <h3 class="popup-title">New Password</h3>
        <p class="popup-message">Please create your new password.</p>
        
        <form method="POST">
            <input type="password" id="new_password_input" name="new_password" class="form-control" placeholder="New Password" required style="margin-bottom:10px; text-align:center;">
            
            <div class="password-requirements" id="pwd-req-box-modal" style="text-align:left; background:#f9fafb; padding:10px; border-radius:8px; border:1px solid #e5e7eb; margin-bottom:15px; font-size:0.85rem;">

                <div class="req-item invalid" id="req-len" style="margin-bottom:2px;"><i class='bx bx-x'></i> 6+ Characters</div>
                <div class="req-item invalid" id="req-upper" style="margin-bottom:2px;"><i class='bx bx-x'></i> Uppercase Letters (A-Z)</div>
                <div class="req-item invalid" id="req-num" style="margin-bottom:2px;"><i class='bx bx-x'></i> Numbers (0-9)</div>
                <div class="req-item invalid" id="req-sym" style="margin-bottom:2px;"><i class='bx bx-x'></i> Symbols (!@#$)</div>

            </div>

            <button type="submit" id="btnSavePass" name="save_new_password" class="popup-btn success" disabled style="opacity:0.6; cursor:not-allowed;">Save Password</button>
        </form>
        <button onclick="closeModal('modalNewPass')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Cancel</button>
    </div>
</div>

<div class="popup-overlay" id="modalGenericError" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box error">
            <i class='bx bx-error-circle'></i>
        </div>
        <h3 class="popup-title">Attention</h3>
        <p class="popup-message" id="generic_error_text">An error occurred.</p>
        
        <button onclick="closeModal('modalGenericError')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer;">Close</button>
    </div>
</div>

<div class="popup-overlay" id="modalLogs" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box" style="width: 600px; max-width: 95%;">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <div style="display:flex; align-items:center; justify-content:center; gap:10px;">
                <h3 class="popup-title" style="margin:0;"><i class='bx bx-history'></i> Account Logs</h3>
            </div>

            <form method="GET" style="margin:0;">
                <select name="logs_limit" onchange="this.form.submit()" class="logs-select">
                    <?php 
                    // Ambil nilai limit dari URL, default 50
                    $curr_limit = isset($_GET['logs_limit']) ? $_GET['logs_limit'] : '50'; 
                    ?>
                    <option value="50" <?php if($curr_limit == '50') echo 'selected'; ?>>Last 50</option>
                    <option value="100" <?php if($curr_limit == '100') echo 'selected'; ?>>Last 100</option>
                    <option value="all" <?php if($curr_limit == 'all') echo 'selected'; ?>>Show All</option>
                </select>
            </form>
        </div>

        <div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px;">
            <table style="width:100%; border-collapse: separate; border-spacing: 0 10px; padding: 0 10px; font-size: 0.85rem;">
                
                <thead style="position: sticky; top: 0; background: white; z-index: 1;">
                    <tr>
                        <th style="padding: 5px 10px; text-align: center; color:var(--text-muted); font-weight:500; font-size:0.8rem; width: 30%;">Time</th>
                        <th style="padding: 5px 10px; text-align: center; color:var(--text-muted); font-weight:500; font-size:0.8rem;">Activity</th>
                    </tr>
                </thead>
                
                <tbody>
                    <?php
                    // LOGIKA SQL LIMIT DINAMIS
                    $limit_sql = "LIMIT 50"; // Default
                    if ($curr_limit == '100') {
                        $limit_sql = "LIMIT 100";
                    } elseif ($curr_limit == 'all') {
                        $limit_sql = ""; // Tidak ada limit
                    }

                    $log_res = $conn->query("SELECT * FROM logsuser WHERE id_user='$user_id' ORDER BY id DESC $limit_sql");
                    
                    if ($log_res && $log_res->num_rows > 0):
                        while($log = $log_res->fetch_assoc()):
                            $dateDisplay = isset($log['created_at']) ? date('d M Y, H:i', strtotime($log['created_at'])) : '-';
                    ?>
                    <tr style="vertical-align: middle;">
                        <td style="padding-right: 15px;">
                            <div style="background: #b45309; color: #fff; padding: 10px 5px; border-radius: 6px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); font-weight: 600; font-size: 0.8rem;">
                                <?php echo $dateDisplay; ?>
                            </div>
                        </td>

                        <td style="padding: 10px; color:var(--text-main); border-bottom: 1px solid #f3f4f6; line-height: 1.5;">
                            <?php echo htmlspecialchars($log['behaviour']); ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="2" style="padding: 30px; text-align: center; color:var(--text-muted);">
                            <i class='bx bx-ghost' style="font-size: 2rem; margin-bottom: 10px; display:block;"></i>
                            Belum ada aktivitas tercatat.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="display:flex; gap:10px; margin-top: 20px;">
            <form method="POST" style="flex: 1;" id="csvForm">
                <button type="button" onclick="submitCSVForm()" id="btnSendCSV" class="popup-btn" style="background:#000; color:white; border:none; display:flex; align-items:center; justify-content: center; gap:8px; width: 100%;">
                    <i class='bx bx-envelope'></i> 
                    <span id="csvButtonText">Export CSV to Email</span>
                    <i class='bx bx-loader-alt bx-spin' id="csvLoadingIcon" style="display:none; font-size:1.2rem;"></i>
                </button>
                <input type="hidden" name="send_logs_email" value="1">
            </form>
            
            <button onclick="closeModal('modalLogs')" class="popup-btn" style="flex: 1; background:#f3f4f6; color:#111; border: 1px solid #e5e7eb;">
                Close
            </button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="modalVerify2FAAction" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box warning"><i class='bx bx-shield-quarter'></i></div>
        <h3 class="popup-title">Two-Step Verification</h3>
        
        <p class="popup-message" id="msg_action_otp">For security reasons, an additional verification is required because an authenticator is enabled on your account. Please enter the verification code to continue.</p>
        <p class="popup-message" id="msg_action_backup" style="display:none;">Enter your 32-character Backup Code.</p>
        
        <input type="text" id="2fa_action_input" class="form-control" placeholder="000000" style="text-align:center; letter-spacing:3px; font-size:1.1rem; margin-bottom:10px;">
        
        <p id="action_2fa_error" style="color:#ef4444; font-size:0.85rem; margin-bottom:10px; display:none; font-weight:500;">
            Incorrect code!
        </p>

        <div style="margin-bottom: 20px;">
            <a href="#" onclick="toggleBackupMode('action')" id="link_action_backup" style="font-size:0.85rem; color:var(--primary); font-weight:600; text-decoration:none;">
                Use Backup Code Instead
            </a>
        </div>

        <div style="display:flex; gap:10px;">
            <button onclick="closeModal('modalVerify2FAAction')" class="popup-btn" style="background:#f3f4f6; color:#111;">Cancel</button>
            <button onclick="submit2FAAction()" class="popup-btn warning" id="btnAction2FA">Verify</button>
        </div>
    </div>
</div>

<script src="webauthn.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
    const is2FAEnabled = <?php echo $user_data['is_2fa_enabled'] ? 'true' : 'false'; ?>;
    let pendingAction = null;

    let cropper;
    const fileInput = document.getElementById('hidden-file-input');
    const imageToCrop = document.getElementById('image-to-crop');
    const cropModal = document.getElementById('cropModal');

    fileInput.addEventListener('change', function(e) {
        const files = e.target.files;
        if (files && files.length > 0) {
            const file = files[0];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                imageToCrop.src = e.target.result;
                openModal('cropModal');

                if(cropper) cropper.destroy();
                cropper = new Cropper(imageToCrop, {
                    aspectRatio: 1, viewMode: 1, autoCropArea: 1
                });
            };
            reader.readAsDataURL(file);
        }
        this.value = null;
    });

    function cropImage() {
        const canvas = cropper.getCroppedCanvas({ width: 300, height: 300 });
        const base64Image = canvas.toDataURL("image/webp");

        const mainPreview = document.getElementById('main-preview');
        const placeholder = document.getElementById('main-preview-placeholder');
        
        mainPreview.src = base64Image;
        mainPreview.style.display = 'block';
        if(placeholder) placeholder.style.display = 'none';

        document.getElementById('cropped_image_data').value = base64Image;
        closeModal('cropModal')

        // 1. Ambil elemen form
        const form = document.getElementById('profileForm');
        
        // 2. Buat input rahasia (hidden) agar PHP tahu ini adalah aksi 'update_profile'
        // (Karena kalau submit via JS, tombol submit asli tidak dianggap ditekan)
        const hiddenSubmit = document.createElement('input');
        hiddenSubmit.type = 'hidden';
        hiddenSubmit.name = 'update_profile'; // Nama ini wajib sama dengan cek di PHP: isset($_POST['update_profile'])
        hiddenSubmit.value = '1';
        form.appendChild(hiddenSubmit);

        // 3. Kirim form secara otomatis ke server
        form.submit();
    }

    // --- (PERUBAHAN 3: JS DELETE MODAL) ---
    const deleteModal = document.getElementById('deleteModal');

    function openDeleteModal() {
        openModal('deleteModal');
        setTimeout(() => deleteModal.style.opacity = '1', 10);
    }

    function closeDeleteModal() {
        deleteModal.style.opacity = '0';
        setTimeout(() => {
            deleteModal.style.display = 'none';
        }, 300);
    }

    // --- FUNGSI UMUM BUKA/TUTUP MODAL ---
    function openModal(id) {
        const el = document.getElementById(id);

        // Siapkan posisi awal animasi
        el.style.display = 'flex';
        el.style.opacity = '0';
        el.style.backdropFilter = 'blur(0px)';

        const box = el.querySelector('.popup-box');
        box.style.transform = 'scale(0.92) translateY(10px)';
        box.style.opacity = '0';

        // Trigger animasi
        requestAnimationFrame(() => {
            el.style.transition = 'opacity .35s ease, backdrop-filter .45s ease';
            el.style.opacity = '1';
            el.style.backdropFilter = 'blur(20px)';

            box.style.transition =
                'transform .55s cubic-bezier(0.16, 1, 0.3, 1), opacity .35s ease';
            box.style.transform = 'scale(1) translateY(0)';
            box.style.opacity = '1';
        });
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        const box = el.querySelector('.popup-box');

        // Animasi keluar
        el.style.opacity = '0';
        el.style.backdropFilter = 'blur(0px)';
        box.style.transform = 'scale(0.93) translateY(12px)';
        box.style.opacity = '0';

        if (id === 'modalVerify2FAAction') resetInputToOTP('action');
        if (id === 'modalDisable2FA') resetInputToOTP('disable');

        // Setelah animasi selesai → sembunyikan
        setTimeout(() => {
            el.style.display = 'none';
        }, 350);
    }


    // 1. Tombol Ganti Password Ditekan
    function openVerifyPassModal() {
        openModal('modalVerifyPass');
    }

    // 2. Cek Password Lama via AJAX
    function checkOldPassword() {
        const pass = document.getElementById('old_password_input').value;
        const btn = document.getElementById('btnCheckPass');
        const errMsg = document.getElementById('error_msg_pass');

        if(!pass) return;

        btn.innerText = "Checking..."; btn.disabled = true;
        
        const formData = new FormData();
        formData.append('ajax_action', 'verify_old_password');
        formData.append('old_password', pass);

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.innerText = "Lanjutkan"; btn.disabled = false;
            if(data.status === 'success') {
                closeModal('modalVerifyPass');
                openModal('modalNewPass'); // Buka Modal Password Baru
            } else {
                errMsg.innerText = data.message;
                errMsg.style.display = 'block';
            }
        });
    }

    let resendInterval;

    // 3. Pindah ke Mode OTP (Kirim OTP dulu)
    function switchToOTP() {
        const btn = document.getElementById('btnCheckPass'); 
        btn.innerText = "Mengirim OTP..."; btn.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'send_otp_pass');

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.innerText = "Lanjutkan"; btn.disabled = false;
            
            if(data.status === 'success') {
                closeModal('modalVerifyPass');
                openModal('modalVerifyOTP');
                
                // HAPUS ALERT, Ganti dengan Timer
                // Ambil waktu tunggu dari PHP (default 60s jika undefined)
                let waitTime = data.next_wait ? data.next_wait : 60;
                startCountdown(waitTime); 
            } else {
                // Jika error karena cooldown (misal user refresh page)
                if(data.wait) {
                    closeModal('modalVerifyPass');
                    openModal('modalVerifyOTP');
                    startCountdown(data.wait);
                    document.getElementById('error_msg_otp').innerText = data.message;
                    document.getElementById('error_msg_otp').style.display = 'block';
                } else {
                    alert(data.message);
                }
            }
        });
    }

    // FUNGSI BARU: KIRIM ULANG OTP
    function resendOTP() {
        const link = document.getElementById('btn_resend');
        const timerText = document.getElementById('timer_display');
        
        link.style.display = 'none';
        timerText.style.display = 'inline';
        timerText.innerText = "Mengirim...";

        const formData = new FormData();
        formData.append('ajax_action', 'send_otp_pass');

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                // Reset Timer dengan waktu baru (backoff multiplier)
                startCountdown(data.next_wait);
            } else {
                // Jika kena limit 1 jam atau error lain
                alert(data.message);
                if(data.wait) {
                   startCountdown(data.wait);
                } else {
                   link.style.display = 'inline'; // Munculkan lagi jika gagal bukan karena limit
                   timerText.style.display = 'none';
                }
            }
        });
    }

    // FUNGSI BARU: HITUNG MUNDUR
    function startCountdown(seconds) {
        const link = document.getElementById('btn_resend');
        const timerText = document.getElementById('timer_display');
        
        link.style.display = 'none';
        timerText.style.display = 'inline';
        
        let timeLeft = seconds;
        
        // Hapus interval sebelumnya jika ada agar tidak bentrok
        if(resendInterval) clearInterval(resendInterval);

        timerText.innerText = `Kirim ulang dalam ${timeLeft}s`;

        resendInterval = setInterval(() => {
            timeLeft--;
            
            if(timeLeft > 0) {
                 // Format waktu jika > 60 detik (misal 1 jam cooldown)
                 if(timeLeft > 60) {
                     let minutes = Math.floor(timeLeft / 60);
                     let secs = timeLeft % 60;
                     timerText.innerText = `Tunggu ${minutes}m ${secs}s`;
                 } else {
                     timerText.innerText = `Kirim ulang dalam ${timeLeft}s`;
                 }
            } else {
                clearInterval(resendInterval);
                timerText.style.display = 'none';
                link.style.display = 'inline'; // Munculkan tombol kirim ulang
            }
        }, 1000);
    }

    // 4. Cek Kode OTP via AJAX
    function checkOTP() {
        const code = document.getElementById('otp_input').value;
        const btn = document.getElementById('btnCheckOTP');
        const errMsg = document.getElementById('error_msg_otp');

        if(!code) return;

        btn.innerText = "Memverifikasi..."; btn.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'verify_otp_pass');
        formData.append('otp_code', code);

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.innerText = "Verifikasi OTP"; btn.disabled = false;
            if(data.status === 'success') {
                closeModal('modalVerifyOTP');
                openModal('modalNewPass'); // Buka Modal Password Baru
            } else {
                errMsg.innerText = data.message;
                errMsg.style.display = 'block';
            }
        });
    }

    // --- FINAL PASSWORD VALIDATION SCRIPT (MATCH REGISTER.PHP STYLE) ---

    const newPassInput = document.getElementById('new_password_input');
    const reqBoxModal  = document.getElementById('pwd-req-box-modal');
    const btnSavePass  = document.getElementById('btnSavePass');

    // Event listeners
    newPassInput.addEventListener('focus', function() { checkPwd(this.value); });
    newPassInput.addEventListener('keyup', function() { checkPwd(this.value); });
    newPassInput.addEventListener('blur', function() { 
        reqBoxModal.classList.remove("show");
    });

    // Main checker
    function checkPwd(val) {
        const isLen   = val.length >= 6;
        const isUpper = /[A-Z]/.test(val);
        const isNum   = /[0-9]/.test(val);
        const isSym   = /[^\w]/.test(val);

        updateReqUI("req-len", isLen);
        updateReqUI("req-upper", isUpper);
        updateReqUI("req-num", isNum);
        updateReqUI("req-sym", isSym);

        const isValid = isLen && isUpper && isNum && isSym;

        // Enable / Disable button
        if (isValid) {
            btnSavePass.disabled = false;
            btnSavePass.style.opacity = "1";
            btnSavePass.style.cursor = "pointer";
        } else {
            btnSavePass.disabled = true;
            btnSavePass.style.opacity = "0.6";
            btnSavePass.style.cursor = "not-allowed";
        }

        // Show / hide requirement box
        if (val.length === 0 || isValid) {
            reqBoxModal.classList.remove("show");
        } else {
            reqBoxModal.classList.add("show");
        }
    }

    // Matches register.php style exactly
    function updateReqUI(id, isValid) {
        const el = document.getElementById(id);
        const icon = el.querySelector("i");

        if (isValid) {
            el.classList.add("valid");
            el.classList.remove("invalid");
            icon.className = "bx bx-check"; // centang hijau
        } else {
            el.classList.add("invalid");
            el.classList.remove("valid");
            icon.className = "bx bx-x"; // X sederhana
        }
    }

    function openDeletePasskey(id) {
        // 1. Masukkan ID yang diklik ke dalam input hidden di modal
        document.getElementById('delete_pk_id_target').value = id;
        
        // 2. Buka Modal
        openModal('modalDeletePasskey');
    }

    function showError(message) {
        // Filter pesan error teknis agar lebih ramah (Opsional)
        if (message.includes("timed out") || message.includes("not allowed")) {
            message = "Proses dibatalkan atau waktu habis.";
        }
        
        document.getElementById('generic_error_text').innerText = message;
        openModal('modalGenericError');
    }

    function openLogsModal() {
        openModal('modalLogs');
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('logs_limit')) {
        // Hapus parameter dari URL agar bersih (opsional, tapi bagus untuk UX)
        window.history.replaceState({}, document.title, window.location.pathname);
        
        // Buka modal secara otomatis
        openLogsModal();
    }

    // Tambahkan di dalam tag <script> Anda:

    function submitCSVForm() {
        const form = document.getElementById('csvForm');
        const button = document.getElementById('btnSendCSV');
        const textSpan = document.getElementById('csvButtonText');
        const loadingIcon = document.getElementById('csvLoadingIcon');
        
        // 1. Tampilkan status loading
        button.disabled = true;
        button.style.opacity = '0.7';
        textSpan.innerText = 'Mengirim...';
        loadingIcon.style.display = 'inline-block';
        
        // 2. Kirim form (PHP akan me-redirect setelah selesai)
        form.submit();
        
        // Catatan: Karena PHP melakukan redirect (header("Location:...")), 
        // kita tidak perlu logika 'success' di JS. Halaman akan reload
        // dan menampilkan popup status (success/error) dari PHP.
    }

    // --- FUNGSI ACCORDION BARU ---
    function toggleAccordion(headerId) {
        const header = document.getElementById(headerId);
        const contentId = headerId.replace('header', 'content');
        const content = document.getElementById(contentId);

        const isOpen = content.classList.contains('open');

        if (isOpen) {
            // CLOSE
            const fullHeight = content.scrollHeight;
            content.style.height = fullHeight + "px"; 

            requestAnimationFrame(() => {
                content.style.height = "0px";
                content.style.opacity = "0";
                header.classList.remove("open");
            });

            content.addEventListener("transitionend", () => {
                if (!content.classList.contains("open")) {
                    content.style.height = "";
                }
            }, { once: true });

            content.classList.remove("open");

        } else {
            // OPEN
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

    // --- STEP 1: BUKA SETUP & GENERATE QR ---
    function open2FAModal() {
        openModal('modalSetup2FA');
        document.getElementById('qrcode-container').innerHTML = "Loading...";
        document.getElementById('2fa_setup_input').value = "";
        
        // RESET ERROR (Sembunyikan pesan error saat modal dibuka)
        document.getElementById('setup_2fa_error').style.display = 'none'; 
        
        const formData = new FormData();
        formData.append('ajax_action', 'generate_2fa');

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                document.getElementById('qrcode-container').innerHTML = "";
                new QRCode(document.getElementById("qrcode-container"), {
                    text: data.qr_url,
                    width: 150,
                    height: 150
                });
                document.getElementById('manual-secret-code').innerText = data.secret;
            } else {
                alert(data.message); // Error server tetap alert saja (jarang terjadi)
                closeModal('modalSetup2FA');
            }
        });
    }

    // --- STEP 2: VERIFIKASI KODE -> TAMPILKAN BACKUP CODE ---
    function processStep1Verify() {
        const codeInput = document.getElementById('2fa_setup_input');
        const code = codeInput.value;
        const btn = document.getElementById('btnStep1');
        const errorMsg = document.getElementById('setup_2fa_error');

        // Reset Error dulu
        errorMsg.style.display = 'none';

        if(code.length < 6) { 
            errorMsg.innerText = "Enter the 6-digit code.";
            errorMsg.style.display = 'block';
            return; 
        }
        
        btn.innerText = "Checking..."; btn.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'verify_2fa_temp');
        formData.append('otp_code', code);

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.innerText = "Continue"; btn.disabled = false;
            
            if(data.status === 'success') {
                // Kode Benar -> Lanjut ke step berikutnya
                closeModal('modalSetup2FA');
                document.getElementById('display_backup_code').innerText = data.backup_code;
                openModal('modalBackupCode');
            } else {
                // KODE SALAH -> Tampilkan di element <p>
                errorMsg.innerText = data.message; // "Kode salah!"
                errorMsg.style.display = 'block';
                codeInput.value = ""; // Kosongkan input agar user bisa ketik ulang
                codeInput.focus();    // Fokuskan kursor kembali
            }
        });
    }

    // --- STEP 3: DARI BACKUP CODE KE NAMING ---
    function processStep2Backup() {
        closeModal('modalBackupCode');
        document.getElementById('auth_name_input').value = "";
        openModal('modalAuthName');
        setTimeout(() => document.getElementById('auth_name_input').focus(), 100);
    }

    // --- STEP 4: FINALISASI (SIMPAN NAMA & DATA) ---
    function processStep3Finalize() {
        const name = document.getElementById('auth_name_input').value;
        const btn = document.getElementById('btnStep3');
        
        btn.innerText = "Menyimpan..."; btn.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'finalize_2fa');
        formData.append('auth_name', name);

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                closeModal('modalAuthName');
                // RELOAD HALAMAN (Popup Success akan muncul dari Session PHP)
                location.reload(); 
            } else {
                alert(data.message);
                btn.innerText = "Simpan & Aktifkan"; btn.disabled = false;
            }
        });
    }

    // --- DISABLE 2FA: BUKA MODAL INPUT ---
    function disable2FA() {
        document.getElementById('2fa_disable_input').value = "";
        // Sembunyikan error jika sebelumnya pernah muncul
        document.getElementById('disable_2fa_error').style.display = 'none'; 
        openModal('modalDisable2FA');
    }

    function checkSecurityAndExecute(actionCallback) {
        if (is2FAEnabled) {
            // Jika 2FA aktif, tahan aksi dan buka modal verifikasi
            pendingAction = actionCallback;
            document.getElementById('2fa_action_input').value = "";
            document.getElementById('action_2fa_error').style.display = 'none';
            openModal('modalVerify2FAAction');
        } else {
            // Jika tidak aktif, langsung jalankan aksi (buka modal hapus/ganti pass)
            actionCallback();
        }
    }

    function toggleBackupMode(type) {
        // type bisa 'action' (security check) atau 'disable' (matikan 2fa)
        
        const input = document.getElementById(`2fa_${type}_input`);
        const link = document.getElementById(`link_${type}_backup`);
        const msgOtp = document.getElementById(`msg_${type}_otp`);
        const msgBackup = document.getElementById(`msg_${type}_backup`);
        
        // Cek mode saat ini berdasarkan placeholder
        const isOtpMode = input.placeholder === "000000";
        
        if (isOtpMode) {
            // Switch ke Backup Mode
            input.placeholder = "Masukkan Kode Backup";
            input.maxLength = 32;
            input.style.letterSpacing = "1px";
            input.style.fontSize = "0.95rem"; // Kecilkan font biar muat
            input.value = "";
            
            link.innerText = "Gunakan Authenticator App";
            msgOtp.style.display = "none";
            msgBackup.style.display = "block";
        } else {
            // Switch balik ke OTP Mode
            input.placeholder = "000000";
            input.maxLength = 6;
            input.style.letterSpacing = "5px"; // Balikin spasi lebar
            input.style.fontSize = "1.2rem";
            input.value = "";
            
            link.innerText = "Gunakan Kode Backup";
            msgOtp.style.display = "block";
            msgBackup.style.display = "none";
        }
    }

    function submit2FAAction() {
        const input = document.getElementById('2fa_action_input');
        const code = input.value.trim();
        const btn = document.getElementById('btnAction2FA');
        const errorMsg = document.getElementById('action_2fa_error');

        errorMsg.style.display = 'none';

        // Validasi Panjang: Harus 6 (OTP) atau 32 (Backup)
        if(code.length !== 6 && code.length !== 32) {
            errorMsg.innerText = "Kode tidak valid (Harus 6 digit atau 32 karakter backup).";
            errorMsg.style.display = 'block';
            return;
        }

        btn.innerText = "Checking..."; btn.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'verify_2fa_general');
        formData.append('otp_code', code);

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.innerText = "Verifikasi"; btn.disabled = false;
            
            if(data.status === 'success') {
                closeModal('modalVerify2FAAction');
                // Reset mode tampilan ke default (OTP) untuk pemakaian berikutnya
                resetInputToOTP('action');
                
                if (typeof pendingAction === 'function') {
                    pendingAction();
                    pendingAction = null; 
                }
            } else {
                errorMsg.innerText = data.message;
                errorMsg.style.display = 'block';
                input.value = "";
                input.focus();
            }
        });
    }
    function confirmDisable2FA() {
        const input = document.getElementById('2fa_disable_input');
        const code = input.value.trim();
        const btn = document.getElementById('btnDisable2FA');
        const errorMsg = document.getElementById('disable_2fa_error');

        errorMsg.style.display = 'none';

        if(code.length !== 6 && code.length !== 32) {
            errorMsg.innerText = "Kode tidak valid.";
            errorMsg.style.display = 'block';
            return;
        }

        btn.innerText = "Memproses..."; btn.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'disable_2fa_secure');
        formData.append('otp_code', code);

        fetch('./', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                location.reload(); 
            } else {
                errorMsg.innerText = data.message;
                errorMsg.style.display = 'block';
                btn.innerText = "Matikan"; btn.disabled = false;
                input.value = ""; 
                input.focus();
            }
        });
    }

    function resetInputToOTP(type) {
        const input = document.getElementById(`2fa_${type}_input`);
        const link = document.getElementById(`link_${type}_backup`);
        const msgOtp = document.getElementById(`msg_${type}_otp`);
        const msgBackup = document.getElementById(`msg_${type}_backup`);
        
        input.placeholder = "000000";
        input.maxLength = 6;
        input.style.letterSpacing = "5px";
        input.style.fontSize = "1.2rem";
        input.value = "";
        
        link.innerText = "Gunakan Kode Backup";
        msgOtp.style.display = "block";
        msgBackup.style.display = "none";
    }

    function submitDownloadForm() {
        // Submit form secara program setelah lolos verifikasi 2FA
        document.getElementById('formDownloadData').submit();
    }


</script>

<?php include 'popupcustom.php'; ?>
</body>
</html>