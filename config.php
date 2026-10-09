<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
if (file_exists(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: 'csdl_net';
$port = (int)(getenv('DB_PORT') ?: 3306);

$conn = mysqli_init();
$flags = 0;
if (getenv('DB_SSL') === '1') {   // nhiều nhà cung cấp MySQL bắt buộc SSL
    $conn->ssl_set(null, null, null, null, null);
    $flags = MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
}
$conn->real_connect($host, $user, $pass, $name, $port, null, $flags);
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+07:00'");   // server trên mạng chạy giờ UTC, thiếu dòng này giờ sẽ lệch 7 tiếng

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function yeu_cau_dang_nhap() {
    if (empty($_SESSION['nv'])) { header('Location: login.php'); exit; }
}