<?php
if (isset($_SESSION['popup_status']) && isset($_SESSION['popup_message'])) {
    $status = $_SESSION['popup_status']; 
    $message = $_SESSION['popup_message'];
    $title = isset($_SESSION['popup_title']) ? $_SESSION['popup_title'] : '';
    $redirect = isset($_SESSION['popup_redirect']) ? $_SESSION['popup_redirect'] : null;

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
            <div class="popup-icon-box <?php echo $status; ?>" style="width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:2rem; margin:0 auto 20px auto; <?php if($status=='success') echo 'background:#dcfce7; color:#166534;'; elseif($status=='error') echo 'background:#fee2e2; color:#991b1b;'; else echo 'background:#fef9c3; color:#854d0e;'; ?>">
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
            setTimeout(() => { popup.remove(); }, 300);
        }

        <?php if ($redirect): ?>
        document.addEventListener("DOMContentLoaded", function() {
            let seconds = 3; 
            const btn = document.getElementById('popupBtn');
            const targetUrl = "<?php echo $redirect; ?>";

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