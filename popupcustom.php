<?php
if (isset($_SESSION['popup_status']) && isset($_SESSION['popup_message'])) {
    $status = $_SESSION['popup_status']; 
    $message = $_SESSION['popup_message'];
    $title = isset($_SESSION['popup_title']) ? $_SESSION['popup_title'] : '';
    $redirect = isset($_SESSION['popup_redirect']) ? $_SESSION['popup_redirect'] : null;

    // Tentukan Icon & Kelas
    if ($status == 'success') {
        $icon = "<i class='bx bx-check'></i>";
        if(empty($title)) $title = "Success";
        $btn_text = "Continue";
        $btn_class = "success";
    } elseif ($status == 'warning') {
        $icon = "<i class='bx bx-error'></i>"; 
        if(empty($title)) $title = "Warning";
        $btn_text = "Close";
        $btn_class = "warning";
    } else {
        $icon = "<i class='bx bx-x'></i>";
        if(empty($title)) $title = "Error";
        $btn_text = "Try Again";
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
        // OPEN animation
        document.addEventListener("DOMContentLoaded", () => {
            const popup = document.getElementById('customPopup');
            popup.classList.add("show");
        });

        // CLOSE with Apple-like effect
        function closePopup() {
            const popup = document.getElementById('customPopup');
            const box = popup.querySelector('.popup-box');

            // Animasi keluar
            popup.style.opacity = '0';
            popup.style.backdropFilter = 'blur(0px)';
            box.style.transform = 'scale(0.93) translateY(12px)';
            box.style.opacity = '0';

            setTimeout(() => popup.remove(), 350);
        }

        <?php if ($redirect): ?>
        document.addEventListener("DOMContentLoaded", function() {
            let seconds = 3; 
            const btn = document.getElementById('popupBtn');
            const targetUrl = "<?php echo $redirect; ?>";

            if (btn) {
                btn.disabled = true;
                btn.style.opacity = "0.7";
                btn.innerText = `Redirecting in ${seconds}...`;

                const interval = setInterval(() => {
                    seconds--;
                    btn.innerText = `Redirecting in ${seconds}...`;
                    if (seconds <= 0) {
                        clearInterval(interval);
                        window.location.href = targetUrl;
                    }
                }, 1000);
            }
        });
        <?php endif; ?>
    </script>

<?php
    unset($_SESSION['popup_status']);
    unset($_SESSION['popup_message']);
    unset($_SESSION['popup_title']);
    unset($_SESSION['popup_redirect']);
}
?>