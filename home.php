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

        /** ---- ADMIN ACTIONS ---- */
        if (strpos($action, 'admin_') === 0) {
            if (!isAdmin()) {
                $errors[] = "Bạn không có quyền admin.";
            } else {

                if ($action === 'admin_add_product') {
                    $name = trim($_POST['name'] ?? '');
                    $price = (float)($_POST['price'] ?? 0);
                    $type = trim($_POST['type'] ?? '');
                    $brand = trim($_POST['brand'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $size = trim($_POST['size'] ?? '');
                    $color = trim($_POST['color'] ?? '');
                    $stock = (int)($_POST['stock'] ?? 0);

                    if ($name === '') $errors[] = "Tên sản phẩm không được để trống.";
                    if ($price < 0) $errors[] = "Giá không hợp lệ.";
                    if ($size === '' || $color === '') $errors[] = "Variant size/color không được để trống.";
                    if ($stock < 0) $errors[] = "Stock không hợp lệ.";

                    $imagePath = null;
                    if (empty($errors)) {
                        $imagePath = save_uploaded_image('product_image', $errors);
                    }

                    if (empty($errors)) {
                        mysqli_begin_transaction($conn);
                        try {
                            $stmt = mysqli_prepare($conn, "INSERT INTO products (name, price, image, description, type, brand) VALUES (?,?,?,?,?,?)");
                            mysqli_stmt_bind_param($stmt, "sdssss", $name, $price, $imagePath, $description, $type, $brand);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);

                            $newProductId = (int)mysqli_insert_id($conn);

                            $stmt = mysqli_prepare($conn, "INSERT INTO product_variants (product_id, size, color, stock) VALUES (?,?,?,?)");
                            mysqli_stmt_bind_param($stmt, "issi", $newProductId, $size, $color, $stock);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);

                            mysqli_commit($conn);
                            $success = "Đã thêm product (#$newProductId) + variant ($size/$color).";
                            $page = 'admin';
                        } catch (Exception $e) {
                            mysqli_rollback($conn);
                            $errors[] = "Thêm product thất bại: " . $e->getMessage();
                        }
                    }
                }

                if ($action === 'admin_update_product') {
                    $productId = (int)($_POST['product_id'] ?? 0);
                    $name = trim($_POST['name'] ?? '');
                    $price = (float)($_POST['price'] ?? 0);
                    $type = trim($_POST['type'] ?? '');
                    $brand = trim($_POST['brand'] ?? '');
                    $description = trim($_POST['description'] ?? '');

                    if ($productId <= 0) $errors[] = "Product không hợp lệ.";
                    if ($name === '') $errors[] = "Tên sản phẩm không được để trống.";
                    if ($price < 0) $errors[] = "Giá không hợp lệ.";

                    $newImage = null;
                    if (empty($errors)) {
                        $newImage = save_uploaded_image('product_image', $errors); // optional
                    }

                    if (empty($errors)) {
                        try {
                            // fetch old image if replacing
                            $oldImage = null;
                            if ($newImage) {
                                $stmt = mysqli_prepare($conn, "SELECT image FROM products WHERE id = ? LIMIT 1");
                                mysqli_stmt_bind_param($stmt, "i", $productId);
                                mysqli_stmt_execute($stmt);
                                $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                                mysqli_stmt_close($stmt);
                                $oldImage = $r['image'] ?? null;
                            }

                            if ($newImage) {
                                $stmt = mysqli_prepare($conn, "UPDATE products SET name=?, price=?, image=?, description=?, type=?, brand=? WHERE id=?");
                                mysqli_stmt_bind_param($stmt, "sdssssi", $name, $price, $newImage, $description, $type, $brand, $productId);
                            } else {
                                $stmt = mysqli_prepare($conn, "UPDATE products SET name=?, price=?, description=?, type=?, brand=? WHERE id=?");
                                mysqli_stmt_bind_param($stmt, "sdsssi", $name, $price, $description, $type, $brand, $productId);
                            }
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);

                            // best-effort delete old file
                            if ($newImage && $oldImage && str_starts_with($oldImage, 'uploads/')) {
                                $oldFull = realpath(__DIR__ . '/' . $oldImage);
                                $uploadsFull = realpath(__DIR__ . '/uploads');
                                if ($oldFull && $uploadsFull && str_starts_with($oldFull, $uploadsFull)) {
                                    @unlink($oldFull);
                                }
                            }

                            $success = "Đã cập nhật product (#$productId).";
                            $page = 'admin';
                        } catch (Exception $e) {
                            $errors[] = "Cập nhật product thất bại: " . $e->getMessage();
                        }
                    }
                }

                if ($action === 'admin_delete_product') {
                    $productId = (int)($_POST['product_id'] ?? 0);
                    if ($productId <= 0) {
                        $errors[] = "Product không hợp lệ.";
                    } else {
                        try {
                            $stmt = mysqli_prepare($conn, "DELETE FROM products WHERE id = ?");
                            mysqli_stmt_bind_param($stmt, "i", $productId);
                            mysqli_stmt_execute($stmt);
                            $aff = mysqli_stmt_affected_rows($stmt);
                            mysqli_stmt_close($stmt);

                            if ($aff !== 1) {
                                $errors[] = "Không xoá được product (có thể không tồn tại).";
                            } else {
                                $success = "Đã xoá product (#$productId).";
                                $page = 'admin';
                            }
                        } catch (Exception $e) {
                            $errors[] = "Xoá product thất bại: " . $e->getMessage();
                        }
                    }
                }

                if ($action === 'admin_add_variant') {
                    $productId = (int)($_POST['product_id'] ?? 0);
                    $size = trim($_POST['size'] ?? '');
                    $color = trim($_POST['color'] ?? '');
                    $stock = (int)($_POST['stock'] ?? 0);

                    if ($productId <= 0) $errors[] = "Product không hợp lệ.";
                    if ($size === '' || $color === '') $errors[] = "Size/color không được để trống.";
                    if ($stock < 0) $errors[] = "Stock không hợp lệ.";

                    if (empty($errors)) {
                        try {
                            $stmt = mysqli_prepare($conn, "INSERT INTO product_variants (product_id, size, color, stock) VALUES (?,?,?,?)");
                            mysqli_stmt_bind_param($stmt, "issi", $productId, $size, $color, $stock);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);

                            $success = "Đã thêm variant ($size/$color) cho product #$productId.";
                            $page = 'admin';
                        } catch (Exception $e) {
                            $errors[] = "Thêm variant thất bại: " . $e->getMessage();
                        }
                    }
                }

                if ($action === 'admin_update_variant') {
                    $variantId = (int)($_POST['variant_id'] ?? 0);
                    $productId = (int)($_POST['product_id'] ?? 0);
                    $size = trim($_POST['size'] ?? '');
                    $color = trim($_POST['color'] ?? '');
                    $stock = (int)($_POST['stock'] ?? 0);

                    if ($variantId <= 0 || $productId <= 0) $errors[] = "Variant/Product không hợp lệ.";
                    if ($size === '' || $color === '') $errors[] = "Size/color không được để trống.";
                    if ($stock < 0) $errors[] = "Stock không hợp lệ.";

                    if (empty($errors)) {
                        try {
                            $stmt = mysqli_prepare($conn, "UPDATE product_variants SET size=?, color=?, stock=? WHERE id=? AND product_id=?");
                            mysqli_stmt_bind_param($stmt, "ssiii", $size, $color, $stock, $variantId, $productId);
                            mysqli_stmt_execute($stmt);
                            $aff = mysqli_stmt_affected_rows($stmt);
                            mysqli_stmt_close($stmt);

                            if ($aff < 0) {
                                $errors[] = "Cập nhật variant thất bại.";
                            } else {
                                $success = "Đã cập nhật variant #$variantId.";
                                $page = 'admin';
                            }
                        } catch (Exception $e) {
                            $errors[] = "Cập nhật variant thất bại: " . $e->getMessage();
                        }
                    }
                }

                if ($action === 'admin_delete_variant') {
                    $variantId = (int)($_POST['variant_id'] ?? 0);
                    $productId = (int)($_POST['product_id'] ?? 0);

                    if ($variantId <= 0 || $productId <= 0) {
                        $errors[] = "Variant/Product không hợp lệ.";
                    } else {
                        try {
                            $stmt = mysqli_prepare($conn, "DELETE FROM product_variants WHERE id = ? AND product_id = ?");
                            mysqli_stmt_bind_param($stmt, "ii", $variantId, $productId);
                            mysqli_stmt_execute($stmt);
                            $aff = mysqli_stmt_affected_rows($stmt);
                            mysqli_stmt_close($stmt);

                            if ($aff !== 1) {
                                $errors[] = "Không xoá được variant (có thể không tồn tại).";
                            } else {
                                $success = "Đã xoá variant #$variantId.";
                                $page = 'admin';
                            }
                        } catch (Exception $e) {
                            $errors[] = "Xoá variant thất bại: " . $e->getMessage();
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

// Orders
$orders = [];
if ($page === 'orders') {
    $stmt = mysqli_prepare($conn, "SELECT id, status, total, note, created_at FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $orders = stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
}

$adminOrders = [];
if ($page === 'admin' && isAdmin()) {
    $stmt = mysqli_prepare($conn, "
        SELECT o.id, o.status, o.total, o.note, o.created_at,
               u.full_name AS user_name,
               u.email     AS user_email
        FROM orders o
        JOIN users u ON u.id = o.user_id
        ORDER BY o.created_at DESC
        LIMIT 20
    ");
    mysqli_stmt_execute($stmt);
    $adminOrders = stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Clothing Store</title>
    <style>
      *{box-sizing:border-box}
      html, body { height: 100%; }

      body{
        margin: 0;
        color: #e7eaf0;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;

        background-color: #0b1220; /* fallback khi ảnh lỗi */
        background-image: url("images/background_home3.jpg");
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
      }

      a{
        color:inherit;
        text-decoration:none
      }

 /* ===== TOP BAR: SOLID (không trong suốt) ===== */
      .topbar{
        position:sticky;
        top:0;
        z-index:10;
        background:#0d1424;          /* solid */
        border-bottom:1px solid rgba(255,255,255,.10);
        backdrop-filter:none;         /* bỏ blur glass */
      }

      .wrap{
        max-width:1100px;
        margin:0 auto;
        padding:14px 16px
      }

      .row{
        display:flex;
        gap:12px;
        align-items:center;
        justify-content:space-between;
        flex-wrap:wrap
      }

      .brand{
        display:flex;
        gap:10px;
        align-items:center;
        font-weight:800;
        letter-spacing:.3px
      }

      /* pill + nav: SOLID */
      .pill{
        padding:6px 10px;
        border-radius:999px;
        border:1px solid rgba(255,255,255,.12);
        background:#111c33; /* solid */
        font-size:13px
      }

      .nav{
        display:flex;
        gap:10px;
        flex-wrap:wrap
      }

      .nav a{
        padding:8px 12px;
        border-radius:10px;
        border:1px solid rgba(255,255,255,.12);
        background:#111c33; /* solid */
      }

      .nav a.active{
        background:linear-gradient(135deg, rgba(102,126,234,.55), rgba(118,75,162,.55));
        border-color:rgba(255,255,255,.18)
      }
