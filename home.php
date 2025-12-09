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
            $size = $_POST['size'] ?? '';
            $color = $_POST['color'] ?? '';
            $qty = (int)($_POST['quantity'] ?? 1);

            if (empty($size) || empty($color)) {
                $errors[] = "Vui lòng chọn kích thước và màu sắc.";
            }

            if ($qty <= 0) {
                $errors[] = "Số lượng phải >= 1.";
            }

            if (empty($errors)) {
                // Kiểm tra biến thể với size và color
                $stmt = mysqli_prepare($conn, "SELECT id FROM product_variants WHERE product_id = ? AND size = ? AND color = ? LIMIT 1");
                mysqli_stmt_bind_param($stmt, "iss", $productId, $size, $color);
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

/** =========================
 *  DATA FETCH FOR PAGES
 *  ========================= */

// Products + variants (grouped)
$products = [];
if ($page === 'products' || $page === 'admin') {
    $orderBy = ($page === 'admin' && isAdmin())
        ? "ORDER BY p.id ASC, v.size ASC, v.color ASC"
        : "ORDER BY p.created_at DESC, v.size ASC, v.color ASC";

    $sql = "
        SELECT p.id AS product_id, p.name, p.price, p.image, p.description, p.type, p.brand, p.created_at,
               v.id AS variant_id, v.size, v.color, v.stock
        FROM products p
        LEFT JOIN product_variants v ON v.product_id = p.id
        $orderBy
    ";
    $res = mysqli_query($conn, $sql);
    $map = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $pid = (int)$r['product_id'];
        if (!isset($map[$pid])) {
            $map[$pid] = [
                'id' => $pid,
                'name' => $r['name'],
                'price' => (float)$r['price'],
                'image' => $r['image'],
                'description' => $r['description'],
                'type' => $r['type'],
                'brand' => $r['brand'],
                'created_at' => $r['created_at'],
                'variants' => []
            ];
        }
        if (!empty($r['variant_id'])) {
            $map[$pid]['variants'][] = [
                'id' => (int)$r['variant_id'],
                'size' => $r['size'],
                'color' => $r['color'],
                'stock' => (int)$r['stock']
            ];
        }
    }
    $products = array_values($map);
}

// Cart items
$cartItems = [];
$cartTotal = 0.0;
if ($page === 'cart') {
    $stmt = mysqli_prepare($conn, "
        SELECT c.id AS cart_id, c.quantity,
               v.id AS variant_id, v.size, v.color, v.stock,
               p.id AS product_id, p.name, p.price, p.image
        FROM cart c
        JOIN product_variants v ON v.id = c.product_variant_id
        JOIN products p ON p.id = v.product_id
        WHERE c.user_id = ?
        ORDER BY c.updated_at DESC
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $cartItems = stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);

    foreach ($cartItems as $it) {
        $cartTotal += ((float)$it['price']) * ((int)$it['quantity']);
    }
}
