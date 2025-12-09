<?php
require_once 'config.php';

// Xóa tất cả session variables
$_SESSION = array();

// Xóa session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Hủy session
session_destroy();

// Xóa cookie remember me (tùy chọn - có thể giữ lại nếu muốn)
// setcookie('remember_user', '', time() - 3600, '/');

// Redirect về trang login với thông báo
redirect('login.php');
?>