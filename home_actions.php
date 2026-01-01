<?php
/** =========================
 *  POST ACTIONS (CSRF + prepared statements) + PRG
 *  ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    $errs = [];
    $ok = '';
    $redirectPage = $page; // mặc định quay về page hiện tại

    if (!csrf_validate($token)) {
        $errs[] = "CSRF token không hợp lệ. Vui lòng tải lại trang.";
        redirect_home_with_flash($redirectPage, $errs, '');
    }

    // =========================
    // USER ACTIONS
    // =========================
    if (!starts_with($action, 'admin_')) {

        if ($action === 'add_to_cart') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $postedVariantId = (int)($_POST['variant_id'] ?? 0);
            $size = trim((string)($_POST['size'] ?? ''));
            $color = trim((string)($_POST['color'] ?? ''));
            $qty = (int)($_POST['quantity'] ?? 1);

            if ($productId <= 0) $errs[] = "Sản phẩm không hợp lệ.";
            if ($size === '' || $color === '') $errs[] = "Vui lòng chọn kích thước và màu sắc.";
            if ($qty <= 0) $errs[] = "Số lượng phải >= 1.";

            if (empty($errs)) {
                // 1) xác định variant
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
                    $errs[] = "Biến thể không tồn tại.";
                } else {
                    $variantId = (int)$variantResult['id'];

                    // 2) check stock
                    $stmt = mysqli_prepare($conn, "SELECT stock FROM product_variants WHERE id = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "i", $variantId);
                    mysqli_stmt_execute($stmt);
                    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);
                    $stock = (int)($row['stock'] ?? 0);

                    // 3) check cart hiện tại
                    $stmt = mysqli_prepare($conn, "SELECT id, quantity FROM cart WHERE user_id = ? AND product_variant_id = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "ii", $userId, $variantId);
                    mysqli_stmt_execute($stmt);
                    $cur = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);

                    $newQty = $qty + ($cur ? (int)$cur['quantity'] : 0);
                    if ($newQty > $stock) {
                        $errs[] = "Tồn kho không đủ. Hiện còn $stock.";
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
                        $ok = "Đã thêm vào giỏ hàng!";
                        $redirectPage = 'cart';
                    }
                }
            } else {
                $redirectPage = 'products';
            }
        }

        if ($action === 'update_cart') {
            $cartId = (int)($_POST['cart_id'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 1);
            $redirectPage = 'cart';

            if ($cartId <= 0) $errs[] = "Cart item không hợp lệ.";

            if (empty($errs)) {
                $stmt = mysqli_prepare($conn, "
                    SELECT c.id, c.product_variant_id, v.stock
                    FROM cart c
                    JOIN product_variants v ON v.id = c.product_variant_id
                    WHERE c.id = ? AND c.user_id = ?
                    LIMIT 1
                ");
                mysqli_stmt_bind_param($stmt, "ii", $cartId, $userId);
                mysqli_stmt_execute($stmt);
                $cur = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if (!$cur) {
                    $errs[] = "Item không tồn tại hoặc không thuộc giỏ của bạn.";
                } else {
                    $stock = (int)$cur['stock'];

                    if ($qty <= 0) {
                        $stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE id = ? AND user_id = ?");
                        mysqli_stmt_bind_param($stmt, "ii", $cartId, $userId);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        $ok = "Đã xoá item khỏi giỏ.";
                    } elseif ($qty > $stock) {
                        $errs[] = "Tồn kho không đủ. Hiện còn $stock.";
                    } else {
                        $stmt = mysqli_prepare($conn, "UPDATE cart SET quantity = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                        mysqli_stmt_bind_param($stmt, "iii", $qty, $cartId, $userId);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        $ok = "Đã cập nhật giỏ hàng.";
                    }
                }
            }
        }

        if ($action === 'remove_cart') {
            $cartId = (int)($_POST['cart_id'] ?? 0);
            $redirectPage = 'cart';

            if ($cartId <= 0) $errs[] = "Cart item không hợp lệ.";

            if (empty($errs)) {
                $stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE id = ? AND user_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $cartId, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $ok = "Đã xoá item khỏi giỏ.";
            }
        }

        if ($action === 'checkout') {
            $redirectPage = 'cart';
            $note = cleanInput($_POST['note'] ?? '');

            $stmt = mysqli_prepare($conn, "
                SELECT c.id AS cart_id, c.quantity,
                       v.id AS variant_id, v.stock,
                       p.price
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
                $errs[] = "Giỏ hàng trống.";
            } else {
                // Total cents
                $totalCents = 0;
                foreach ($items as $it) {
                    $totalCents += price_to_cents($it['price']) * (int)$it['quantity'];
                }
                $totalStr = cents_to_decimal_string($totalCents);

                mysqli_begin_transaction($conn);
                try {
                    // Create order
                    $stmt = mysqli_prepare($conn, "INSERT INTO orders (user_id, status, note, total) VALUES (?, 'pending', ?, ?)");
                    mysqli_stmt_bind_param($stmt, "iss", $userId, $note, $totalStr);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $orderId = (int)mysqli_insert_id($conn);

                    // Insert order lines + reduce stock atomically
                    foreach ($items as $it) {
                        $variantId = (int)$it['variant_id'];
                        $qty = (int)$it['quantity'];
                        $priceStr = (string)$it['price'];

                        $stmt = mysqli_prepare($conn, "UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");
                        mysqli_stmt_bind_param($stmt, "iii", $qty, $variantId, $qty);
                        mysqli_stmt_execute($stmt);
                        $affected = mysqli_stmt_affected_rows($stmt);
                        mysqli_stmt_close($stmt);

                        if ($affected !== 1) {
                            throw new Exception("Tồn kho thay đổi. Vui lòng thử lại (variant #$variantId).");
                        }

                        $stmt = mysqli_prepare($conn, "INSERT INTO order_detail (order_id, product_variant_id, quantity, price) VALUES (?, ?, ?, ?)");
                        mysqli_stmt_bind_param($stmt, "iiis", $orderId, $variantId, $qty, $priceStr);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }

                    // Clear cart
                    $stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE user_id = ?");
                    mysqli_stmt_bind_param($stmt, "i", $userId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    mysqli_commit($conn);

                    $ok = "Checkout thành công! Mã đơn hàng: #$orderId";
                    $redirectPage = 'orders';
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $errs[] = "Checkout thất bại: " . $e->getMessage();
                    $redirectPage = 'cart';
                }
            }
        }

        redirect_home_with_flash($redirectPage, $errs, $ok);
    }

    // =========================
    // ADMIN ACTIONS
    // =========================
    if (starts_with($action, 'admin_')) {
        $redirectPage = 'admin';

        if (!isAdmin()) {
            $errs[] = "Bạn không có quyền admin.";
            redirect_home_with_flash($redirectPage, $errs, '');
        }

        // helper validate length
        $maxLen = function(string $s, int $max): bool { return mb_strlen($s) <= $max; };

        if ($action === 'admin_add_product') {
            $name = trim((string)($_POST['name'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $type = trim((string)($_POST['type'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $size = trim((string)($_POST['size'] ?? ''));
            $color = trim((string)($_POST['color'] ?? ''));
            $stock = (int)($_POST['stock'] ?? 0);

            if ($name === '') $errs[] = "Tên sản phẩm không được để trống.";
            if (!$maxLen($name, 255)) $errs[] = "Tên sản phẩm quá dài (tối đa 255 ký tự).";
            if ($price < 0) $errs[] = "Giá không hợp lệ.";
            if ($type !== '' && !$maxLen($type, 100)) $errs[] = "Type quá dài (tối đa 100 ký tự).";
            if ($brand !== '' && !$maxLen($brand, 100)) $errs[] = "Brand quá dài (tối đa 100 ký tự).";
            if ($description !== '' && !$maxLen($description, 2000)) $errs[] = "Description quá dài (tối đa 2000 ký tự).";
            if ($size === '' || $color === '') $errs[] = "Variant size/color không được để trống.";
            if ($size !== '' && !$maxLen($size, 50)) $errs[] = "Size quá dài (tối đa 50 ký tự).";
            if ($color !== '' && !$maxLen($color, 50)) $errs[] = "Color quá dài (tối đa 50 ký tự).";
            if ($stock < 0) $errs[] = "Stock không hợp lệ.";

            $imagePath = null;
            if (empty($errs)) {
                $imagePath = save_uploaded_image('product_image', $errs);
            }

            if (empty($errs)) {
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
                    $ok = "Đã thêm sản phẩm + variant!";
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $errs[] = "Thêm sản phẩm thất bại: " . $e->getMessage();
                }
            }
        }

        if ($action === 'admin_update_product') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $type = trim((string)($_POST['type'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));

            if ($productId <= 0) $errs[] = "Product không hợp lệ.";
            if ($name === '') $errs[] = "Tên sản phẩm không được để trống.";
            if (!$maxLen($name, 255)) $errs[] = "Tên sản phẩm quá dài (tối đa 255 ký tự).";
            if ($price < 0) $errs[] = "Giá không hợp lệ.";
            if ($type !== '' && !$maxLen($type, 100)) $errs[] = "Type quá dài (tối đa 100 ký tự).";
            if ($brand !== '' && !$maxLen($brand, 100)) $errs[] = "Brand quá dài (tối đa 100 ký tự).";
            if ($description !== '' && !$maxLen($description, 2000)) $errs[] = "Description quá dài (tối đa 2000 ký tự).";

            $newImagePath = null;
            if (empty($errs)) {
                $newImagePath = save_uploaded_image('product_image', $errs);
            }

            if (empty($errs)) {
                try {
                    if ($newImagePath) {
                        $stmt = mysqli_prepare($conn, "UPDATE products SET name=?, price=?, image=?, description=?, type=?, brand=? WHERE id=?");
                        mysqli_stmt_bind_param($stmt, "sdssssi", $name, $price, $newImagePath, $description, $type, $brand, $productId);
                    } else {
                        $stmt = mysqli_prepare($conn, "UPDATE products SET name=?, price=?, description=?, type=?, brand=? WHERE id=?");
                        mysqli_stmt_bind_param($stmt, "sdsssi", $name, $price, $description, $type, $brand, $productId);
                    }
                    mysqli_stmt_execute($stmt);
                    $aff = mysqli_stmt_affected_rows($stmt);
                    mysqli_stmt_close($stmt);

                    if ($aff === 0) {
                        $ok = "Không có thay đổi hoặc sản phẩm không tồn tại.";
                    } else {
                        $ok = "Đã cập nhật sản phẩm.";
                    }
                } catch (Exception $e) {
                    $errs[] = "Cập nhật sản phẩm thất bại: " . $e->getMessage();
                }
            }
        }

        if ($action === 'admin_delete_product') {
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) $errs[] = "Product không hợp lệ.";

            if (empty($errs)) {
                try {
                    $stmt = mysqli_prepare($conn, "DELETE FROM products WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, "i", $productId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $ok = "Đã xoá sản phẩm.";
                } catch (Exception $e) {
                    // 1451: cannot delete or update a parent row: a foreign key constraint fails
                    $code = (int)mysqli_errno($conn);
                    if ($code === 1451) {
                        $errs[] = "Không thể xoá vì sản phẩm/variant đã nằm trong đơn hàng.";
                    } else {
                        $errs[] = "Xoá sản phẩm thất bại: " . $e->getMessage();
                    }
                }
            }
        }

        if ($action === 'admin_add_variant') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $size = trim((string)($_POST['size'] ?? ''));
            $color = trim((string)($_POST['color'] ?? ''));
            $stock = (int)($_POST['stock'] ?? 0);

            if ($productId <= 0) $errs[] = "Product không hợp lệ.";
            if ($size === '' || $color === '') $errs[] = "Size/Color không được để trống.";
            if ($size !== '' && !$maxLen($size, 50)) $errs[] = "Size quá dài (tối đa 50 ký tự).";
            if ($color !== '' && !$maxLen($color, 50)) $errs[] = "Color quá dài (tối đa 50 ký tự).";
            if ($stock < 0) $errs[] = "Stock không hợp lệ.";

            if (empty($errs)) {
                try {
                    $stmt = mysqli_prepare($conn, "INSERT INTO product_variants (product_id, size, color, stock) VALUES (?,?,?,?)");
                    mysqli_stmt_bind_param($stmt, "issi", $productId, $size, $color, $stock);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $ok = "Đã thêm variant.";
                } catch (Exception $e) {
                    $code = (int)mysqli_errno($conn);
                    if ($code === 1062) {
                        $errs[] = "Variant size/color đã tồn tại cho sản phẩm này.";
                    } else {
                        $errs[] = "Thêm variant thất bại: " . $e->getMessage();
                    }
                }
            }
        }

        if ($action === 'admin_update_variant') {
            $variantId = (int)($_POST['variant_id'] ?? 0);
            $productId = (int)($_POST['product_id'] ?? 0);
            $size = trim((string)($_POST['size'] ?? ''));
            $color = trim((string)($_POST['color'] ?? ''));
            $stock = (int)($_POST['stock'] ?? 0);

            if ($variantId <= 0 || $productId <= 0) $errs[] = "Variant/Product không hợp lệ.";
            if ($size === '' || $color === '') $errs[] = "Size/Color không được để trống.";
            if ($size !== '' && !$maxLen($size, 50)) $errs[] = "Size quá dài (tối đa 50 ký tự).";
            if ($color !== '' && !$maxLen($color, 50)) $errs[] = "Color quá dài (tối đa 50 ký tự).";
            if ($stock < 0) $errs[] = "Stock không hợp lệ.";

            if (empty($errs)) {
                try {
                    $stmt = mysqli_prepare($conn, "UPDATE product_variants SET size=?, color=?, stock=? WHERE id=? AND product_id=?");
                    mysqli_stmt_bind_param($stmt, "ssiii", $size, $color, $stock, $variantId, $productId);
                    mysqli_stmt_execute($stmt);
                    $aff = mysqli_stmt_affected_rows($stmt);
                    mysqli_stmt_close($stmt);

                    if ($aff === 0) {
                        $ok = "Không có thay đổi hoặc variant không tồn tại.";
                    } else {
                        $ok = "Đã cập nhật variant.";
                    }
                } catch (Exception $e) {
                    $code = (int)mysqli_errno($conn);
                    if ($code === 1062) {
                        $errs[] = "Variant size/color bị trùng với variant khác.";
                    } else {
                        $errs[] = "Cập nhật variant thất bại: " . $e->getMessage();
                    }
                }
            }
        }

        if ($action === 'admin_delete_variant') {
            $variantId = (int)($_POST['variant_id'] ?? 0);
            $productId = (int)($_POST['product_id'] ?? 0);

            if ($variantId <= 0 || $productId <= 0) $errs[] = "Variant/Product không hợp lệ.";

            if (empty($errs)) {
                try {
                    $stmt = mysqli_prepare($conn, "DELETE FROM product_variants WHERE id = ? AND product_id = ?");
                    mysqli_stmt_bind_param($stmt, "ii", $variantId, $productId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $ok = "Đã xoá variant.";
                } catch (Exception $e) {
                    $code = (int)mysqli_errno($conn);
                    if ($code === 1451) {
                        $errs[] = "Không thể xoá vì variant đã nằm trong đơn hàng.";
                    } else {
                        $errs[] = "Xoá variant thất bại: " . $e->getMessage();
                    }
                }
            }
        }

        redirect_home_with_flash($redirectPage, $errs, $ok);
    }
}
