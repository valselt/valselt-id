<?php
// HAPUS session_start(); DISINI (karena sudah ada di config.php)
require 'config.php';

if (!isset($_SESSION['valselt_user_id'])) {
    header("Location: login.php"); exit();
}

$user_id = $_SESSION['valselt_user_id'];

// --- LOGIC 1: UPDATE PROFILE ---
if (isset($_POST['update_profile'])) {
    $new_username = htmlspecialchars($_POST['username']);
    $new_email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $new_pass = $_POST['password'];
    
    // --- UPDATE FOTO PROFIL (VIA BASE64 CROPPER) ---
    if (!empty($_POST['cropped_image'])) {
        $data = $_POST['cropped_image'];
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
            // Path penyimpanan di MinIO
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
            } catch (AwsException $e) {
                $_SESSION['popup_status'] = 'error';
                $_SESSION['popup_message'] = "Upload Gagal: " . $e->getMessage();
            }
        }
    }

    $conn->query("UPDATE users SET username='$new_username', email='$new_email' WHERE id='$user_id'");

    if (!empty($new_pass)) {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password='$hash' WHERE id='$user_id'");
    }
    
    $_SESSION['valselt_username'] = $new_username; // Update session
    $_SESSION['popup_status'] = 'success';
    $_SESSION['popup_message'] = 'Profil berhasil diperbarui!';
    header("Location: index.php");
    exit();
}

