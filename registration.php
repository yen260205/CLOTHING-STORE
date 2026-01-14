<?php
require_once 'config.php';
require_once 'validation.php';

if (isLoggedIn()) redirect('home.php');

$errors = [];
$success = '';
$formData = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = cleanInput($_POST['full_name'] ?? '');
    $email = cleanInput($_POST['email'] ?? '');
    $phone = cleanInput($_POST['phone'] ?? '');
    $address = cleanInput($_POST['address'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    $formData = compact('full_name','email','phone','address');

    $errors = array_merge($errors, validateFullName($full_name));
    $errors = array_merge($errors, validateEmail($email));
    $errors = array_merge($errors, validatePhone($phone));
    $errors = array_merge($errors, validateAddress($address));
    $errors = array_merge($errors, validatePassword($password));

    if ($password !== $confirm_password) $errors[] = "Password và Confirm Password không khớp";

    if (empty($errors)) {
        $conn = getDBConnection();

        if (checkEmailExists($conn, $email)) {
            $errors[] = "Email đã được sử dụng";
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, phone, address, role) VALUES (?, ?, ?, ?, ?, 'user')");
            mysqli_stmt_bind_param($stmt, "sssss", $full_name, $email, $hash, $phone, $address);

            if (mysqli_stmt_execute($stmt)) {
                $success = "Đăng ký thành công! Đang chuyển đến trang đăng nhập...";
                $formData = ['full_name'=>'','email'=>'','phone'=>'','address'=>''];
                header("refresh:2;url=login.php");
            } else {
                $errors[] = "Lỗi: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }

        mysqli_close($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký - Clothing Store</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            background-image:url('images/background.jpg');
            background-size:cover;background-repeat:no-repeat;background-position:center;
            min-height:100vh;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            display:flex;justify-content:center;align-items:center;padding:20px
        }
        .register-container{background:#fff;padding:40px;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,.2);width:100%;max-width:520px}
        .register-header{text-align:center;margin-bottom:30px}
        .register-header h1{color:#333;font-size:28px;margin-bottom:10px}
        .register-header p{color:#666;font-size:14px}
        .form-group{margin-bottom:18px}
        .form-group label{display:block;margin-bottom:8px;color:#333;font-weight:500}
        .form-group input{width:100%;padding:12px;border:1px solid #ddd;border-radius:5px;font-size:14px}
        .btn-register{width:100%;padding:12px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border:none;border-radius:5px;font-size:16px;font-weight:600;cursor:pointer;margin-top:10px}
        .error-message{background:#fee;color:#c33;padding:12px;border-radius:5px;margin-bottom:20px;border-left:4px solid #c33}
        .success-message{background:#efe;color:#3c3;padding:12px;border-radius:5px;margin-bottom:20px;border-left:4px solid #3c3}
        .login-link{text-align:center;margin-top:20px;color:#666;font-size:14px}
        .login-link a{color:#667eea;text-decoration:none;font-weight:600}
        .required{color:red}
    </style>
</head>
<body>
<div class="register-container">
    <div class="register-header">
        <h1>🛍️ Clothing Store</h1>
        <p>Tạo tài khoản mới</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <strong>Vui lòng sửa các lỗi sau:</strong>
            <ul style="margin-left:18px;margin-top:8px">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo e($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?><div class="success-message"><?php echo e($success); ?></div><?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="full_name">Họ và tên <span class="required">*</span></label>
            <input type="text" id="full_name" name="full_name" value="<?php echo e($formData['full_name']); ?>" placeholder="Nhập họ và tên">
        </div>

        <div class="form-group">
            <label for="email">Email <span class="required">*</span></label>
            <input type="email" id="email" name="email" value="<?php echo e($formData['email']); ?>" placeholder="Nhập email">
        </div>

        <div class="form-group">
            <label for="phone">Số điện thoại</label>
            <input type="text" id="phone" name="phone" value="<?php echo e($formData['phone']); ?>" placeholder="Nhập số điện thoại (tuỳ chọn)">
        </div>

        <div class="form-group">
            <label for="address">Địa chỉ</label>
            <input type="text" id="address" name="address" value="<?php echo e($formData['address']); ?>" placeholder="Nhập địa chỉ (tuỳ chọn)">
        </div>

        <div class="form-group">
            <label for="password">Password <span class="required">*</span></label>
            <input type="password" id="password" name="password" placeholder="Nhập password (ít nhất 6 ký tự)">
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password <span class="required">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Nhập lại password">
        </div>

        <button type="submit" class="btn-register">Đăng ký</button>
    </form>

    <div class="login-link">
        Đã có tài khoản? <a href="login.php">Đăng nhập ngay</a>
    </div>
</div>
</body>
</html>
