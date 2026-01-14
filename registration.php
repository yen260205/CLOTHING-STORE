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
