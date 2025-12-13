<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// Pastikan Anda sudah COPY folder vendor dari spencal ke valselt-id
require __DIR__ . '/vendor/autoload.php'; 

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use PHPMailer\PHPMailer\PHPMailer;
use Google\Client as GoogleClient;
use Dotenv\Dotenv;

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    // Fallback jika file .env tidak ditemukan (Opsional)
    die("File konfigurasi .env tidak ditemukan.");
}

$conn = new mysqli(
    $_ENV['DB_HOST'], 
    $_ENV['DB_USER'], 
    $_ENV['DB_PASS'], 
    $_ENV['DB_NAME'], 
    $_ENV['DB_PORT']
);

if ($conn->connect_error) die("Koneksi Valselt ID Gagal: " . $conn->connect_error);

// --- AUTO LOGIN CHECK (REMEMBER ME) ---
if (!isset($_SESSION['valselt_user_id']) && isset($_COOKIE['remember_token'])) {
    $cookie_data = explode(':', $_COOKIE['remember_token']);
    
    if (count($cookie_data) == 2) {
        $uid = $conn->real_escape_string($cookie_data[0]);
        $token = $conn->real_escape_string($cookie_data[1]);
        
        // Cari user yang ID dan Token-nya cocok
        $q = $conn->query("SELECT * FROM users WHERE id='$uid' AND remember_token='$token'");
        
        if ($q->num_rows > 0) {
            $user = $q->fetch_assoc();
            
            // Login-kan user secara otomatis
            $_SESSION['valselt_user_id'] = $user['id'];
            $_SESSION['valselt_username'] = $user['username'];
            
            // (Opsional) Perbarui masa aktif cookie agar diperpanjang 30 hari lagi
            setcookie('remember_token', $_COOKIE['remember_token'], time() + (86400 * 30), "/", "", false, true);
        }
    }
}

// --- MINIO ---
$minio_bucket = $_ENV['MINIO_BUCKET']; 

try {
    $s3 = new S3Client([
        'version' => 'latest', 
        'region'  => $_ENV['MINIO_REGION'], 
        'endpoint' => $_ENV['MINIO_ENDPOINT'],
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key'    => $_ENV['MINIO_KEY'], 
            'secret' => $_ENV['MINIO_SECRET']
        ],
    ]);
} catch (Exception $e) { die("Gagal MinIO: " . $e->getMessage()); }

// --- GOOGLE ---
$google_client = new GoogleClient();
$google_client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$google_client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$google_client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']); 
$google_client->addScope('email');
$google_client->addScope('profile');

// --- GITHUB ---
$github_client_id     = $_ENV['GITHUB_CLIENT_ID'];
$github_client_secret = $_ENV['GITHUB_CLIENT_SECRET'];
$github_redirect_uri  = $_ENV['GITHUB_REDIRECT_URI'];

// --- CREDENTIALS LAIN ---
$recaptcha_site_key   = $_ENV['RECAPTCHA_SITE_KEY']; 
$recaptcha_secret_key = $_ENV['RECAPTCHA_SECRET_KEY'];

// --- SMTP MAIL ---
$mail_host      = $_ENV['MAIL_HOST'];
$mail_port      = $_ENV['MAIL_PORT']; 
$mail_user      = $_ENV['MAIL_USER']; 
$mail_pass      = $_ENV['MAIL_PASS'];  
$mail_from_name = $_ENV['MAIL_FROM_NAME'];

