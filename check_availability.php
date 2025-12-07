<?php
require 'config.php';

header('Content-Type: application/json');

// Terima data JSON dari Javascript
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['type']) && isset($data['value'])) {
    $type = $data['type']; // 'username' atau 'email'
    $value = $conn->real_escape_string($data['value']);
    
    // Pastikan hanya mengecek kolom yang valid
    if ($type !== 'username' && $type !== 'email') {
        echo json_encode(['status' => 'error']);
        exit;
    }

    $query = "SELECT id FROM users WHERE $type = '$value'";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        // Sudah ada (Tidak Tersedia)
        echo json_encode(['status' => 'taken']);
    } else {
        // Belum ada (Tersedia)
        echo json_encode(['status' => 'available']);
    }
} else {
    echo json_encode(['status' => 'error']);
}
?>