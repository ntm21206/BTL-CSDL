<?php
require 'config.php';
$loi = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT `Mã nhân viên`, `Họ và tên`, `Vai trò`, `Mật khẩu` FROM `nhân viên` WHERE `Tên đăng nhập` = ?");
    $stmt->bind_param('s', $u);
    $stmt->execute();
    $nv = $stmt->get_result()->fetch_assoc();
    $ok = false;
    if ($nv) {
        if (password_verify($p, $nv['Mật khẩu'])) {
            $ok = true;
        } elseif (hash_equals($nv['Mật khẩu'], $p)) {      // mật khẩu cũ lưu chữ thường
            $ok = true;
            $h = password_hash($p, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE `nhân viên` SET `Mật khẩu` = ? WHERE `Mã nhân viên` = ?");
            $up->bind_param('si', $h, $nv['Mã nhân viên']);
            $up->execute();
        }
    }
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['nv'] = ['id' => $nv['Mã nhân viên'], 'ten' => $nv['Họ và tên'], 'vaitro' => $nv['Vai trò']];
        header('Location: trang_chu.php');
        exit;
    }
    $loi = 'Sai tên đăng nhập hoặc mật khẩu';
}

?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Đăng nhập</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
<form method="post" class="login-box">
  <h1 class="logo">Tiệm Net</h1>
  <p class="muted">Đăng nhập nhân viên</p>
  <?php if ($loi): ?><p class="loi"><?= e($loi) ?></p><?php endif; ?>
  <label>Tên đăng nhập<input name="username" required autofocus></label>
  <label>Mật khẩu<input name="password" type="password" required></label>
  <button class="btn b2">Đăng nhập</button>
</form>
</body>
</html>