function sendOTPEmail($toEmail, $otp) {
    // Tambahkan $conn ke global agar bisa insert log
    global $mail_host, $mail_port, $mail_user, $mail_pass, $mail_from_name, $conn;
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $mail_host; $mail->SMTPAuth = true;
        $mail->Username = $mail_user; $mail->Password = $mail_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = $mail_port;
        $mail->setFrom($mail_user, $mail_from_name);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = "Kode Verifikasi Valselt ID Anda";

        // --- ASSETS ---
        $logoUrl = "https://cdn.ivanaldorino.web.id/valselt/valselt_white.png";
        $bgUrl   = "https://cdn.ivanaldorino.web.id/valselt/wallpaper_email.jpg";
        $year    = date('Y');
        
        // Buat Reference ID Unik
        $uniqueId = strtoupper(bin2hex(random_bytes(4))); 

        // --- LOGGING KE TABLE LOGSUSER (BARU) ---
        // 1. Cari ID User berdasarkan Email penerima
        $check_user = $conn->query("SELECT id FROM users WHERE email='$toEmail'");
        if ($check_user && $check_user->num_rows > 0) {
            $u_data = $check_user->fetch_assoc();
            $log_uid = $u_data['id'];
            
            // 2. Susun Pesan Log
            $log_behaviour = "Pengiriman OTP Ke " . $toEmail . ", dengan Reference ID " . $uniqueId;
            $log_behaviour = $conn->real_escape_string($log_behaviour);
            
            // 3. Simpan ke Database
            $conn->query("INSERT INTO logsuser (id_user, behaviour) VALUES ('$log_uid', '$log_behaviour')");
        }
        // ----------------------------------------

        // --- GENERATE OTP HTML ---
        $otpChars = str_split($otp);
        $otpHtml = '';
        foreach ($otpChars as $char) {
            $otpHtml .= "<span class='otp-box'>$char</span>";
        }

        // --- EMAIL CONTENT ---
        $mailContent = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <link rel='preconnect' href='https://fonts.googleapis.com'>
            <link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
            <link href='https://fonts.googleapis.com/css2?family=Inter+Tight:wght@700&display=swap' rel='stylesheet'>
            <style>
                /* RESET CSS */
                body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; font-family: 'Inter Tight', Helvetica, Arial, sans-serif;}
                table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
                img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
                
                /* DEFAULT STYLES (Desktop) */
                .otp-box {
                    display: inline-block;
                    margin: 0 6px;
                    padding: 12px 18px; 
                    /* MENGGUNAKAN FONT INTER TIGHT */
                    font-family: 'Inter Tight', Helvetica, Arial, sans-serif;
                    font-size: 28px;    
                    font-weight: 700;
                    color: #3c156b;     
                    background-color: #ebe7f0; 
                    border-radius: 8px;
                }
                
                .main-table { background-color: #ffffff; margin: 0 auto; width: 100%; max-width: 600px; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
                
                /* MOBILE RESPONSIVE */
                @media screen and (max-width: 480px) {
                    .main-table { width: 90% !important; } 
                    .otp-box {
                        margin: 0 2px !important;   
                        padding: 8px 12px !important; 
                        font-size: 20px !important;   
                    }
                    .logo-container { padding-left: 5% !important; }
                }
            </style>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f4f4f4;'>
            
            <table border='0' cellpadding='0' cellspacing='0' width='100%' style='background-image: url(\"$bgUrl\"); background-size: cover; background-position: center; background-repeat: no-repeat; padding: 40px 0;'>
                <tr>
                    <td align='center'>
                        
                        <table border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 600px; margin-bottom: 15px;'>
                            <tr>
                                <td align='left' class='logo-container' style='padding-left: 0;'> 
                                    <img src='$logoUrl' alt='Valselt ID' width='80' style='display: block; margin-top: 10px;'>
                                </td>
                            </tr>
                        </table>

                        <table class='main-table' border='0' cellpadding='0' cellspacing='0'>
                            <tr>
                                <td align='center' style='padding: 50px 40px; text-align: center;'>
                                    
                                    <h2 style='margin: 0 0 10px 0; font-family: Helvetica, Arial, sans-serif; color: #333333; font-size: 24px;'>
                                        Kode OTP Anda
                                    </h2>
                                    
                                    <p style='margin: 0 0 20px 0; font-family: Helvetica, Arial, sans-serif; color: #666666; font-size: 14px;'>
                                        Halo Pengguna Valselt ID,
                                    </p>
                                    
                                    <p style='margin: 0 0 30px 0; font-family: Helvetica, Arial, sans-serif; color: #666666; font-size: 14px; line-height: 1.5;'>
                                        Gunakan kode berikut untuk memverifikasi akun Anda.<br>
                                        Kode ini berlaku selama <strong>10 menit</strong>.
                                    </p>
                                    
                                    <div style='margin: 30px 0;'>
                                        $otpHtml
                                    </div>
                                    
                                    <p style='margin: 30px 0 0 0; font-family: Helvetica, Arial, sans-serif; color: #999999; font-size: 12px;'>
                                        Jangan bagikan kode ini kepada siapa pun,<br>termasuk pihak Valselt ID.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td align='center' style='background-color: #fafafa; padding: 15px; border-top: 1px solid #eeeeee;'>
                                    <a href='#' style='color: #1a73e8; font-family: Helvetica, Arial, sans-serif; font-size: 12px; text-decoration: none;'>Pusat Bantuan</a>
                                    <span style='color: #cccccc; margin: 0 10px;'>|</span>
                                    <a href='#' style='color: #1a73e8; font-family: Helvetica, Arial, sans-serif; font-size: 12px; text-decoration: none;'>Website Kami</a>
                                </td>
                            </tr>
                        </table>

                        <table border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 600px; margin-top: 20px;'>
                            <tr>
                                <td align='center' style='font-family: Helvetica, Arial, sans-serif; color: #ffffff; font-size: 12px; opacity: 0.8;'>
                                    &copy; $year Valselt ID Company. All rights reserved.<br>
                                    <span style='color: #ffffff; font-size: 10px; opacity: 0.4;'>Ref: $uniqueId</span>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>
            </table>
            
            <div style='display:none; white-space:nowrap; font:15px courier; line-height:0;'>&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</div>
        </body>
        </html>
        ";

        $mail->Body = $mailContent;
        $mail->send();
        return true;
    } catch (Exception $e) { return false; }
}

