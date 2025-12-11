<?php
require 'config.php';

if (!isset($_SESSION['valselt_user_id'])) {
    header("Location: login.php"); exit();
}

$user_id = $_SESSION['valselt_user_id'];

// --- UPDATE PROFILE LOGIC ---
if (isset($_POST['update_profile'])) {
    $new_username = htmlspecialchars($_POST['username']);
    $new_email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $new_pass = $_POST['password'];
    
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
    
    $_SESSION['valselt_username'] = $new_username;
    $_SESSION['popup_status'] = 'success';
    $_SESSION['popup_message'] = 'Profil berhasil diperbarui!';
    header("Location: index.php");
    exit();
}

// --- HAPUS AKUN ---
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

                <div class="form-group">
                    <label class="form-label">Password Baru <span style="font-weight:400; color:var(--text-muted); font-size:0.8rem;">(Opsional)</span></label>
                    <input type="password" name="password" class="form-control" placeholder="******">
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>

        <div style="background: #f9fafb; padding: 20px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #e5e7eb; margin-top:40px;">
            <h4 style="margin-bottom: 15px; font-weight:600;">Linked Accounts</h4>
            
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
        </div>

        <hr style="border:0; border-top:1px solid #e5e7eb; margin:40px 0;">

        <div style="text-align:center;">
             <form method="POST" onsubmit="return confirm('Yakin ingin menghapus akun? Ini permanen!');" style="margin-bottom:15px;">
                <button type="submit" name="delete_account" style="background:none; border:none; color:var(--danger); font-weight:600; cursor:pointer; font-family:var(--font-sans);">
                    <i class='bx bx-trash'></i> Hapus Akun Saya Permanen
                </button>
            </form>
            
            <a href="logout.php" class="btn btn-logout" style="display:inline-block; text-decoration:none; padding:10px 20px; border-radius:8px;">Keluar / Logout</a>
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
            <button type="button" onclick="cropImage()" class="popup-btn success">Selesai</button>
        </div>
    </div>
</div>

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