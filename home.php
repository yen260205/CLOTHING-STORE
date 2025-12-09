<?php
require_once 'config.php';
require_once 'validation.php';

requireLogin();
$conn = getDBConnection();

$userId = (int)($_SESSION['user_id'] ?? 0);
$page = $_GET['page'] ?? 'products'; // products | cart | orders | admin
$page = in_array($page, ['products','cart','orders','admin'], true) ? $page : 'products';

$errors = [];
$success = '';

function stmt_fetch_all(mysqli_stmt $stmt): array {
    $res = mysqli_stmt_get_result($stmt);
    if (!$res) return [];
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

/** =========================
 *  POST ACTIONS (CSRF + prepared statements)
 *  ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $errors[] = "CSRF token không hợp lệ. Vui lòng tải lại trang.";
    } else {
        $action = $_POST['action'] ?? '';

        /** ---- USER: ADD TO CART ---- */
        if ($action === 'add_to_cart') {
            $variantId = (int)($_POST['variant_id'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 1);
            if ($variantId <= 0) $errors[] = "Vui lòng chọn biến thể.";
            if ($qty <= 0) $errors[] = "Số lượng phải >= 1.";

            if (empty($errors)) {
                // Check variant stock
                $stmt = mysqli_prepare($conn, "SELECT stock FROM product_variants WHERE id = ? LIMIT 1");
                mysqli_stmt_bind_param($stmt, "i", $variantId);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if (!$row) {
                    $errors[] = "Biến thể không tồn tại.";
                } else {
                    $stock = (int)$row['stock'];

                    // Check current cart qty
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
                            $stmt = mysqli_prepare($conn, "UPDATE cart SET quantity = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                            mysqli_stmt_bind_param($stmt, "iii", $newQty, $cur['id'], $userId);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        } else {
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
