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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $errors[] = "CSRF token không hợp lệ. Vui lòng tải lại trang.";
    } else {
        $action = $_POST['action'] ?? ''; 

     if ($action === 'add_to_cart') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $postedVariantId = (int)($_POST['variant_id'] ?? 0);
            $size = $_POST['size'] ?? '';
            $color = $_POST['color'] ?? '';
            $qty = (int)($_POST['quantity'] ?? 1);


            if ($productId <= 0) {
                $errors[] = "Sản phẩm không hợp lệ.";
            }
            if (empty($size) || empty($color)) {
                $errors[] = "Vui lòng chọn kích thước và màu sắc.";
            }

            if ($qty <= 0) {
                $errors[] = "Số lượng phải >= 1.";
            }

            if (empty($errors)) {
                // Kiểm tra biến thể với size và color
                if ($postedVariantId > 0) {
                    $stmt = mysqli_prepare($conn, "SELECT id FROM product_variants WHERE id = ? AND product_id = ? AND size = ? AND color = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "iiss", $postedVariantId, $productId, $size, $color);
                } else {
                    $stmt = mysqli_prepare($conn, "SELECT id FROM product_variants WHERE product_id = ? AND size = ? AND color = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "iss", $productId, $size, $color);
                }
                mysqli_stmt_execute($stmt);
                $variantResult = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if (!$variantResult) {
                    $errors[] = "Biến thể không tồn tại.";
                } else {
                    $variantId = (int)$variantResult['id'];

                    // Kiểm tra tồn kho
                    $stmt = mysqli_prepare($conn, "SELECT stock FROM product_variants WHERE id = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "i", $variantId);
                    mysqli_stmt_execute($stmt);
                    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);

                    $stock = (int)$row['stock'];

                    // Kiểm tra giỏ hàng hiện tại
                    $stmt = mysqli_prepare($conn, "SELECT id, quantity FROM cart WHERE user_id = ? AND product_variant_id = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "ii", $userId, $variantId);
                    mysqli_stmt_execute($stmt);
                    $cur = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);

                    $newQty = $qty + ($cur ? (int)$cur['quantity'] : 0);

                    if ($newQty > $stock) {
                        $errors[] = "Tồn kho không đủ. Hiện còn $stock.";
                    } else {
                        if ($cur) {
                            // Update giỏ hàng
                            $stmt = mysqli_prepare($conn, "UPDATE cart SET quantity = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                            mysqli_stmt_bind_param($stmt, "iii", $newQty, $cur['id'], $userId);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        } else {
                            // Insert vào giỏ hàng
                            $stmt = mysqli_prepare($conn, "INSERT INTO cart (user_id, product_variant_id, quantity) VALUES (?, ?, ?)");
                            mysqli_stmt_bind_param($stmt, "iii", $userId, $variantId, $qty);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }
                        $success = "Đã thêm vào giỏ hàng!";
                        $page = 'cart';
                    }
                }
            }
        }
     






 
        /** ---- USER: UPDATE CART ---- */
        if ($action === 'update_cart') {
            $cartId = (int)($_POST['cart_id'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 1);

            if ($cartId <= 0) $errors[] = "Cart item không hợp lệ.";

            if (empty($errors)) {
                // Ensure ownership + get stock
                $stmt = mysqli_prepare($conn, "
                    SELECT c.id, c.product_variant_id, v.stock
                    FROM cart c
                    JOIN product_variants v ON v.id = c.product_variant_id
                    WHERE c.id = ? AND c.user_id = ?
                    LIMIT 1
                ");
                mysqli_stmt_bind_param($stmt, "ii", $cartId, $userId);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if (!$row) {
                    $errors[] = "Không tìm thấy cart item.";
                } else {
                    $stock = (int)$row['stock'];

                    if ($qty <= 0) {
                        $stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE id = ? AND user_id = ?");
                        mysqli_stmt_bind_param($stmt, "ii", $cartId, $userId);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        $success = "Đã xoá item khỏi giỏ.";
                    } else {
                        if ($qty > $stock) {
                            $errors[] = "Tồn kho không đủ. Hiện còn $stock.";
                        } else {
                            $stmt = mysqli_prepare($conn, "UPDATE cart SET quantity = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                            mysqli_stmt_bind_param($stmt, "iii", $qty, $cartId, $userId);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                            $success = "Đã cập nhật giỏ hàng.";
                        }
                    }
                    $page = 'cart';
                }
            }
        }
