<?php
// api/user.php
require 'config.php';

header('Content-Type: application/json');

// 1. Ambil Token dari Header Authorization (Bearer Token)
$headers = apache_request_headers();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
    $token = $matches[1];
} else {
    // Fallback: Cek parameter GET (kurang aman tapi oke buat awal)
    $token = isset($_GET['token']) ? $_GET['token'] : '';
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token required']);
    exit();
}

// 2. Validasi Token di Database
// Perhatikan: Kolom 'auth_token' di users sebaiknya punya masa berlaku (expiry)
// Untuk sekarang kita cek token saja.
$stmt = $conn->prepare("SELECT id, username, email, profile_pic, google_id, github_id FROM users WHERE auth_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // 3. Kembalikan Data User (JSON)
    echo json_encode([
        'status' => 'success',
        'data' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'profile_pic' => $user['profile_pic'],
            'providers' => [
                'google' => !empty($user['google_id']),
                'github' => !empty($user['github_id'])
            ]
        ]
    ]);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
}
?>