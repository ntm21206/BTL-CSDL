<?php
function tien($x) { return number_format((float)$x, 0, ',', '.') . 'đ'; }
function gio_phut($p) { $p = (int)$p; return intdiv($p, 60) . 'g ' . str_pad($p % 60, 2, '0', STR_PAD_LEFT) . 'p'; }
function tinh_tien_may($phut, $gia) { $phut = max(1, (int)$phut); return ceil($phut * (float)$gia / 60 / 100) * 100; }
function lay_ca($conn, $nv_id) {
    $st = $conn->prepare("SELECT `ID ca` FROM `ca làm` WHERE `Nhân viên_Mã nhân viên` = ? AND `Giờ ra` IS NULL ORDER BY `ID ca` DESC LIMIT 1");
    $st->bind_param('i', $nv_id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    if ($r) return (int)$r['ID ca'];
    $st = $conn->prepare("INSERT INTO `ca làm` (`Giờ vào`, `Tiền đầu ca`, `Nhân viên_Mã nhân viên`) VALUES (NOW(), 0, ?)");
    $st->bind_param('i', $nv_id);
    $st->execute();
    return (int)$conn->insert_id;
}
function bao($loai, $msg) { $_SESSION['flash'] = [$loai, $msg]; }
function quyen_trang() {
    return [
        'so_do_may'  => ['Quản lý', 'Nhân viên', 'Kỹ thuật'],
        'khach_hang' => ['Quản lý', 'Nhân viên'],
        'goi_mon'    => ['Quản lý', 'Nhân viên'],
        'hoa_don'    => ['Quản lý', 'Nhân viên'],
        'bao_cao'    => ['Quản lý', 'Nhân viên'],
        'quan_ly'    => ['Quản lý'],
    ];
}
function co_quyen($trang) {
    $vt = $_SESSION['nv']['vaitro'] ?? '';
    return in_array($vt, quyen_trang()[$trang] ?? [], true);
}
function yeu_cau_quyen($trang) {
    if (empty($_SESSION['nv'])) { header('Location: login.php'); exit; }
    if (!co_quyen($trang)) {
        http_response_code(403);
        dau_trang('Không có quyền truy cập');
        echo '<p class="loi">Vai trò "' . e($_SESSION['nv']['vaitro']) . '" không được vào trang này.</p>';
        echo '<a class="btn b1" style="align-self:flex-start" href="so_do_may.php">Về Sơ đồ máy</a>';
        cuoi_trang();
        exit;
    }
}

function dau_trang($tieu_de, $act = '') {
    $menu = [
        'so_do_may'  => ['so_do_may.php',  'Sơ đồ máy', true],
        'khach_hang' => ['khach_hang.php', 'Khách hàng & nạp tiền', true],
        'goi_mon'    => ['goi_mon.php',    'Gọi món', true],
        'hoa_don'    => ['hoa_don.php',    'Hóa đơn', true],
        'bao_cao'    => ['bao_cao.php',    'Báo cáo', true],
        'quan_ly'    => ['quan_ly.php', 'Quản lý danh mục', true],
    ];
    $nv = $_SESSION['nv'];
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tieu_de) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@600;700&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">
<aside class="side">
<p class="logo">Tiệm Net</p>
<?php foreach ($menu as $k => $mn): if (!co_quyen($k)) continue; ?>
  <a class="nav<?= $k === $act ? ' act' : '' ?>" href="<?= $mn[0] ?>"><?= e($mn[1]) ?></a>
<?php endforeach; 
?>
<a class="nav" href="logout.php">Đăng xuất</a>
</aside>
<main class="main">
<div class="top">
  <h1><?= e($tieu_de) ?></h1>
  <div class="who"><?= e($nv['ten']) ?> · <?= e($nv['vaitro']) ?></div>
</div>
<?php if (!empty($_SESSION['flash'])): [$fl, $fm] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<p class="<?= $fl === 'loi' ? 'loi' : 'ok' ?>"><?= e($fm) ?></p>
<?php endif; ?>
<?php
}

function cuoi_trang() { echo "</main>\n</div>\n</body>\n</html>"; }