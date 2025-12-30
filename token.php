<?php
// Izinkan akses dari mana saja (CORS) jika perlu, atau spesifik domain
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json');

require 'config.php';

// Aktifkan error reporting untuk debugging JSON (Matikan di production nanti)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid Request Method');
    }

    $client_id     = $_POST['client_id'] ?? '';
    $client_secret = $_POST['client_secret'] ?? '';
    $code          = $_POST['code'] ?? '';
    $redirect_uri  = $_POST['redirect_uri'] ?? '';

    // 1. Validasi Client (Tanpa cek redirect_uri app)
    $stmt = $conn->prepare("SELECT * FROM oauth_clients WHERE client_id = ? AND client_secret = ?");
    $stmt->bind_param("ss", $client_id, $client_secret);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        throw new Exception('Client authentication failed. Cek Client ID/Secret.');
    }

    // 2. Validasi Authorization Code & Match Redirect URI
    // Pastikan kolom `redirect_uri` ADA di tabel `oauth_codes`
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT * FROM oauth_codes WHERE code = ? AND client_id = ? AND redirect_uri = ? AND expires_at > ? AND is_used = 0");
    
    // String tipe data harus "ssss" (4 huruf 's'), variabel juga harus 4
    $stmt->bind_param("ssss", $code, $client_id, $redirect_uri, $now);
    
    $stmt->execute();
    $auth_code_data = $stmt->get_result()->fetch_assoc();

    if (!$auth_code_data) {
        throw new Exception('Invalid Code, Expired, or Redirect URI Mismatch. (Pastikan Redirect URI di login.php sama persis dengan yang dikirim saat authorize)');
    }

    // 3. Mark Code as Used
    $stmt = $conn->prepare("UPDATE oauth_codes SET is_used = 1 WHERE id = ?");
    $stmt->bind_param("i", $auth_code_data['id']);
    $stmt->execute();

    // 4. Ambil Data User
    $user_id = $auth_code_data['user_id'];
    $stmt = $conn->prepare("SELECT id, username, email, profile_pic, is_verified, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    echo json_encode([
        'access_token' => bin2hex(random_bytes(32)), 
        'user_info' => $user
    ]);

} catch (mysqli_sql_exception $e) {
    // Tangkap Error SQL
    http_response_code(500);
    echo json_encode(['error' => 'database_error', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    // Tangkap Error Logika
    http_response_code(400);
    echo json_encode(['error' => 'auth_error', 'message' => $e->getMessage()]);
}
?>