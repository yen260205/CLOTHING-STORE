<?php
// Partials: head + topbar + flash + footer/script

function layout_start(string $page, string $success, array $errors): void {
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

      /* ===== CARD: SOLID ===== */
      .card{
        background:#0f172a; /* solid */
        border:1px solid rgba(255,255,255,.12);
        border-radius:16px;
        padding:14px
      }

      .grid{
        display:grid;
        grid-template-columns:repeat(3,1fr);
        gap:14px
      }

      @media (max-width: 900px){
        .grid{ grid-template-columns:repeat(2,1fr) }
      }
      @media (max-width: 600px){
        .grid{ grid-template-columns:1fr }
      }

      /* IMGBOX: SOLID */
      .imgbox{
        height:170px;
        border-radius:12px;
        background:#0b1220; /* solid */
        border:1px solid rgba(255,255,255,.12);
        display:flex;
        align-items:center;
        justify-content:center;
        overflow:hidden
      }
      .imgbox img{
        width:100%;
        height:100%;
        object-fit:cover
      }

      .muted{ opacity:.75 }

      .h1{
        font-size:20px;
        font-weight:800;
        margin:0
      }
      .price{
        font-size:18px;
        font-weight:800
      }

      .btn{
        cursor:pointer;
        border:none;
        border-radius:12px;
        padding:10px 12px;
        background:linear-gradient(135deg,#667eea,#764ba2);
        color:white;
        font-weight:700
      }

      /* Buttons phụ: cũng solid cho đồng bộ */
      .btn.secondary{
        background:#111c33;
        border:1px solid rgba(255,255,255,.12)
      }
      .btn.danger{
        background:#2a1212;
        border:1px solid rgba(255,80,80,.35)
      }

      /* INPUT/SELECT/TEXTAREA: SOLID */
      input,select,textarea{
        width:100%;
        padding:10px;
        border-radius:12px;
        border:1px solid rgba(255,255,255,.12);
        background:#0b1220; /* solid */
        color:#e7eaf0;
        outline:none
      }
      textarea{
        min-height:90px;
        resize:vertical
      }

      .msg{
        margin:12px 0;
        padding:12px;
        border-radius:12px;
        border:1px solid
      }
      .msg.ok{
        background:#10251b;
        border-color:rgba(60,200,120,.35)
      }
      .msg.err{
        background:#2a1212;
        border-color:rgba(255,80,80,.30)
      }

      table{
        width:100%;
        border-collapse:separate;
        border-spacing:0 10px
      }
      td,th{ padding:10px }

      /* TABLE ROW: SOLID */
      tr{
        background:#0f172a; /* solid */
        border:1px solid rgba(255,255,255,.12)
      }
      tr td:first-child, tr th:first-child{
        border-top-left-radius:12px;
        border-bottom-left-radius:12px
      }
      tr td:last-child, tr th:last-child{
        border-top-right-radius:12px;
        border-bottom-right-radius:12px
      }

      .two{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:12px
      }
      @media (max-width: 800px){
        .two{ grid-template-columns:1fr }
      }

      .small{ font-size:13px }
    </style>


</head>
<body>

<div class="topbar">
  <div class="wrap">
    <div class="row">
      <div class="brand">
        <span style="font-size:18px;">🛍️</span>
        <span>Clothing Store</span>
        <span class="pill small"><?php echo e($_SESSION['full_name'] ?? ''); ?> (<?php echo e($_SESSION['role'] ?? 'user'); ?>)</span>
      </div>
      <div class="nav">
        <a class="<?php echo $page==='products'?'active':''; ?>" href="home.php?page=products">Products</a>
        <a class="<?php echo $page==='cart'?'active':''; ?>" href="home.php?page=cart">Cart</a>
        <a class="<?php echo $page==='orders'?'active':''; ?>" href="home.php?page=orders">Orders</a>
        <?php if (isAdmin()): ?>
          <a class="<?php echo $page==='admin'?'active':''; ?>" href="home.php?page=admin">Admin</a>
        <?php endif; ?>
        <a href="logout.php" class="btn secondary" style="padding:8px 12px;">Logout</a>
      </div>
    </div>
  </div>
</div>

<div class="wrap">
  <?php if ($success): ?>
    <div class="msg ok"><?php echo e($success); ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="msg err">
      <div style="font-weight:800;margin-bottom:6px;">Có lỗi:</div>
      <ul style="margin:0 0 0 18px;">
        <?php foreach ($errors as $er): ?>
          <li><?php echo e($er); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

<?php
}

function layout_end(mysqli $conn): void {
?>
</div>

<script>
(function () {
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
  }
  function uniq(arr) { return Array.from(new Set(arr)); }

  document.querySelectorAll('form.add-cart-form').forEach(function (form) {
    var variants = [];
    try { variants = JSON.parse(form.dataset.variants || '[]'); } catch (e) { variants = []; }

    var sizeSel = form.querySelector('select.js-size');
    var colorSel = form.querySelector('select.js-color');
    var variantIdInput = form.querySelector('input.js-variant-id');
    var hint = form.querySelector('.js-variant-hint');
    var btn = form.querySelector('button.btn');

    if (!sizeSel || !colorSel) return;

    function setHint(msg, isError) {
      if (!hint) return;
      hint.textContent = msg || '';
      hint.style.color = isError ? '#fca5a5' : '';
    }

    function refreshColors() {
      var size = sizeSel.value;
      if (variantIdInput) variantIdInput.value = '';
      colorSel.innerHTML = '<option value="">Chọn màu</option>';
      colorSel.disabled = !size;
      if (btn) btn.disabled = false;
      setHint('', false);

      if (!size) {
        setHint('Chọn size để hiện màu phù hợp.', false);
        return;
      }

      var colors = uniq(
        variants
          .filter(function (v) { return v.size === size && Number(v.stock) > 0; })
          .map(function (v) { return v.color; })
      );
      colors.sort();

      if (colors.length === 0) {
        colorSel.disabled = true;
        if (btn) btn.disabled = true;
        setHint('Size này đã hết hàng.', true);
        return;
      }

      colors.forEach(function (c) {
        colorSel.insertAdjacentHTML('beforeend', '<option value="' + esc(c) + '">' + esc(c) + '</option>');
      });
    }

    function updateVariantId() {
      var size = sizeSel.value;
      var color = colorSel.value;
      if (variantIdInput) variantIdInput.value = '';
      if (btn) btn.disabled = false;
      setHint('', false);

      if (!size || !color) return;

      var match = variants.find(function (v) { return v.size === size && v.color === color; });
      if (!match) {
        if (btn) btn.disabled = true;
        setHint('Kết hợp size/màu này không tồn tại.', true);
        return;
      }
      if (Number(match.stock) <= 0) {
        if (btn) btn.disabled = true;
        setHint('Biến thể này đã hết hàng.', true);
        return;
      }
      if (variantIdInput) variantIdInput.value = String(match.id);
      setHint('Còn ' + match.stock + ' sản phẩm.', false);
    }

    sizeSel.addEventListener('change', function () {
      refreshColors();
      updateVariantId();
    });
    colorSel.addEventListener('change', updateVariantId);

    // Init
    refreshColors();
  });
})();
</script>

</body>
</html>

<?php
mysqli_close($conn);
}