function getDeviceName() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $os = "Unknown OS";
    $browser = "Unknown Browser";

    // --- DETEKSI OS & MODEL ---
    
    if (preg_match('/windows nt 10/i', $userAgent))       $os = 'Windows 10/11';
    elseif (preg_match('/windows nt 6.3/i', $userAgent))   $os = 'Windows 8.1';
    elseif (preg_match('/macintosh|mac os x/i', $userAgent)) $os = 'Mac OS';
    
    // DETEKSI ANDROID + MODEL
    elseif (preg_match('/android/i', $userAgent)) {
        $os = 'Android';
        
        // Coba ambil teks antara "Android ...;" dan "Build/"
        // Contoh UA: ... Android 10; SAMSUNG SM-A505F Build/ ...
        if (preg_match('/Android\s+([0-9.]+); ([a-zA-Z0-9\s\-\_]+) Build/i', $userAgent, $matches)) {
            // $matches[2] biasanya berisi model HP (Misal: SAMSUNG SM-A505F)
            $model = trim($matches[2]);
            if (!empty($model)) {
                $os = "Android ($model)";
            }
        } 
        // Fallback: Coba ambil format lain jika format pertama gagal
        elseif (preg_match('/Android\s+([0-9.]+); ([a-zA-Z0-9\s\-\_]+)\)/i', $userAgent, $matches)) {
             $model = trim($matches[2]);
             if (!empty($model)) {
                $os = "Android ($model)";
            }
        }
    }
    
    elseif (preg_match('/linux/i', $userAgent))           $os = 'Linux';
    elseif (preg_match('/iphone/i', $userAgent))          $os = 'iPhone';
    elseif (preg_match('/ipad/i', $userAgent))            $os = 'iPad';

    // --- DETEKSI BROWSER ---
    if (preg_match('/MSIE/i', $userAgent) && !preg_match('/Opera/i', $userAgent)) $browser = 'Internet Explorer';
    elseif (preg_match('/Firefox/i', $userAgent)) $browser = 'Firefox';
    elseif (preg_match('/Chrome/i', $userAgent))  $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $userAgent))  $browser = 'Safari';
    elseif (preg_match('/Opera/i', $userAgent))   $browser = 'Opera';

    return "$os - $browser";
}

