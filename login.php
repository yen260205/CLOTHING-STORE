<?php
require_once 'config.php';
require_once 'validation.php';

if (isLoggedIn()) redirect('home.php');

$error = '';
$success = '';

$remembered_email = (isset($_COOKIE['remember_email']) && !isLoggedIn()) ? $_COOKIE['remember_email'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = cleanInput($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    $errs = array_merge([], validateEmail($email));
    if (empty($password)) $errs[] = "Password không được để trống";

    if (!empty($errs)) {
        $error = implode("<br>", array_map('e', $errs));
    } else {
        $conn = getDBConnection();

        $stmt = mysqli_prepare($conn, "SELECT id, full_name, email, password, role FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();

            if ($remember) setcookie('remember_email', $email, time() + (86400 * 30), '/');
            else setcookie('remember_email', '', time() - 3600, '/');

            redirect('home.php');
        } else {
            $error = "Email hoặc password không đúng";
        }

        mysqli_stmt_close($stmt);
        mysqli_close($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Clothing Store</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            background-image:url('images/background.jpg');
            background-size:cover;background-repeat:no-repeat;background-position:center;
            min-height:100vh;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            display:flex;justify-content:center;align-items:center;padding:20px
        }
        .login-container{background:#fff;padding:40px;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,.2);width:100%;max-width:400px}
        .login-header{text-align:center;margin-bottom:30px}
        .login-header h1{color:#333;font-size:28px;margin-bottom:10px}
        .login-header p{color:#666;font-size:14px}
        .form-group{margin-bottom:20px}
        .form-group label{display:block;margin-bottom:8px;color:#333;font-weight:500}
        .form-group input{width:100%;padding:12px;border:1px solid #ddd;border-radius:5px;font-size:14px}
        .remember-me{display:flex;align-items:center;margin-bottom:20px}
        .remember-me input{margin-right:8px}
        .remember-me label{color:#666;font-size:14px}
        .btn-login{width:100%;padding:12px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border:none;border-radius:5px;font-size:16px;font-weight:600;cursor:pointer}
        .error-message{background:#fee;color:#c33;padding:12px;border-radius:5px;margin-bottom:20px;border-left:4px solid #c33}
        .register-link{text-align:center;margin-top:20px;color:#666;font-size:14px}
        .register-link a{color:#667eea;text-decoration:none;font-weight:600}
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-header">
        <h1>🛍️ Clothing Store</h1>
        <p>Đăng nhập vào hệ thống</p>
    </div>

    <?php if ($error): ?><div class="error-message"><?php echo $error; ?></div><?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?php echo e($remembered_email); ?>" placeholder="Nhập email">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Nhập password">
        </div>

        <div class="remember-me">
            <input type="checkbox" id="remember" name="remember" <?php echo $remembered_email ? 'checked' : ''; ?>>
            <label for="remember">Ghi nhớ đăng nhập</label>
        </div>

        <button type="submit" class="btn-login">Đăng nhập</button>
    </form>

    <div class="register-link">
        Chưa có tài khoản? <a href="registration.php">Đăng ký ngay</a>
    </div>
</div>
</body>
</html>
