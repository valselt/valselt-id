<?php
if (isset($_SESSION['popup_status']) && isset($_SESSION['popup_message'])) {
    $status = $_SESSION['popup_status']; 
    $message = $_SESSION['popup_message'];
    
    // Cek apakah ada judul custom, jika tidak pakai default
    $title = isset($_SESSION['popup_title']) ? $_SESSION['popup_title'] : '';
    
    // Cek apakah ada request redirect otomatis
    $redirect = isset($_SESSION['popup_redirect']) ? $_SESSION['popup_redirect'] : null;

    // Tentukan Ikon & Style berdasarkan Status
    if ($status == 'success') {
        $icon = "<i class='bx bx-check'></i>";
        if(empty($title)) $title = "Berhasil!";
        $btn_text = "Lanjutkan";
        $btn_class = "success";
    } elseif ($status == 'warning') {
        $icon = "<i class='bx bx-error'></i>"; // Tanda Seru
        if(empty($title)) $title = "Peringatan!";
        $btn_text = "Tutup";
        $btn_class = "warning";
    } else {
        $icon = "<i class='bx bx-x'></i>";
        if(empty($title)) $title = "Gagal!";
        $btn_text = "Coba Lagi";
        $btn_class = "error";
    }
?>
    <div class="popup-overlay" id="customPopup">
        <div class="popup-box">
            <div class="popup-icon-box <?php echo $status; ?>">
                <?php echo $icon; ?>
            </div>
            <h3 class="popup-title"><?php echo $title; ?></h3>
            <p class="popup-message"><?php echo $message; ?></p>
            
            <button onclick="closePopup()" id="popupBtn" class="popup-btn <?php echo $btn_class; ?>">
                <?php echo $btn_text; ?>
            </button>
        </div>
    </div>

    <script>
        function closePopup() {
            const popup = document.getElementById('customPopup');
            popup.style.opacity = '0';
            setTimeout(() => {
                popup.remove();
            }, 300);
        }

        // --- LOGIC AUTO REDIRECT (COUNTDOWN) ---
        <?php if ($redirect): ?>
        document.addEventListener("DOMContentLoaded", function() {
            let seconds = 5; // Waktu hitung mundur
            const btn = document.getElementById('popupBtn');
            const targetUrl = "<?php echo $redirect; ?>";

            // Disable tombol agar user notice ini otomatis
            btn.disabled = true;
            btn.style.opacity = "0.7";
            btn.innerText = `Dialihkan dalam ${seconds}...`;

            const interval = setInterval(() => {
                seconds--;
                btn.innerText = `Dialihkan dalam ${seconds}...`;

                if (seconds <= 0) {
                    clearInterval(interval);
                    window.location.href = targetUrl;
                }
            }, 1000);
        });
        <?php endif; ?>
    </script>
<?php
    // Bersihkan semua session popup setelah ditampilkan
    unset($_SESSION['popup_status']);
    unset($_SESSION['popup_message']);
    unset($_SESSION['popup_title']);
    unset($_SESSION['popup_redirect']);
}
?>