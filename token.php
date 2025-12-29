<?php
require 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'invalid_request']); exit();
}

$client_id = $_POST['client_id'] ?? '';
$client_secret = $_POST['client_secret'] ?? '';
$code = $_POST['code'] ?? '';

// 1. CEK KREDENSIAL APLIKASI DI MASTER (oauth_clients)
// Memastikan yang request benar-benar Server Spencal
$stmt = $conn->prepare("SELECT * FROM oauth_clients WHERE client_id = ? AND client_secret = ?");
$stmt->bind_param("ss", $client_id, $client_secret);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();

if (!$app) {
    echo json_encode(['error' => 'invalid_client', 'message' => 'Client authentication failed']); exit();
}

// 2. Validasi Authorization Code
$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare("SELECT * FROM oauth_codes WHERE code = ? AND client_id = ? AND expires_at > ? AND is_used = 0");
$stmt->bind_param("sss", $code, $client_id, $now);
$stmt->execute();
$auth_code_data = $stmt->get_result()->fetch_assoc();

if (!$auth_code_data) {
    echo json_encode(['error' => 'invalid_grant', 'message' => 'Code expired or invalid']); exit();
}

// 3. Mark Code as Used
$stmt = $conn->prepare("UPDATE oauth_codes SET is_used = 1 WHERE id = ?");
$stmt->bind_param("i", $auth_code_data['id']);
$stmt->execute();

// 4. Ambil Data User Pemilik Code
$user_id = $auth_code_data['user_id'];
$stmt = $conn->prepare("SELECT id, username, email, profile_pic, is_verified, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// 5. Return Data User
echo json_encode([
    'access_token' => bin2hex(random_bytes(32)), 
    'token_type' => 'Bearer',
    'expires_in' => 3600,
    'user_info' => $user
]);
exit();
?>