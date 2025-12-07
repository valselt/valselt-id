<?php
session_start();
session_destroy(); 

// Cek apakah ada permintaan redirect khusus setelah logout
if (isset($_GET['continue'])) {
    header("Location: " . $_GET['continue']);
} else {
    header("Location: login.php");
}
exit();
?>