function getRealIP() {
    // Cloudflare Real IP
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    // X-Forwarded-For (Proxy chain)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }

    // Default
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function getUserLocation($ip) {
    // Localhost
    if ($ip == '127.0.0.1' || $ip == '::1') {
        return 'Localhost';
    }

    // Try with ipwho.is
    $ch = curl_init("https://ipwho.is/{$ip}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);

        if (!empty($data['success']) && $data['success'] == true) {
            $parts = [];

            if (!empty($data['city']))      $parts[] = $data['city'];
            if (!empty($data['region']))    $parts[] = $data['region'];
            if (!empty($data['country']))   $parts[] = $data['country'];

            if (!empty($parts)) {
                return implode(', ', $parts);
            }
        }
    }

    // 🔥 FALLBACK ke ip-api.com
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,city,regionName,country");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);

        if (!empty($data['status']) && $data['status'] == "success") {
            $parts = [];

            if (!empty($data['city']))        $parts[] = $data['city'];
            if (!empty($data['regionName']))  $parts[] = $data['regionName'];
            if (!empty($data['country']))     $parts[] = $data['country'];

            if (!empty($parts)) {
                return implode(', ', $parts);
            }
        }
    }

    return 'Unknown Location';
}

function logUserDevice($conn, $uid) {
    // 1. Ambil Data Lingkungan
    $device   = $conn->real_escape_string(getDeviceName());
    $ip       = getRealIP(); 
    $location = $conn->real_escape_string(getUserLocation($ip));
    $sess_id  = session_id();

    // 2. Cek Cookie Identitas Device
    $device_token = isset($_COOKIE['valselt_device_id']) ? $_COOKIE['valselt_device_id'] : null;
    $found_in_db  = false;

    if ($device_token) {
        // Cek apakah token ini terdaftar milik user ini di DB
        $stmt = $conn->prepare("SELECT id FROM user_devices WHERE device_token = ? AND user_id = ?");
        $stmt->bind_param("si", $device_token, $uid);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $found_in_db = true;
            $row = $res->fetch_assoc();
            $db_id = $row['id'];

            // === SKENARIO A: DEVICE LAMA (UPDATE) ===
            // PERBAIKAN DISINI: Ubah "sssssi" menjadi "ssssi" (5 karakter)
            $update = $conn->prepare("UPDATE user_devices SET session_id=?, ip_address=?, location=?, last_login=NOW(), device_name=?, is_active=1 WHERE id=?");
            $update->bind_param("ssssi", $sess_id, $ip, $location, $device, $db_id);
            $update->execute();
        }
    }

    if (!$found_in_db) {
        // === SKENARIO B: DEVICE BARU / COOKIE HILANG (INSERT) ===
        // 1. Buat Token Baru
        $new_token = bin2hex(random_bytes(32)); 

        // 2. Tanam Cookie "KTP" di Browser (Berlaku 10 Tahun)
        setcookie('valselt_device_id', $new_token, time() + (86400 * 365 * 10), "/", "", false, true);

        // 3. Simpan Device Baru ke DB
        // Format: isssss (Integer, String, String, String, String, String) - Total 6
        $stmt = $conn->prepare("INSERT INTO user_devices (user_id, device_token, device_name, ip_address, location, session_id, last_login, is_active) VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)");
        $stmt->bind_param("isssss", $uid, $new_token, $device, $ip, $location, $sess_id);
        $stmt->execute();
    }

    // 3. Bersihkan Sesi Ganda (Logout device lain yang punya session_id sama - konflik PHP)
    // Pastikan token device beda
    $conn->query("UPDATE user_devices SET is_active = 0 WHERE session_id = '$sess_id' AND device_token != '$device_token'");
}


function logActivity($conn, $uid, $action) {
    if (!$uid) return;
    $action = $conn->real_escape_string($action);
    // Pastikan koneksi DB ($conn) valid
    $conn->query("INSERT INTO logsuser (id_user, behaviour) VALUES ('$uid', '$action')");
}

function handleRememberMe($conn, $uid) {
    // 1. Buat Token Random
    $token = bin2hex(random_bytes(32));
    
    // 2. Simpan Token di Database
    $conn->query("UPDATE users SET remember_token='$token' WHERE id='$uid'");
    
    // 3. Simpan Token di Cookie Browser (30 Hari)
    // Format cookie: "user_id:token"
    $cookie_value = $uid . ':' . $token;
    setcookie('remember_token', $cookie_value, time() + (86400 * 30), "/", "", false, true);
}

