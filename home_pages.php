<?php
// Pages: load data + render by page
require_once __DIR__ . '/home_partials.php';

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
$cartTotalCents = 0;
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
        $cartTotalCents += price_to_cents($it['price']) * ((int)$it['quantity']);
    }
    $cartTotal = $cartTotalCents / 100;
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

<?php layout_start($page, $success, $errors); ?>

  <!-- ================= PRODUCTS ================= -->
  <?php if ($page === 'products'): ?>
    <div class="row" style="margin-bottom:12px;">
      <h2 class="h1">Products</h2>
      <div class="muted small">Chọn variant (size/color) rồi Add to cart.</div>
    </div>

    <div class="grid">
      <?php foreach ($products as $p): ?>
        <div class="card">
          <div class="imgbox">
            <?php
              $imgSrc = resolve_product_image_src($p['image'] ?? '');
              if ($imgSrc && local_file_exists($imgSrc)) {
                echo '<img src="'.e($imgSrc).'" alt="product">';
              } else {
                echo '<div class="muted small">No image</div>';
              }
            ?>
          </div>

          <div style="margin-top:12px;display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
            <div>
              <div style="font-weight:800"><?php echo e($p['name']); ?></div>
              <div class="muted small">
                <?php echo e($p['brand'] ?: ''); ?> <?php echo e($p['type'] ? ('• '.$p['type']) : ''); ?>
              </div>
            </div>
            <div class="price"><?php echo number_format($p['price'], 0, ',', '.'); ?>₫</div>
          </div>

          <?php if ($p['description']): ?>
            <div class="muted small" style="margin-top:8px;">
              <?php echo e(mb_strlen($p['description'])>120 ? mb_substr($p['description'],0,120).'...' : $p['description']); ?>
            </div>
          <?php endif; ?>

          <form method="POST" style="margin-top:12px;" class="add-cart-form" data-variants="<?php echo e(json_encode($p['variants'], JSON_UNESCAPED_UNICODE)); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
            <input type="hidden" name="variant_id" value="" class="js-variant-id">

            <div class="two">
              <!-- Dropdown cho Size -->
              <div>
                <label class="small muted">Size</label>
                <select name="size" class="js-size" required>
                  <option value="">Chọn kích thước</option>
                  <?php
                    $sizeSet = [];
                    foreach ($p['variants'] as $v) {
                      if ((int)$v['stock'] > 0) { $sizeSet[$v['size']] = true; }
                    }
                    $sizes = array_keys($sizeSet);
                    sort($sizes);
                  ?>
                  <?php foreach ($sizes as $s): ?>
                    <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Dropdown cho Color -->
              <div>
                <label class="small muted">Color</label>
                <select name="color" class="js-color" required disabled>
                  <option value="">Chọn màu</option>
                  <?php
                    $colorSet = [];
                    foreach ($p['variants'] as $v) {
                      if ((int)$v['stock'] > 0) { $colorSet[$v['color']] = true; }
                    }
                    $colors = array_keys($colorSet);
                    sort($colors);
                  ?>
                  <?php foreach ($colors as $c): ?>
                    <option value="<?php echo e($c); ?>"><?php echo e($c); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="small muted js-variant-hint" style="margin-top:6px;"></div>

            <div>
              <label class="small muted">Qty</label>
              <input type="number" name="quantity" min="1" value="1" required />
            </div>

            <button class="btn" style="margin-top:10px;">
              Add to cart
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ================= CART ================= -->
  <?php if ($page === 'cart'): ?>
    <div class="row" style="margin-bottom:12px;">
      <h2 class="h1">Cart</h2>
      <div class="pill">Total: <b><?php echo number_format($cartTotal, 0, ',', '.'); ?>₫</b></div>
    </div>

    <?php if (empty($cartItems)): ?>
      <div class="card muted">Giỏ hàng trống.</div>
    <?php else: ?>
      <div class="card">
        <table>
          <thead class="muted small">
            <tr>
              <th align="left">Item</th>
              <th align="left">Variant</th>
              <th align="right">Price</th>
              <th align="center">Qty</th>
              <th align="right">Subtotal</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cartItems as $it): ?>
            <?php
              $sub = ((float)$it['price']) * ((int)$it['quantity']);
              $warning = ((int)$it['quantity'] > (int)$it['stock']);
            ?>
            <tr>
              <td>
                <div style="font-weight:800"><?php echo e($it['name']); ?></div>
                <?php if ($warning): ?>
                  <div class="small" style="color:#ff9a9a;">Vượt tồn kho (stock: <?php echo (int)$it['stock']; ?>)</div>
                <?php else: ?>
                  <div class="muted small">stock: <?php echo (int)$it['stock']; ?></div>
                <?php endif; ?>
              </td>
              <td class="muted"><?php echo e($it['size'].' / '.$it['color']); ?></td>
              <td align="right"><?php echo number_format((float)$it['price'], 0, ',', '.'); ?>₫</td>
              <td align="center" style="min-width:170px;">
                <form method="POST" style="display:flex;gap:8px;align-items:center;justify-content:center;">
                  <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="update_cart">
                  <input type="hidden" name="cart_id" value="<?php echo (int)$it['cart_id']; ?>">
                  <input type="number" name="quantity" min="0" value="<?php echo (int)$it['quantity']; ?>" style="width:90px;">
                  <button class="btn secondary" type="submit" style="padding:8px 10px;">Update</button>
                </form>
                <div class="small muted">(Qty=0 sẽ xoá)</div>
              </td>
              <td align="right"><?php echo number_format($sub, 0, ',', '.'); ?>₫</td>
              <td align="right">
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="remove_cart">
                  <input type="hidden" name="cart_id" value="<?php echo (int)$it['cart_id']; ?>">
                  <button class="btn danger" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin-top:14px;">
        <h3 style="margin:0 0 10px 0;">Checkout</h3>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="checkout">
          <label class="small muted">Note (tuỳ chọn)</label>
          <textarea name="note" placeholder="Ghi chú đơn hàng..."></textarea>
          <button class="btn" style="margin-top:10px;">Place order (Total: <?php echo number_format($cartTotal, 0, ',', '.'); ?>₫)</button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ================= ORDERS ================= -->
  <?php if ($page === 'orders'): ?>
    <div class="row" style="margin-bottom:12px;">
      <h2 class="h1">Orders</h2>
      <div class="muted small">Lịch sử đơn hàng của bạn (50 đơn gần nhất).</div>
    </div>

    <?php if (empty($orders)): ?>
      <div class="card muted">Chưa có đơn hàng nào.</div>
    <?php else: ?>
      <?php foreach ($orders as $od): ?>
        <div class="card" style="margin-bottom:12px;">
          <div class="row">
            <div>
              <div style="font-weight:900">Order #<?php echo (int)$od['id']; ?></div>
              <div class="muted small"><?php echo e($od['created_at']); ?> • Status: <b><?php echo e($od['status']); ?></b></div>
            </div>
            <div class="pill">Total: <b><?php echo number_format((float)$od['total'], 0, ',', '.'); ?>₫</b></div>
          </div>
          <?php if (!empty($od['note'])): ?>
            <div class="muted small" style="margin-top:8px;">Note: <?php echo e($od['note']); ?></div>
          <?php endif; ?>

          <?php
            // order lines
            $stmt = mysqli_prepare($conn, "
              SELECT od.quantity, od.price,
                     v.size, v.color,
                     p.name
              FROM order_detail od
              JOIN product_variants v ON v.id = od.product_variant_id
              JOIN products p ON p.id = v.product_id
              WHERE od.order_id = ?
              ORDER BY od.id ASC
            ");
            $oid = (int)$od['id'];
            mysqli_stmt_bind_param($stmt, "i", $oid);
            mysqli_stmt_execute($stmt);
            $lines = stmt_fetch_all($stmt);
            mysqli_stmt_close($stmt);
          ?>
          <div style="margin-top:10px;">
            <div class="muted small" style="margin-bottom:6px;">Items:</div>
            <ul style="margin:0 0 0 18px;">
              <?php foreach ($lines as $ln): ?>
                <li class="small">
                  <b><?php echo e($ln['name']); ?></b>
                  (<?php echo e($ln['size'].'/'.$ln['color']); ?>)
                  — Qty: <?php echo (int)$ln['quantity']; ?>
                  — Price: <?php echo number_format((float)$ln['price'], 0, ',', '.'); ?>₫
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ================= ADMIN ================= -->
  <?php if ($page === 'admin'): ?>
    <?php if (!isAdmin()): ?>
      <div class="card">Bạn không có quyền admin.</div>
    <?php else: ?>
      <div class="row" style="margin-bottom:12px;">
        <h2 class="h1">Admin</h2>
        <div class="muted small">Quản lý products + variants (size/color/stock).</div>
      </div>

      <div class="two">
        <div class="card">
          <h3 style="margin:0 0 10px 0;">Add product</h3>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="admin_add_product">

            <label class="small muted">Name</label>
            <input name="name" placeholder="Tên sản phẩm" />

            <div class="two" style="margin-top:10px;">
              <div>
                <label class="small muted">Price</label>
                <input name="price" type="number" step="0.01" placeholder="Giá" />
              </div>
              <div>
                <label class="small muted">Stock (initial)</label>
                <input name="stock" type="number" min="0" value="0" />
              </div>
            </div>

            <div class="two" style="margin-top:10px;">
              <div>
                <label class="small muted">Type</label>
                <input name="type" placeholder="VD: Tshirt" />
              </div>
              <div>
                <label class="small muted">Brand</label>
                <input name="brand" placeholder="VD: Nike" />
              </div>
            </div>

            <div class="two" style="margin-top:10px;">
              <div>
                <label class="small muted">Variant size</label>
                <input name="size" placeholder="VD: M" value="FREE" />
              </div>
              <div>
                <label class="small muted">Variant color</label>
                <input name="color" placeholder="VD: Black" value="DEFAULT" />
              </div>
            </div>

            <label class="small muted" style="margin-top:10px;">Description</label>
            <textarea name="description" placeholder="Mô tả..."></textarea>

            <label class="small muted" style="margin-top:10px;">Image</label>
            <input type="file" name="product_image" accept="image/*"/>

            <button class="btn" style="margin-top:10px;">Add</button>
          </form>
        </div>

        <div class="card">
          <h3 style="margin:0 0 10px 0;">Manage products</h3>
          <?php if (empty($products)): ?>
            <div class="muted">Chưa có sản phẩm.</div>
          <?php else: ?>
            <?php foreach ($products as $p): ?>
              <div class="card" style="margin-bottom:10px;">
                <div class="row">
                  <div>
                    <div style="font-weight:900">#<?php echo (int)$p['id']; ?> — <?php echo e($p['name']); ?></div>
                    <div class="muted small"><?php echo number_format($p['price'], 0, ',', '.'); ?>₫ • <?php echo e($p['brand'] ?: ''); ?> <?php echo e($p['type'] ? ('• '.$p['type']) : ''); ?></div>
                  </div>
                  <form method="POST" onsubmit="return confirm('Xoá product này?');">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="admin_delete_product">
                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                    <button class="btn danger" type="submit">Delete</button>
                  </form>
                </div>

                <details style="margin-top:10px;">
                  <summary class="small" style="cursor:pointer;">Edit product + Variants</summary>

                  <div style="margin-top:10px;">
                    <form method="POST" enctype="multipart/form-data">
                      <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                      <input type="hidden" name="action" value="admin_update_product">
                      <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">

                      <div class="two">
                        <div>
                          <label class="small muted">Name</label>
                          <input name="name" value="<?php echo e($p['name']); ?>" />
                        </div>
                        <div>
                          <label class="small muted">Price</label>
                          <input name="price" type="number" step="0.01" value="<?php echo e($p['price']); ?>" />
                        </div>
                      </div>

                      <div class="two" style="margin-top:10px;">
                        <div>
                          <label class="small muted">Type</label>
                          <input name="type" value="<?php echo e($p['type'] ?? ''); ?>" />
                        </div>
                        <div>
                          <label class="small muted">Brand</label>
                          <input name="brand" value="<?php echo e($p['brand'] ?? ''); ?>" />
                        </div>
                      </div>

                      <label class="small muted" style="margin-top:10px;">Description</label>
                      <textarea name="description"><?php echo e($p['description'] ?? ''); ?></textarea>

                      <label class="small muted" style="margin-top:10px;">Replace image (optional)</label>
                      <input type="file" name="product_image" accept="image/*"/>

                      <button class="btn secondary" style="margin-top:10px;">Save product</button>
                    </form>
                  </div>

                  <div style="margin-top:14px;">
                    <div class="muted small" style="margin-bottom:8px;">Variants</div>

                    <?php if (empty($p['variants'])): ?>
                      <div class="muted small">Chưa có variant.</div>
                    <?php else: ?>
                      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                        <?php foreach ($p['variants'] as $v): ?>
                          <div class="card" style="padding:10px;">
                            <div style="font-weight:800"><?php echo e($v['size'].' / '.$v['color']); ?></div>
                            <div class="muted small">Stock hiện tại: <?php echo (int)$v['stock']; ?></div>

                            <form method="POST" style="margin-top:10px;">
                              <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                              <input type="hidden" name="action" value="admin_update_variant">
                              <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                              <input type="hidden" name="variant_id" value="<?php echo (int)$v['id']; ?>">

                              <label class="small muted">Size</label>
                              <input name="size" value="<?php echo e($v['size']); ?>" />

                              <label class="small muted" style="margin-top:8px;">Color</label>
                              <input name="color" value="<?php echo e($v['color']); ?>" />

                              <label class="small muted" style="margin-top:8px;">Stock</label>
                              <input type="number" name="stock" min="0" value="<?php echo (int)$v['stock']; ?>" />

                              <button class="btn secondary" style="margin-top:10px;width:100%;">Save variant</button>
                            </form>

                            <form method="POST" style="margin-top:8px;" onsubmit="return confirm('Xóa variant này?');">
                              <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                              <input type="hidden" name="action" value="admin_delete_variant">
                              <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                              <input type="hidden" name="variant_id" value="<?php echo (int)$v['id']; ?>">
                              <button class="btn danger" style="width:100%;">Delete</button>
                            </form>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>

                    <div class="card" style="margin-top:10px;">
                      <div class="muted small" style="margin-bottom:8px;">Add variant</div>
                      <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="admin_add_variant">
                        <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">

                        <div class="two">
                          <div>
                            <label class="small muted">Size</label>
                            <input name="size" placeholder="VD: L" />
                          </div>
                          <div>
                            <label class="small muted">Color</label>
                            <input name="color" placeholder="VD: White" />
                          </div>
                        </div>

                        <label class="small muted" style="margin-top:10px;">Stock</label>
                        <input type="number" name="stock" min="0" value="0" />

                        <button class="btn" style="margin-top:10px;">Add variant</button>
                      </form>
                    </div>

                  </div>
                </details>

              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card" style="margin-top:14px;">
        <div class="row" style="margin-bottom:10px;">
          <h3 style="margin:0;">Đơn hàng user đã đặt</h3>
          <div class="muted small">Hiển thị <?php echo count($adminOrders); ?> đơn gần nhất.</div>
        </div>

        <?php if (empty($adminOrders)): ?>
          <div class="muted small">Chưa có đơn hàng nào.</div>
        <?php else: ?>
          <?php foreach ($adminOrders as $od): ?>
            <div style="padding:10px 0;border-top:1px solid rgba(255,255,255,.06);">
              <div class="row">
                <div>
                  <div style="font-weight:900">Order #<?php echo (int)$od['id']; ?></div>
                  <div class="muted small">
                    <?php echo e($od['created_at']); ?> • Status: <b><?php echo e($od['status']); ?></b>
                    • User: <?php echo e($od['user_name']); ?> (<?php echo e($od['user_email']); ?>)
                  </div>
                </div>
                <div class="pill">Total: <b><?php echo number_format((float)$od['total'], 0, ',', '.'); ?>₫</b></div>
              </div>

              <?php if (!empty($od['note'])): ?>
                <div class="muted small" style="margin-top:8px;">Note: <?php echo e($od['note']); ?></div>
              <?php endif; ?>

              <?php
                // order lines
                $stmt = mysqli_prepare($conn, "
                  SELECT od.quantity, od.price,
                        v.size, v.color,
                        p.name
                  FROM order_detail od
                  JOIN product_variants v ON v.id = od.product_variant_id
                  JOIN products p ON p.id = v.product_id
                  WHERE od.order_id = ?
                ");
                mysqli_stmt_bind_param($stmt, "i", $od['id']);
                mysqli_stmt_execute($stmt);
                $lines = stmt_fetch_all($stmt);
                mysqli_stmt_close($stmt);
              ?>

              <?php if (!empty($lines)): ?>
                <div class="muted small" style="margin-top:8px;">Items:</div>
                <ul style="margin:0 0 0 18px;">
                  <?php foreach ($lines as $ln): ?>
                    <li class="small">
                      <b><?php echo e($ln['name']); ?></b>
                      (<?php echo e($ln['size'].'/'.$ln['color']); ?>)
                      — Qty: <?php echo (int)$ln['quantity']; ?>
                      — Price: <?php echo number_format((float)$ln['price'], 0, ',', '.'); ?>₫
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  <?php endif; ?>

<?php
layout_end($conn);
?>