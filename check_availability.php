<?php
require 'config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['type']) && isset($data['value'])) {
    $type = $data['type']; // 'username' atau 'email'
    $value = $conn->real_escape_string($data['value']);
    
    if ($type !== 'username' && $type !== 'email') {
        echo json_encode(['status' => 'error']);
        exit;
    }

    $query = "SELECT id FROM users WHERE $type = '$value'";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        // SUDAH ADA DI DB -> TAKEN -> MUNCUL SILANG (Invalid)
        echo json_encode(['status' => 'taken']);
    } else {
        // TIDAK ADA DI DB -> AVAILABLE -> MUNCUL CENTANG (Valid)
        echo json_encode(['status' => 'available']);
    }
} else {
    echo json_encode(['status' => 'error']);
}
?>