function sendLogEmail($toEmail, $username, $csvContent) {
    global $mail_host, $mail_port, $mail_user, $mail_pass, $mail_from_name, $conn;
    
    $mail = new PHPMailer(true);
    try {
        // Konfigurasi SMTP
        $mail->isSMTP();
        $mail->Host = $mail_host; $mail->SMTPAuth = true;
        $mail->Username = $mail_user; $mail->Password = $mail_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = $mail_port;
        $mail->setFrom($mail_user, $mail_from_name);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = "Log Aktivitas Akun Valselt ID Anda";

        // Attachment CSV
        $mail->addStringAttachment($csvContent, 'ActivityLogs_'.date('Ymd_Hi').'.csv');

        // --- ASSETS ---
        $logoUrl = "https://cdn.ivanaldorino.web.id/valselt/valselt_white.png";
        $bgUrl   = "https://cdn.ivanaldorino.web.id/valselt/wallpaper_email.jpg";
        $year    = date('Y');
        
        // Buat Reference ID Unik
        $uniqueId = strtoupper(bin2hex(random_bytes(4)));

        // --- LOG KE DATABASE ---
        $check_user = $conn->query("SELECT id FROM users WHERE email='$toEmail'");
        if ($check_user && $check_user->num_rows > 0) {
            $u_data = $check_user->fetch_assoc();
            $log_uid = $u_data['id'];
            $log_behaviour = "Mengirim Log Aktivitas (CSV) ke Email, Ref: " . $uniqueId;
            $conn->query("INSERT INTO logsuser (id_user, behaviour) VALUES ('$log_uid', '$log_behaviour')");
        }

        // --- EMAIL CONTENT (HTML STYLE) ---
        $mailContent = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <link href='https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;700&display=swap' rel='stylesheet'>
            <style>
                body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; font-family: 'Inter Tight', Helvetica, Arial, sans-serif;}
                table { border-collapse: collapse; }
                img { border: 0; outline: none; text-decoration: none; }
                
                .main-table { background-color: #ffffff; margin: 0 auto; width: 100%; max-width: 600px; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
                
                @media screen and (max-width: 480px) {
                    .main-table { width: 90% !important; }
                    .logo-container { padding-left: 5% !important; }
                }
            </style>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f4f4f4;'>
            
            <table border='0' cellpadding='0' cellspacing='0' width='100%' style='background-image: url(\"$bgUrl\"); background-size: cover; background-position: center; background-repeat: no-repeat; padding: 40px 0;'>
                <tr>
                    <td align='center'>
                        
                        <table border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 600px; margin-bottom: 15px;'>
                            <tr>
                                <td align='left' class='logo-container' style='padding-left: 0;'> 
                                    <img src='$logoUrl' alt='Valselt ID' width='80' style='display: block; margin-top: 10px;'>
                                </td>
                            </tr>
                        </table>

                        <table class='main-table' border='0' cellpadding='0' cellspacing='0'>
                            <tr>
                                <td align='center' style='padding: 50px 40px; text-align: center;'>
                                    
                                    <h2 style='margin: 0 0 15px 0; color: #333333; font-size: 24px; font-weight: 700;'>
                                        Log Aktivitas Keamanan
                                    </h2>
                                    
                                    <p style='margin: 0 0 20px 0; color: #666666; font-size: 14px; line-height: 1.6;'>
                                        Halo <strong>$username</strong>,
                                    </p>
                                    
                                    <p style='margin: 0 0 30px 0; color: #666666; font-size: 14px; line-height: 1.6;'>
                                        Sesuai permintaan Anda, kami melampirkan file <strong>CSV</strong> yang berisi riwayat aktivitas login dan keamanan akun Anda.
                                        <br><br>
                                        Silakan periksa lampiran (attachment) pada email ini.
                                    </p>
                                    
                                    <div style='background: #f0fdf4; color: #166534; padding: 15px; border-radius: 8px; font-size: 13px; border: 1px solid #bbf7d0; margin-bottom: 30px;'>
                                        <strong style='display:block; margin-bottom:5px;'>💡 Tips Keamanan:</strong>
                                        Jika Anda melihat aktivitas yang mencurigakan di dalam log ini, segera ganti password Anda dan hapus sesi perangkat yang tidak dikenal.
                                    </div>
                                    
                                    <p style='margin: 0; color: #999999; font-size: 12px;'>
                                        Permintaan ini dibuat secara otomatis melalui Dashboard Akun Valselt ID.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td align='center' style='background-color: #fafafa; padding: 15px; border-top: 1px solid #eeeeee;'>
                                    <a href='#' style='color: #1a73e8; font-size: 12px; text-decoration: none;'>Pusat Bantuan</a>
                                    <span style='color: #cccccc; margin: 0 10px;'>|</span>
                                    <a href='#' style='color: #1a73e8; font-size: 12px; text-decoration: none;'>Keamanan Akun</a>
                                </td>
                            </tr>
                        </table>

                        <table border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 600px; margin-top: 20px;'>
                            <tr>
                                <td align='center' style='color: #ffffff; font-size: 12px; opacity: 0.8;'>
                                    &copy; $year Valselt ID Company. All rights reserved.<br>
                                    <span style='color: #ffffff; font-size: 10px; opacity: 0.4;'>Ref: $uniqueId</span>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>
            </table>
            
        </body>
        </html>
        ";

        $mail->Body = $mailContent;
        $mail->send();
        return true;
    } catch (Exception $e) { return false; }
}

