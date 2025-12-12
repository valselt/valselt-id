<?php
require 'config.php';

if (!isset($_SESSION['valselt_user_id'])) {
    header("Location: login.php"); exit();
}

$user_id = $_SESSION['valselt_user_id'];

// --- AJAX HANDLER UNTUK GANTI PASSWORD ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $uid = $_SESSION['valselt_user_id'];
    $response = ['status' => 'error', 'message' => 'Terjadi kesalahan'];

    if ($_POST['ajax_action'] == 'verify_old_password') {
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
    header("Location: index.php"); exit();
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
    header("Location: index.php");
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
    header("Location: index.php"); exit();
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

$u_res = $conn->query("SELECT * FROM users WHERE id='$user_id'");
$user_data = $u_res->fetch_assoc();
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
</head>
<body style="background:#f9fafb;"> <div class="valselt-container">
    <div class="valselt-header">
        <img src="https://cdn.ivanaldorino.web.id/valselt/valselt_black.png" alt="Valselt" class="logo-dashboard">
        <p style="color:var(--text-muted);">Pusat Pengaturan Akun</p>
    </div>

    <div class="profile-card">
        <form action="index.php" method="POST" id="profileForm">
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

                <button type="submit" name="update_profile" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>

        <div style="background: #f9fafb; padding: 20px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #e5e7eb; margin-top:40px;">
            <h4 style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center;">
                <i class='bx bx-user' style="margin-right:10px; font-size:1.2rem;"></i>Linked Accounts
            </h4>
            
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center;">
                    <img src="https://www.svgrepo.com/show/475656/google-color.svg" style="width:24px; margin-right:12px;">
                    <div>
                        <div style="font-weight:500;">Google</div>
                        <div style="font-size:0.85rem; color:var(--text-muted);">
                            <?php if($user_data['google_id']): ?>
                                Terhubung
                            <?php else: ?>
                                Tidak terhubung
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if($user_data['google_id']): ?>
                    <button disabled class="btn" style="width:auto; padding: 8px 16px; font-size:0.9rem; background:#dcfce7; color:#166534; cursor:default;">
                        <i class='bx bx-check'></i> Linked
                    </button>
                <?php else: ?>
                    <a href="<?php echo $google_client->createAuthUrl(); ?>" class="btn" style="width:auto; padding: 8px 16px; font-size:0.9rem; background:white; border:1px solid #d1d5db;">
                        Link Account
                    </a>
                <?php endif; ?>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px;">
                <div style="display:flex; align-items:center;">
                    <i class='bx bxl-github' style="font-size:28px; margin-right:12px; color:#333;"></i>
                    <div>
                        <div style="font-weight:500;">GitHub</div>
                        <div style="font-size:0.85rem; color:var(--text-muted);">
                            <?php if($user_data['github_id']): ?>
                                Terhubung
                            <?php else: ?>
                                Tidak terhubung
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if($user_data['github_id']): ?>
                    <button disabled class="btn" style="width:auto; padding: 8px 16px; font-size:0.9rem; background:#dcfce7; color:#166534; cursor:default;">
                        <i class='bx bx-check'></i> Linked
                    </button>
                <?php else: ?>
                    <?php 
                    $github_link_url = "https://github.com/login/oauth/authorize?client_id=" . $github_client_id . "&scope=user:email"; 
                    ?>
                    <a href="<?php echo $github_link_url; ?>" class="btn" style="width:auto; padding: 8px 16px; font-size:0.9rem; background:white; border:1px solid #d1d5db;">
                        Link Account
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div style="background: white; border: 1px solid #e5e7eb; padding: 20px; border-radius: 12px; margin-top: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.02);">
            <div style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center; justify-content:space-between;" class="passkey-title">
                <div class="passkey-header" style="display:flex; flex-direction:row; align-items:center;">
                    <i class='bx bx-shield' style="margin-right:10px; font-size:1.2rem;"></i><h4>Passkey</h4>
                </div>

                <button onclick="registerPasskey()" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#000; color:white;">
                    <i class='bx bx-plus'></i>
                </button>
            </div>

            <div class="passkey-list">
                <?php
                // AMBIL DATA PASSKEY
                $q_pk = $conn->query("SELECT * FROM user_passkeys WHERE user_id='$user_id' ORDER BY created_at DESC");
                
                if ($q_pk->num_rows > 0):
                    while($pk = $q_pk->fetch_assoc()):
                        $pk_date = date('d M Y, H:i', strtotime($pk['created_at']));
                        $source = $pk['credential_source'] ? htmlspecialchars($pk['credential_source']) : 'Passkey Credential';
                        
                        // Tentukan Icon & Warna Berdasarkan Nama
                        $iconClass = 'bx-key';
                        $bgColor = '#e0f2fe'; // Default Biru
                        $iconColor = '#0284c7';

                        if (stripos($source, 'Google') !== false || stripos($source, 'Android') !== false) {
                            $iconClass = 'bxl-google';
                            $bgColor = '#dcfce7'; // Hijau
                            $iconColor = '#166534';
                        } elseif (stripos($source, 'iCloud') !== false || stripos($source, 'Apple') !== false) {
                            $iconClass = 'bxl-apple';
                            $bgColor = '#f3f4f6'; // Abu
                            $iconColor = '#1f2937';
                        } elseif (stripos($source, 'Windows') !== false) {
                            $iconClass = 'bxl-windows';
                            $bgColor = '#dbeafe'; // Biru Win
                            $iconColor = '#2563eb';
                        }
                ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6;">
                        <div style="display:flex; align-items:center;">
                            <div style="width:40px; height:40px; background:<?php echo $bgColor; ?>; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-right:15px; color:<?php echo $iconColor; ?>;">
                                <i class='bx <?php echo $iconClass; ?>' style="font-size:1.4rem;"></i>
                            </div>
                            
                            <div>
                                <div style="font-weight:600; font-size:0.95rem; color:var(--text-main);">
                                    <?php echo $source; ?>
                                </div>
                                <div style="font-size:0.8rem; color:var(--text-muted);">
                                    Dibuat: <?php echo $pk_date; ?>
                                </div>
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
                        Belum ada Passkey Tersimpan. Klik "+" untuk menambahkannya.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="background: white; border: 1px solid #e5e7eb; padding: 20px; border-radius: 12px; margin-top: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.02);">
            <h4 style="margin-bottom: 20px; font-weight:600; display:flex; align-items:center;">
                <i class='bx bx-devices' style="margin-right:10px; font-size:1.2rem;"></i> Devices
            </h4>

            <?php
            $current_session = session_id();
            $q_dev = $conn->query("SELECT * FROM user_devices WHERE user_id='$user_id' ORDER BY (session_id = '$current_session') DESC, last_login DESC");
            
            if ($q_dev->num_rows > 0):
                while($dev = $q_dev->fetch_assoc()):
                    $is_current = ($dev['session_id'] == $current_session);
                    
                    // Tentukan Icon
                    $icon = 'bx-laptop'; 
                    if (stripos($dev['device_name'], 'Android') !== false || stripos($dev['device_name'], 'iPhone') !== false) {
                        $icon = 'bx-mobile';
                    }
            ?>
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6;">
                <div style="display:flex; align-items:center;">
                    <div style="width:40px; height:40px; background:#f3f4f6; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-right:15px; color:var(--primary);">
                        <i class='bx <?php echo $icon; ?>' style="font-size:1.2rem;"></i>
                    </div>
                    
                    <div>
                        <div style="font-weight:600; font-size:0.95rem; color:var(--text-main); display: flex; align-items: center;">
                            <?php echo htmlspecialchars($dev['device_name']); ?>
                            
                            <?php if($is_current): ?>
                                <span style="background:#dcfce7; color:#166534; font-size:0.7rem; padding:2px 8px; border-radius:10px; margin-left:8px; border: 1px solid #bbf7d0;">
                                    This Device
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.8rem; color:var(--text-muted);">
                            <?php echo date('d M Y, H:i', strtotime($dev['last_login'])); ?> • IP: <?php echo htmlspecialchars($dev['ip_address']); ?>
                        </div>
                    </div>
                </div>
                
                <?php if(!$is_current): ?>
                    <?php endif; ?>
            </div>

            <?php endwhile; else: ?>
                <p style="color:var(--text-muted); font-size:0.9rem; text-align:center;">Belum ada data perangkat tersimpan. Silakan Logout dan Login kembali.</p>
            <?php endif; ?>
        </div>

        <hr style="border:0; border-top:1px solid #e5e7eb; margin:40px 0;">

        <div style="background: #fff5f5; padding: 25px; border-radius: 12px; border: 1px solid #fed7d7;">
            
            <div style="display:flex; align-items:center; margin-bottom: 20px; color: #c53030;">
                <i class='bx bx-error' style="font-size: 1.2rem; margin-right: 10px;"></i>
                <h4 style="font-weight:600;">Danger Zone</h4>
            </div>
            
            <div style="display:flex; flex-direction:column; gap:15px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <div style="font-weight:600; color: #9b2c2c;">Hapus Akun Permanen</div>
                        <div style="font-size:0.85rem; color: #c53030; opacity: 0.8; max-width: 300px; line-height: 1.5;">
                            Tindakan ini tidak dapat dibatalkan. Semua data profil dan foto akan hilang selamanya.
                        </div>
                    </div>

                    <button type="button" onclick="openDeleteModal()" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#e53e3e; color:white; border:none; transition:0.2s;">
                        <i class='bx bx-trash' style="font-size: 1.2rem;"></i>
                    </button>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <div style="font-weight:600; color: #9b2c2c;">Ganti Password</div>
                        <div style="font-size:0.85rem; color: #c53030; opacity: 0.8; max-width: 300px; line-height: 1.5;">
                            Ganti Password Akun Anda dengan yang baru.
                        </div>
                    </div>

                    <button type="button" onclick="openVerifyPassModal()" class="btn" style="width:auto; padding: 10px; font-size:0.9rem; background:#e53e3e; color:white; border:none; transition:0.2s;">
                        <i class='bx bx-key' style="font-size: 1.2rem;"></i>
                    </button>
                </div>
            </div>

            
        </div>

        <div style="text-align:center; margin-top: 40px;">
            <a href="logout.php" class="btn btn-logout" style="display:inline-flex; align-items:center; justify-content: center; gap:8px; text-decoration:none; padding:12px 30px; border-radius:50px; font-weight:600;">
                <i class='bx bx-log-out'></i> Keluar / Logout
            </a>
        </div>
    </div>
</div>

<div class="popup-overlay" id="cropModal" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box" style="width: 500px; max-width: 95%;">
        <h3 class="popup-title">Sesuaikan Foto</h3>
        <p class="popup-message" style="margin-bottom:15px;">Geser dan zoom area yang ingin diambil.</p>
        
        <div class="crop-container">
            <img id="image-to-crop" style="max-width: 100%; display: block;">
        </div>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" onclick="closeCropModal()" class="popup-btn" style="background:#f3f4f6; color:#111;">Batal</button>
            <button type="button" onclick="cropImage()" class="popup-btn success">Simpan</button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="deleteModal" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box error">
            <i class='bx bx-trash'></i>
        </div>
        
        <h3 class="popup-title">Hapus Akun?</h3>
        <p class="popup-message">Apakah Anda yakin? Akun yang dihapus tidak dapat dikembalikan lagi selamanya.</p>
        
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" onclick="closeDeleteModal()" class="popup-btn">Batal</button>
            
            <form method="POST" style="width:100%;">
                <button type="submit" name="delete_account" class="popup-btn error">Ya, Hapus</button>
            </form>
        </div>
    </div>
</div>

<div class="popup-overlay" id="modalVerifyPass" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box warning"><i class='bx bx-lock-alt'></i></div>
        <h3 class="popup-title">Verifikasi</h3>
        <p class="popup-message">Masukkan password lama Anda untuk melanjutkan.</p>
        
        <input type="password" id="old_password_input" class="form-control" placeholder="Password Lama" style="margin-bottom:15px; text-align:center;">
        <p id="error_msg_pass" style="color:red; font-size:0.85rem; display:none; margin-bottom:10px;"></p>

        <button onclick="checkOldPassword()" class="popup-btn warning" id="btnCheckPass">Lanjutkan</button>
        
        <div style="margin-top:15px; font-size:0.9rem; color:var(--text-muted);">
            Lupa password? <a href="#" onclick="switchToOTP()" style="color:var(--primary); font-weight:600;">Gunakan OTP Email</a>
        </div>
        <button onclick="closeModal('modalVerifyPass')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Batal</button>
    </div>
</div>

<div class="popup-overlay" id="modalDeletePasskey" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box error">
            <i class='bx bx-trash'></i>
        </div>
        
        <h3 class="popup-title">Hapus Passkey?</h3>
        <p class="popup-message">Anda tidak akan bisa login menggunakan metode ini lagi di perangkat terkait.</p>
        
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" onclick="closeModal('modalDeletePasskey')" class="popup-btn">Batal</button>
            
            <form method="POST" style="width:100%;">
                <input type="hidden" name="pk_id" id="delete_pk_id_target">
                <button type="submit" name="delete_passkey" class="popup-btn error">Ya, Hapus</button>
            </form>
        </div>
    </div>
</div>

<div class="popup-overlay" id="modalPasskeyName" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box success"><i class='bx bx-fingerprint'></i></div>
        <h3 class="popup-title">Beri Nama Passkey</h3>
        <p class="popup-message">Passkey berhasil dibuat! Beri nama agar mudah dikenali (Contoh: Proton Pass, Yubikey).</p>
        
        <input type="text" id="passkey_name_input" class="form-control" placeholder="Nama Passkey (Opsional)" style="margin-bottom:15px; text-align:center;">
        
        <button onclick="submitPasskeyData()" class="popup-btn success">Simpan</button>
        <button onclick="closeModal('modalPasskeyName')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Batal</button>
    </div>
</div>

<div class="popup-overlay" id="modalVerifyOTP" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box warning"><i class='bx bx-envelope'></i></div>
        <h3 class="popup-title">Kode OTP</h3>
        <p class="popup-message">Kami telah mengirim kode ke email Anda.</p>
        
        <input type="text" id="otp_input" class="form-control" placeholder="000000" style="margin-bottom:15px; text-align:center; letter-spacing:5px; font-size:1.2rem;">
        <p id="error_msg_otp" style="color:red; font-size:0.85rem; display:none; margin-bottom:10px;"></p>

        <button onclick="checkOTP()" class="popup-btn warning" id="btnCheckOTP">Verifikasi OTP</button>
        
        <div style="margin-top:15px; font-size:0.9rem; color:var(--text-muted);">
            Tidak menerima kode? 
            <span id="timer_display" style="color:var(--text-muted);">Kirim ulang dalam 60s</span>
            <a href="#" id="btn_resend" onclick="resendOTP()" style="display:none; color:var(--primary); font-weight:600;">Kirim Ulang</a>
        </div>
        <button onclick="closeModal('modalVerifyOTP')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Batal</button>
    </div>
</div>

<div class="popup-overlay" id="modalNewPass" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box success"><i class='bx bx-key'></i></div>
        <h3 class="popup-title">Password Baru</h3>
        <p class="popup-message">Silakan buat password baru Anda.</p>
        
        <form method="POST">
            <input type="password" id="new_password_input" name="new_password" class="form-control" placeholder="Password Baru" required style="margin-bottom:10px; text-align:center;">
            
            <div class="password-requirements" id="pwd-req-box-modal" style="text-align:left; background:#f9fafb; padding:10px; border-radius:8px; border:1px solid #e5e7eb; margin-bottom:15px; font-size:0.85rem;">

                <div class="req-item invalid" id="req-len" style="margin-bottom:2px;"><i class='bx bx-x'></i> 6+ Karakter</div>
                <div class="req-item invalid" id="req-upper" style="margin-bottom:2px;"><i class='bx bx-x'></i> Huruf Besar (A-Z)</div>
                <div class="req-item invalid" id="req-num" style="margin-bottom:2px;"><i class='bx bx-x'></i> Angka (0-9)</div>
                <div class="req-item invalid" id="req-sym" style="margin-bottom:2px;"><i class='bx bx-x'></i> Simbol (!@#$)</div>

            </div>

            <button type="submit" id="btnSavePass" name="save_new_password" class="popup-btn success" disabled style="opacity:0.6; cursor:not-allowed;">Simpan Password</button>
        </form>
        <button onclick="closeModal('modalNewPass')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer; margin-top:10px;">Batal</button>
    </div>
</div>

<div class="popup-overlay" id="modalGenericError" style="display:none; opacity:0; transition: opacity 0.3s;">
    <div class="popup-box">
        <div class="popup-icon-box error">
            <i class='bx bx-error-circle'></i>
        </div>
        <h3 class="popup-title">Perhatian</h3>
        <p class="popup-message" id="generic_error_text">Terjadi kesalahan.</p>
        
        <button onclick="closeModal('modalGenericError')" class="popup-btn" style="background:#f3f4f6; color:#111; cursor:pointer;">Tutup</button>
    </div>
</div>

<script src="passkey.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
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
                cropModal.style.display = 'flex';
                setTimeout(() => cropModal.style.opacity = '1', 10);

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
        closeCropModal();

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

    function closeCropModal() {
        cropModal.style.opacity = '0';
        setTimeout(() => {
            cropModal.style.display = 'none';
            if(cropper) cropper.destroy();
        }, 300);
    }

    // --- (PERUBAHAN 3: JS DELETE MODAL) ---
    const deleteModal = document.getElementById('deleteModal');

    function openDeleteModal() {
        deleteModal.style.display = 'flex';
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
        el.style.display = 'flex';
        setTimeout(() => el.style.opacity = '1', 10);
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        el.style.opacity = '0';
        setTimeout(() => el.style.display = 'none', 300);
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

        btn.innerText = "Memeriksa..."; btn.disabled = true;
        
        const formData = new FormData();
        formData.append('ajax_action', 'verify_old_password');
        formData.append('old_password', pass);

        fetch('index.php', { method: 'POST', body: formData })
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

        fetch('index.php', { method: 'POST', body: formData })
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

        fetch('index.php', { method: 'POST', body: formData })
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

        fetch('index.php', { method: 'POST', body: formData })
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
    



</script>

<?php include 'popupcustom.php'; ?>
</body>
</html>