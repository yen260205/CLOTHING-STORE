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
  $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            $errors[] = "Không tạo được thư mục uploads.";
            return null;
        }
    }

    $filename = 'p_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        $errors[] = "Lưu ảnh thất bại.";
        return null;
    }

    return 'uploads/' . $filename;
}