// --- LOGIC 2: HAPUS AKUN ---
if (isset($_POST['delete_account'])) {
    $conn->query("DELETE FROM users WHERE id='$user_id'");
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
    <title>Valselt ID</title>
    <link rel="icon" type="image/png" href="https://cdn.ivanaldorino.web.id/valselt/valselt_favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <style>
        /* Override Style untuk Layout Tanpa Sidebar */
        body { background-color: #f8fafc; font-family: 'DM Sans', sans-serif; display: block; height: auto; }
        .valselt-container { max-width: 800px; margin: 50px auto; padding: 20px; }
        .valselt-header { text-align: center; margin-bottom: 40px; }
        .valselt-brand { font-size: 2rem; font-weight: 800; color: #4f46e5; }
        .valselt-brand span { color: #1e293b; }
        
        .profile-card { background: white; border-radius: 16px; padding: 40px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: 1px solid #e2e8f0; }
        
        .profile-header-section { display: flex; flex-direction: column; align-items: center; margin-bottom: 30px; }
        .avatar-wrapper { position: relative; width: 120px; height: 120px; margin-bottom: 15px; }
        .avatar-img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid #e2e8f0; }
        .avatar-placeholder { width: 100%; height: 100%; border-radius: 50%; background: #4f46e5; color: white; display: flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: bold; border: 4px solid #e2e8f0; }
        
        .btn-edit-avatar { 
            position: absolute; bottom: 0; right: 0; 
            background: #1e293b; color: white; 
            width: 36px; height: 36px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            cursor: pointer; transition: 0.2s; border: 2px solid white;
        }
        .btn-edit-avatar:hover { background: #4f46e5; }

        /* Form Styling */
        .form-grid { display: grid; gap: 20px; }
        .btn-logout { 
            display: block; width: 100%; text-align: center; padding: 12px; margin-top: 20px; 
            background: #fee2e2; color: #991b1b; border-radius: 8px; font-weight: 600; text-decoration: none; 
        }
        .btn-logout:hover { background: #fecaca; }
        
        /* Cropper Modal */
        .crop-container { height: 300px; background: #333; overflow: hidden; margin-bottom: 20px; border-radius: 8px; }
        #hidden-file-input { display: none; }
    </style>
</head>
<body>

<div class="valselt-container">
    <div class="valselt-header">
        <div class="valselt-brand">valselt<span>.id</span></div>
        <p style="color:#64748b;">Pusat Pengaturan Akun</p>
    </div>

    <div class="profile-card">
        <form action="index.php" method="POST" id="profileForm">
            <input type="hidden" name="cropped_image" id="cropped_image_data">

            <div class="profile-header-section">
                <div class="avatar-wrapper">
                    <?php if($user_data['profile_pic']): ?>
                        <img src="<?php echo $user_data['profile_pic']; ?>" id="main-preview" class="avatar-img">
                    <?php else: ?>
                        <div id="main-preview-placeholder" class="avatar-placeholder">
                            <?php echo strtoupper(substr($user_data['username'], 0, 2)); ?>
                        </div>
                        <img src="" id="main-preview" class="avatar-img" style="display:none;">
                    <?php endif; ?>
                    
                    <div class="btn-edit-avatar" onclick="document.getElementById('hidden-file-input').click()">
                        <i class='bx bx-camera'></i>
                    </div>
                </div>
                <h2 style="margin:0; color:#1e293b;"><?php echo htmlspecialchars($user_data['username']); ?></h2>
                <p style="margin:5px 0 0 0; color:#64748b; font-size:0.9rem;"><?php echo htmlspecialchars($user_data['email']); ?></p>
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

                <div class="form-group">
                    <label class="form-label">Password Baru <span style="font-weight:400; color:#94a3b8;">(Kosongkan jika tidak ganti)</span></label>
                    <input type="password" name="password" class="form-control" placeholder="******">
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>

        <hr style="border:0; border-top:1px solid #e2e8f0; margin:30px 0;">

        <div style="text-align:center;">
             <form method="POST" onsubmit="return confirm('Yakin ingin menghapus akun? Ini permanen!');">
                <button type="submit" name="delete_account" style="background:none; border:none; color:#ef4444; font-weight:600; cursor:pointer; font-size:0.9rem;">
                    <i class='bx bx-trash'></i> Hapus Akun Saya Permanen
                </button>
            </form>
            
            <a href="logout.php" class="btn-logout">Keluar / Logout</a>
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

        <div style="display:flex; gap:10px;">
            <button type="button" onclick="closeCropModal()" class="popup-btn" style="background:#f1f5f9; color:#333;">Batal</button>
            <button type="button" onclick="cropImage()" class="popup-btn success">Selesai</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<script>
    // --- LOGIC CROPPER JS ---
    let cropper;
    const fileInput = document.getElementById('hidden-file-input');
    const imageToCrop = document.getElementById('image-to-crop');
    const cropModal = document.getElementById('cropModal');

    // 1. Saat file dipilih
    fileInput.addEventListener('change', function(e) {
        const files = e.target.files;
        if (files && files.length > 0) {
            const file = files[0];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                imageToCrop.src = e.target.result;
                // Buka Modal
                cropModal.style.display = 'flex';
                setTimeout(() => cropModal.style.opacity = '1', 10);

                if(cropper) cropper.destroy();
                cropper = new Cropper(imageToCrop, {
                    aspectRatio: 1, // Kotak
                    viewMode: 1,
                    autoCropArea: 1
                });
            };
            reader.readAsDataURL(file);
        }
        this.value = null; // Reset input
    });

    // 2. Saat tombol Selesai ditekan
    function cropImage() {
        const canvas = cropper.getCroppedCanvas({ width: 300, height: 300 });
        const base64Image = canvas.toDataURL("image/webp");

        // Update Preview
        const mainPreview = document.getElementById('main-preview');
        const placeholder = document.getElementById('main-preview-placeholder');
        
        mainPreview.src = base64Image;
        mainPreview.style.display = 'block';
        if(placeholder) placeholder.style.display = 'none';

        // Masukkan data ke Hidden Input
        document.getElementById('cropped_image_data').value = base64Image;

        closeCropModal();
    }

    function closeCropModal() {
        cropModal.style.opacity = '0';
        setTimeout(() => {
            cropModal.style.display = 'none';
            if(cropper) cropper.destroy();
        }, 300);
    }
</script>

<?php include 'popupcustom.php'; ?>
</body>
</html>