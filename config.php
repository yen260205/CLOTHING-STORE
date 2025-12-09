<?php
// Thông tin database InfinityFree
$db_host = 'sql104.infinityfree.com';
$db_user = 'if0_40572983';
$db_pass = 'Ag27Qk5M7j21wJ';
$db_name = 'if0_40572983_clothing_store_db';
$db_port = 3306;

define('DB_HOST', $db_host);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_NAME', $db_name);
define('DB_PORT', $db_port);

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getDBConnection(): mysqli {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if (!$conn) {
        die("Kết nối thất bại: " . mysqli_connect_error());
    }
    mysqli_set_charset($conn, "utf8mb4");
    return $conn;
}

function redirect(string $url): void {
    header("Location: $url");
    exit();
}

// CHỈ trim input; escape khi output bằng e()
function cleanInput($data): string {
    return trim(stripslashes((string)$data));
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Auth helpers
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}
function isAdmin(): bool {
    return (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
}
function requireLogin(): void {
    if (!isLoggedIn()) redirect('login.php');
}

// CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_validate($token): bool {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}
