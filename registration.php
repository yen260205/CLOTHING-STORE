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
