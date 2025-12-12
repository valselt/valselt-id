<?php
// 1. TANGKAP SEMUA OUTPUT LIAR
ob_start();

require 'config.php';
require 'vendor/autoload.php';

// 2. CEK SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// --- HELPER FUNCTIONS ---

// Output JSON Bersih
function sendJson($data) {
    ob_clean(); 
    echo json_encode($data);
    exit();
}

// Konversi Base64URL (JS) ke Binary (PHP)
// INI SOLUSI ERROR "INVALID OFFSET OR LENGTH"
function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

// Rekursif Konversi Binary ke Base64 (Untuk Output ke JS)
function recursiveConvert($data) {
    if (is_array($data)) {
        return array_map('recursiveConvert', $data);
    }
    if (is_object($data)) {
        if ($data instanceof \lbuchs\WebAuthn\Binary\ByteBuffer) {
            return base64_encode($data->getBinaryString());
        }
        $newData = clone $data;
        foreach ($newData as $key => $value) {
            $newData->$key = recursiveConvert($value);
        }
        return $newData;
    }
    return $data;
}
// ------------------------

try {
    // KONFIGURASI
    $rpName = 'Valselt ID';
    $rpId = 'valseltid.ivanaldorino.web.id'; 

    if (!class_exists('\lbuchs\WebAuthn\WebAuthn')) {
        throw new Exception("Library WebAuthn tidak ditemukan.");
    }

    $webAuthn = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId);

    // Ambil Input
    $post = json_decode(file_get_contents('php://input'));
    $fn = $_GET['fn'] ?? '';

    // ==================================================================
    // 1. REGISTER: DAPATKAN CHALLENGE
    // ==================================================================
    if ($fn === 'getRegisterArgs') {
        if (!isset($_SESSION['valselt_user_id'])) throw new Exception("Login required");
        
        $userId = $_SESSION['valselt_user_id'];
        $userName = $_SESSION['valselt_username'];
        
        $existingIds = [];
        $q = $conn->query("SELECT credential_id FROM user_passkeys WHERE user_id='$userId'");
        while($row = $q->fetch_assoc()) {
            $existingIds[] = base64_decode($row['credential_id']);
        }

        $createArgs = $webAuthn->getCreateArgs(
            (string)$userId, 
            $userName, 
            $userName, 
            60*4, 
            false, 
            false, 
            $existingIds
        );

        // Simpan Challenge sebagai String Binary
        $_SESSION['challenge'] = $webAuthn->getChallenge()->getBinaryString();
        
        sendJson(recursiveConvert($createArgs));
    }

    // ==================================================================
    // 2. REGISTER: PROSES VERIFIKASI
    // ==================================================================
    elseif ($fn === 'processRegister') {
        if (!isset($_SESSION['valselt_user_id'])) throw new Exception("Login required");

        // PERBAIKAN UTAMA: Gunakan base64url_decode buatan kita
        $clientDataJSON = base64url_decode($post->clientDataJSON);
        $attestationObject = base64url_decode($post->attestationObject);
        
        $challenge = $_SESSION['challenge'];

        // Validasi
        $data = $webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, false, true, false);

        // Simpan ke DB
        $uid = $_SESSION['valselt_user_id'];
        $credId = base64_encode($data->credentialId);
        $pubKey = $data->credentialPublicKey;
        
        // Cek duplikasi
        $cek = $conn->query("SELECT id FROM user_passkeys WHERE credential_id='$credId'");
        if ($cek->num_rows > 0) {
            throw new Exception("Passkey ini sudah terdaftar.");
        }

        $stmt = $conn->prepare("INSERT INTO user_passkeys (user_id, credential_id, public_key) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $uid, $credId, $pubKey);
        
        if ($stmt->execute()) {
            $devName = function_exists('getDeviceName') ? getDeviceName() : 'Unknown Device';
            logActivity($conn, $uid, "Menambahkan Passkey Baru di " . $devName);
            sendJson(['status' => 'success', 'msg' => 'Passkey berhasil didaftarkan!']);
        } else {
            throw new Exception("Database Error");
        }
    }

    // ==================================================================
    // 3. LOGIN: DAPATKAN CHALLENGE
    // ==================================================================
    elseif ($fn === 'getLoginArgs') {
        $getArgs = $webAuthn->getGetArgs();
        $_SESSION['challenge'] = $webAuthn->getChallenge()->getBinaryString();
        sendJson(recursiveConvert($getArgs));
    }

    // ==================================================================
    // 4. LOGIN: PROSES VERIFIKASI
    // ==================================================================
    elseif ($fn === 'processLogin') {
        // PERBAIKAN UTAMA: Gunakan base64url_decode buatan kita
        $clientDataJSON = base64url_decode($post->clientDataJSON);
        $authenticatorData = base64url_decode($post->authenticatorData);
        $signature = base64url_decode($post->signature);
        $credentialId = base64url_decode($post->id);
        
        $challenge = $_SESSION['challenge'];

        $id_for_db = base64_encode($credentialId);
        $q = $conn->query("SELECT * FROM user_passkeys WHERE credential_id='$id_for_db'");
        
        if ($q->num_rows === 0) {
            throw new Exception("Passkey tidak dikenal.");
        }
        
        $row = $q->fetch_assoc();
        $credentialPublicKey = $row['public_key'];
        
        // Verifikasi
        if ($webAuthn->processGet($clientDataJSON, $authenticatorData, $signature, $credentialPublicKey, $challenge)) {
            $user_id = $row['user_id'];
            $uq = $conn->query("SELECT * FROM users WHERE id='$user_id'");
            $user = $uq->fetch_assoc();
            
            $_SESSION['valselt_user_id'] = $user['id'];
            $_SESSION['valselt_username'] = $user['username'];
            
            $devName = function_exists('getDeviceName') ? getDeviceName() : 'Unknown Device';
            logActivity($conn, $user_id, "Login via Passkey di " . $devName);
            
            if(function_exists('logUserDevice')) {
                logUserDevice($conn, $user_id);
            }
            
            sendJson(['status' => 'success']);
        }
    }

} catch (Exception $ex) {
    http_response_code(400);
    ob_clean(); 
    echo json_encode(['status' => 'error', 'message' => $ex->getMessage()]);
    exit();
}
?>