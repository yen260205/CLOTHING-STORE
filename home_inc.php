<?php
require_once 'config.php';
require_once 'validation.php';

requireLogin();
$conn = getDBConnection();

$userId = (int)($_SESSION['user_id'] ?? 0);
$page = $_GET['page'] ?? 'products'; // products | cart | orders | admin
$page = in_array($page, ['products', 'cart', 'orders', 'admin'], true) ? $page : 'products';

$errors = [];
$success = '';

function stmt_fetch_all(mysqli_stmt $stmt): array {
    $res = mysqli_stmt_get_result($stmt);
    if (!$res) return [];
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

/**
 * Save uploaded image to /uploads and return relative path (e.g. uploads/xxx.jpg)
 * Returns null if no file uploaded. Pushes errors on failure.
 */
function save_uploaded_image(string $field, array &$errors): ?string {
    // Upload ảnh sản phẩm (lưu vào uploads/products) và trả về đường dẫn tương đối để lưu DB.
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;

    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;

    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = "Upload ảnh thất bại.";
        return null;
    }

    $max = 5 * 1024 * 1024; // 5MB
    if (($f['size'] ?? 0) > $max) {
        $errors[] = "Ảnh quá lớn (tối đa 5MB).";
        return null;
    }

    $tmp = $f['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errors[] = "File upload không hợp lệ.";
        return null;
    }

    // Check mime thật (không tin ext)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmp) : null;
    if ($finfo) finfo_close($finfo);

    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!$mime || !isset($extMap[$mime])) {
        $errors[] = "Định dạng ảnh không được hỗ trợ (jpg/png/webp/gif).";
        return null;
    }

    $uploadDir = __DIR__ . '/uploads/products';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            $errors[] = "Không tạo được thư mục uploads/products.";
            return null;
        }
    }

    $filename = 'p_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        $errors[] = "Lưu ảnh thất bại.";
        return null;
    }

    return 'uploads/products/' . $filename;
}



/** =========================
 *  FLASH (PRG) + HELPERS
 *  ========================= */
if (!empty($_SESSION['flash_errors']) && is_array($_SESSION['flash_errors'])) {
    $errors = $_SESSION['flash_errors'];
    unset($_SESSION['flash_errors']);
}
if (!empty($_SESSION['flash_success']) && is_string($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

function starts_with(string $haystack, string $needle): bool {
    $n = strlen($needle);
    return $n === 0 || strncmp($haystack, $needle, $n) === 0;
}

/**
 * Chuẩn hoá đường dẫn ảnh sản phẩm để tương thích cả DB cũ và mới.
 * - Nếu DB lưu "uploads/products/xxx.jpg" => dùng thẳng
 * - Nếu DB lưu "uploads/xxx.jpg" => dùng thẳng
 * - Nếu DB chỉ lưu "xxx.jpg" => auto prefix "uploads/products/"
 */
function resolve_product_image_src(?string $dbValue): string {
    $v = trim((string)$dbValue);
    if ($v === '') return '';

    // Nếu là URL ngoài
    if (preg_match('~^https?://~i', $v)) return $v;

    $v = ltrim($v, '/');

    if (starts_with($v, 'uploads/')) {
        return $v;
    }

    return 'uploads/products/' . $v;
}

function local_file_exists(string $src): bool {
    if ($src === '') return false;
    if (preg_match('~^https?://~i', $src)) return true; // không check được file ngoài
    $p = __DIR__ . '/' . ltrim($src, '/');
    return file_exists($p);
}

// Giảm sai số float: tính tiền theo cents nội bộ, lưu DB dạng DECIMAL string.
function price_to_cents($price): int {
    return (int)round(((float)$price) * 100);
}
function cents_to_decimal_string(int $cents): string {
    return number_format($cents / 100, 2, '.', '');
}

function redirect_home_with_flash(string $page, array $errs = [], string $ok = ''): void {
    if (!empty($errs)) $_SESSION['flash_errors'] = $errs;
    if ($ok !== '') $_SESSION['flash_success'] = $ok;
    header('Location: home.php?page=' . urlencode($page));
    exit;
}

