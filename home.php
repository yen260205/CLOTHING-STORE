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

        /** ---- USER: REMOVE CART ---- */
        if ($action === 'remove_cart') {
            $cartId = (int)($_POST['cart_id'] ?? 0);
            if ($cartId <= 0) $errors[] = "Cart item không hợp lệ.";
            if (empty($errors)) {
                $stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE id = ? AND user_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $cartId, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $success = "Đã xoá item khỏi giỏ.";
                $page = 'cart';
            }
        }

                /** ---- USER: CHECKOUT ---- */
        if ($action === 'checkout') {
            $note = cleanInput($_POST['note'] ?? '');

            // Get cart items
            $stmt = mysqli_prepare($conn, "
                SELECT c.id AS cart_id, c.quantity,
                       v.id AS variant_id, v.size, v.color, v.stock,
                       p.id AS product_id, p.name, p.price
                FROM cart c
                JOIN product_variants v ON v.id = c.product_variant_id
                JOIN products p ON p.id = v.product_id
                WHERE c.user_id = ?
                ORDER BY c.updated_at DESC
            ");
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            $items = stmt_fetch_all($stmt);
            mysqli_stmt_close($stmt);

            if (empty($items)) {
                $errors[] = "Giỏ hàng đang trống.";
            } else {
                // Calculate total + basic stock check
                $total = 0.0;
                foreach ($items as $it) {
                    if ((int)$it['quantity'] > (int)$it['stock']) {
                        $errors[] = "Biến thể #{$it['variant_id']} không đủ tồn kho (còn {$it['stock']}).";
                    }
                    $total += ((float)$it['price']) * ((int)$it['quantity']);
                }
            }

            if (empty($errors)) {
                mysqli_begin_transaction($conn);
                try {
                    // Insert order
                    $stmt = mysqli_prepare($conn, "INSERT INTO orders (user_id, status, note, total) VALUES (?, 'pending', ?, ?)");
                    mysqli_stmt_bind_param($stmt, "isd", $userId, $note, $total);
                    mysqli_stmt_execute($stmt);
                    $orderId = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    // For each item: insert detail + reduce stock atomically
                    foreach ($items as $it) {
                        $variantId = (int)$it['variant_id'];
                        $qty = (int)$it['quantity'];
                        $price = (float)$it['price'];

                        // Reduce stock only if enough
                        $stmt = mysqli_prepare($conn, "UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");
                        mysqli_stmt_bind_param($stmt, "iii", $qty, $variantId, $qty);
                        mysqli_stmt_execute($stmt);
                        $affected = mysqli_stmt_affected_rows($stmt);
                        mysqli_stmt_close($stmt);

                        if ($affected !== 1) {
                            throw new Exception("Tồn kho thay đổi. Vui lòng thử lại (variant #$variantId).");
                        }

                        $stmt = mysqli_prepare($conn, "INSERT INTO order_detail (order_id, product_variant_id, quantity, price) VALUES (?, ?, ?, ?)");
                        mysqli_stmt_bind_param($stmt, "iiid", $orderId, $variantId, $qty, $price);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }

                    // Clear cart
                    $stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE user_id = ?");
                    mysqli_stmt_bind_param($stmt, "i", $userId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    mysqli_commit($conn);
                    $success = "Checkout thành công! Mã đơn hàng: #$orderId";
                    $page = 'orders';
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $errors[] = "Checkout thất bại: " . $e->getMessage();
                    $page = 'cart';
                }
            }
        }
    }
}
