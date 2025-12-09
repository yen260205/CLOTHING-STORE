<?php

function validateEmail($email): array {
    $errors = [];
    if (empty($email)) $errors[] = "Email không được để trống";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email không hợp lệ";
    return $errors;
}

function validatePassword($password): array {
    $errors = [];
    if (empty($password)) $errors[] = "Password không được để trống";
    elseif (strlen($password) < 6) $errors[] = "Password phải có ít nhất 6 ký tự";
    elseif (strlen($password) > 72) $errors[] = "Password quá dài";
    return $errors;
}

function validateFullName($fullname): array {
    $errors = [];
    if (empty($fullname)) $errors[] = "Họ tên không được để trống";
    elseif (mb_strlen($fullname) < 3) $errors[] = "Họ tên phải có ít nhất 3 ký tự";
    return $errors;
}

function validatePhone($phone): array {
    $errors = [];
    if ($phone === '') return $errors; // optional
    if (!preg_match('/^[0-9+\-\s]{6,30}$/', $phone)) $errors[] = "Số điện thoại không hợp lệ";
    return $errors;
}

function validateAddress($address): array {
    $errors = [];
    if ($address === '') return $errors; // optional
    if (mb_strlen($address) < 5) $errors[] = "Địa chỉ quá ngắn";
    return $errors;
}

function checkEmailExists($conn, $email, $excludeId = null): bool {
    $sql = "SELECT id FROM users WHERE email = ? " . ($excludeId ? "AND id <> ?" : "") . " LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    if ($excludeId) mysqli_stmt_bind_param($stmt, "si", $email, $excludeId);
    else mysqli_stmt_bind_param($stmt, "s", $email);

    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function validateProductNew($name, $price, $stock): array {
    $errors = [];
    if (empty($name)) $errors[] = "Tên sản phẩm không được để trống";
    if ($price === '' || !is_numeric($price) || (float)$price <= 0) $errors[] = "Giá phải là số dương";
    if ($stock === '' || !is_numeric($stock) || (int)$stock < 0) $errors[] = "Tồn kho phải là số không âm";
    return $errors;
}

/** Image */
function validateImage($file): array {
    $errors = [];
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return $errors;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Lỗi khi upload file";
        return $errors;
    }

    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) $errors[] = "Kích thước file không được vượt quá 5MB";

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($file['tmp_name']);
    if (!in_array($fileType, $allowedTypes, true)) $errors[] = "Chỉ chấp nhận JPG, PNG, GIF, WEBP";

    return $errors;
}

function uploadProductImage($file) {
    $uploadDir = 'uploads/products/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension === '') $extension = 'jpg';

    $filename = uniqid('product_', true) . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) return $filename;
    return false;
}

function deleteProductImage($filename): bool {
    if (empty($filename) || $filename === 'default.jpg') return true;
    $filepath = 'uploads/products/' . $filename;
    if (file_exists($filepath)) return unlink($filepath);
    return true;
}
