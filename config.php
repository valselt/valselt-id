<?php

session_start();
date_default_timezone_set('Asia/Jakarta');

// Pastikan Anda sudah COPY folder vendor dari spencal ke valselt-id
require __DIR__ . '/vendor/autoload.php'; 

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use PHPMailer\PHPMailer\PHPMailer;
use Google\Client as GoogleClient;

// --- DATABASE PUSAT (VALSELT ID) ---
$db_host = '100.115.160.110';
$db_port = 3306;
$db_user = 'root';
$db_pass = 'aldorino04';
$db_name = 'valselt_id'; // Pastikan DB ini sudah dibuat dan tabel users ada disana

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
if ($conn->connect_error) die("Koneksi Valselt ID Gagal: " . $conn->connect_error);

// --- MINIO ---
$minio_endpoint = 'https://cdn.ivanaldorino.web.id/';
$minio_key      = 'admin';
$minio_secret   = 'aldorino04';
$minio_bucket   = 'valselt'; 

try {
    $s3 = new S3Client([
        'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $minio_endpoint,
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => $minio_key, 'secret' => $minio_secret],
    ]);
} catch (Exception $e) { die("Gagal MinIO: " . $e->getMessage()); }


$google_client = new GoogleClient();
$google_client->setClientId('627951571756-lrp1sdd41nbbi6sf0snvkcs4e6v8c43g.apps.googleusercontent.com');
$google_client->setClientSecret('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');
// Ganti URL dibawah sesuai domain/IP CasaOS Anda
$google_client->setRedirectUri('https://valseltidbackup.ivanaldorino.web.id/google_auth.php'); 
$google_client->addScope('email');
$google_client->addScope('profile');

// --- CREDENTIALS LAIN ---
$recaptcha_site_key   = '6LdEEyMsAAAAAPK75it3V-_wxwWESVqQebrdNzKF'; 
$recaptcha_secret_key = '6LdEEyMsAAAAADK5A1RXPIHpHTi2lwx5CdnORfwB';

$mail_host = 'smtp.gmail.com';
$mail_port = 587; 
$mail_user = 'valseltalt@gmail.com'; 
$mail_pass = 'cryw pkpa chai pefm';  
$mail_from_name = 'Valselt ID Security';

function sendOTPEmail($toEmail, $otp) {
    global $mail_host, $mail_port, $mail_user, $mail_pass, $mail_from_name;
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $mail_host; $mail->SMTPAuth = true;
        $mail->Username = $mail_user; $mail->Password = $mail_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = $mail_port;
        $mail->setFrom($mail_user, $mail_from_name);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = "Kode Verifikasi Valselt ID";
        $mail->Body    = "<h3>Kode OTP: $otp</h3>";
        $mail->send();
        return true;
    } catch (Exception $e) { return false; }
}
?>