function checkTrustedDevice($conn, $uid) {
    // 1. Cek Cookie Browser
    if (!isset($_COOKIE['valselt_2fa_trusted'])) {
        return false; // Tidak ada cookie -> Belum trusted
    }
    
    $token = $_COOKIE['valselt_2fa_trusted'];
    
    // 2. Cek Token di Database (Tabel user_devices)
    // Pastikan token ini milik user yang sedang login ($uid)
    $stmt = $conn->prepare("SELECT id FROM user_devices WHERE user_id = ? AND two_factor_token = ?");
    $stmt->bind_param("is", $uid, $token);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        return true; // Trusted!
    }
    
    return false; // Token salah/kadaluarsa/device lain
}

// FUNGSI UNTUK MENCATAT & REDIRECT SSO
function processSSORedirect($conn, $uid, $target) {
    if (!empty($target) && strpos($target, 'http') !== 0) {
        $decoded = base64_decode($target, true);
        if ($decoded) $target = $decoded;
    }
    if (!empty($target)) {
        // 1. Ambil Nama Domain/App dari URL Target
        $parsed = parse_url($target);
        $host = isset($parsed['host']) ? $parsed['host'] : $target;
        
        // Bersihkan www. atau subdomain jika perlu, atau ambil nama simpel
        // Contoh sederhana: Ambil kata pertama sebelum titik (spencal.web.id -> spencal)
        $parts = explode('.', $host);
        $appName = ucfirst($parts[0]); // Spencal
        if($appName == 'Www') $appName = ucfirst($parts[1]); // Jika ada www

        // 2. Catat ke Database (Insert atau Update waktu akses)
        // Cek dulu apakah sudah ada
        $check = $conn->prepare("SELECT id FROM authorized_apps WHERE user_id = ? AND app_domain = ?");
        $check->bind_param("is", $uid, $host);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            // Update Last Accessed
            $stmt = $conn->prepare("UPDATE authorized_apps SET last_accessed = NOW() WHERE user_id = ? AND app_domain = ?");
            $stmt->bind_param("is", $uid, $host);
            $stmt->execute();
        } else {
            // Insert Baru
            $stmt = $conn->prepare("INSERT INTO authorized_apps (user_id, app_domain, app_name, last_accessed) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iss", $uid, $host, $appName);
            $stmt->execute();
        }

        // 3. Log Aktivitas
        logActivity($conn, $uid, "Login SSO ke Aplikasi: " . $appName . " (" . $host . ")");

        // 4. Generate Token & Redirect
        $token = bin2hex(random_bytes(32));
        $conn->query("UPDATE users SET auth_token='$token' WHERE id='$uid'");
        header("Location: " . $target . "?token=" . $token);
    } else {
        header("Location: ./");
    }
    exit();
}
?>