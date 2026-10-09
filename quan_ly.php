<?php
require 'config.php';
require 'giao_dien.php';
yeu_cau_quyen('quan_ly');
$nv = $_SESSION['nv'];
$VAITRO = ['Quản lý', 'Nhân viên', 'Kỹ thuật'];
$muc = $_GET['muc'] ?? 'dich_vu';

/* ---------- Xử lý thao tác ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $muc = $_POST['muc'] ?? 'dich_vu';
    try {
        if ($act === 'dv_them') {
            $ten = trim($_POST['ten'] ?? ''); $loai = trim($_POST['loai'] ?? '');
            $gia = (float)($_POST['gia'] ?? 0); $ton = (int)($_POST['ton'] ?? 0);
            if ($ten === '' || $loai === '' || $gia <= 0 || $ton < 0) throw new Exception('Nhập đủ tên, loại, đơn giá lớn hơn 0 và tồn kho từ 0 trở lên.');
            $st = $conn->prepare("INSERT INTO `dịch vụ` (`Tên dịch vụ`, `Loại`, `Đơn giá`, `Số lượng tồn`) VALUES (?, ?, ?, ?)");
            $st->bind_param('ssdi', $ten, $loai, $gia, $ton);
            $st->execute();
            bao('ok', 'Đã thêm món ' . $ten . '.');

        } elseif ($act === 'dv_sua') {
            $id = (int)($_POST['id'] ?? 0); $gia = (float)($_POST['gia'] ?? 0); $them = (int)($_POST['them'] ?? 0);
            if ($gia <= 0 || $them < 0 || $them > 100000) throw new Exception('Đơn giá phải lớn hơn 0 và số nhập thêm từ 0 đến 100.000.');
            $st = $conn->prepare("UPDATE `dịch vụ` SET `Đơn giá` = ?, `Số lượng tồn` = `Số lượng tồn` + ? WHERE `Mã dịch vụ` = ?");
            $st->bind_param('dii', $gia, $them, $id);
            $st->execute();
            bao('ok', 'Đã cập nhật món.');

        } elseif ($act === 'may_them') {
            $ten = trim($_POST['ten'] ?? ''); $ch = trim($_POST['cauhinh'] ?? ''); $kv = (int)($_POST['kv'] ?? 0);
            if ($ten === '' || $ch === '' || $kv < 1) throw new Exception('Nhập đủ tên máy, cấu hình và chọn khu vực.');
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM `máy tính` WHERE `Tên máy` = ?");
            $st->bind_param('s', $ten);
            $st->execute();
            if ((int)$st->get_result()->fetch_assoc()['c'] > 0) throw new Exception('Tên máy này đã tồn tại.');
            $st = $conn->prepare("INSERT INTO `máy tính` (`Tên máy`, `Cấu hình`, `Trạng thái`, `Khu vực_ID Khu vực`) VALUES (?, ?, 'Offline', ?)");
            $st->bind_param('ssi', $ten, $ch, $kv);
            $st->execute();
            bao('ok', 'Đã thêm máy ' . $ten . '.');

        } elseif ($act === 'may_sua') {
            $id = (int)($_POST['id'] ?? 0); $ch = trim($_POST['cauhinh'] ?? ''); $kv = (int)($_POST['kv'] ?? 0);
            if ($ch === '' || $kv < 1) throw new Exception('Cấu hình và khu vực không được để trống.');
            $st = $conn->prepare("SELECT `Trạng thái` AS tt FROM `máy tính` WHERE `ID Máy` = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $m = $st->get_result()->fetch_assoc();
            if (!$m) throw new Exception('Không tìm thấy máy.');
            if ($m['tt'] === 'Online') throw new Exception('Không sửa được máy đang có người chơi.');
            $st = $conn->prepare("UPDATE `máy tính` SET `Cấu hình` = ?, `Khu vực_ID Khu vực` = ? WHERE `ID Máy` = ?");
            $st->bind_param('sii', $ch, $kv, $id);
            $st->execute();
            bao('ok', 'Đã cập nhật máy.');

        } elseif ($act === 'nv_them') {
            $ten = trim($_POST['ten'] ?? ''); $sdt = trim($_POST['sdt'] ?? '');
            $user = trim($_POST['user'] ?? ''); $mk = $_POST['mk'] ?? ''; $vt = $_POST['vt'] ?? '';
            if ($ten === '' || $user === '') throw new Exception('Nhập đủ họ tên và tên đăng nhập.');
            if (!preg_match('/^[0-9]{9,10}$/', $sdt)) throw new Exception('Số điện thoại nhân viên gồm 9 hoặc 10 chữ số.');
            if (strlen($mk) < 6) throw new Exception('Mật khẩu phải có ít nhất 6 ký tự.');
            if (!in_array($vt, $VAITRO, true)) throw new Exception('Vai trò không hợp lệ.');
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM `nhân viên` WHERE `Tên đăng nhập` = ?");
            $st->bind_param('s', $user);
            $st->execute();
            if ((int)$st->get_result()->fetch_assoc()['c'] > 0) throw new Exception('Tên đăng nhập đã tồn tại.');
            $hash = password_hash($mk, PASSWORD_DEFAULT);
            $st = $conn->prepare("INSERT INTO `nhân viên` (`Họ và tên`, `SĐT`, `Tên đăng nhập`, `Mật khẩu`, `Vai trò`) VALUES (?, ?, ?, ?, ?)");
            $st->bind_param('sssss', $ten, $sdt, $user, $hash, $vt);
            $st->execute();
            bao('ok', 'Đã thêm nhân viên ' . $ten . '.');

        } elseif ($act === 'nv_vaitro') {
            $id = (int)($_POST['id'] ?? 0); $vt = $_POST['vt'] ?? '';
            if ($id === (int)$nv['id']) throw new Exception('Không thể đổi vai trò của chính mình.');
            if (!in_array($vt, $VAITRO, true)) throw new Exception('Vai trò không hợp lệ.');
            $st = $conn->prepare("UPDATE `nhân viên` SET `Vai trò` = ? WHERE `Mã nhân viên` = ?");
            $st->bind_param('si', $vt, $id);
            $st->execute();
            bao('ok', 'Đã đổi vai trò.');

        } elseif ($act === 'nv_mk') {
            $id = (int)($_POST['id'] ?? 0); $mk = $_POST['mk'] ?? '';
            if (strlen($mk) < 6) throw new Exception('Mật khẩu mới phải có ít nhất 6 ký tự.');
            $hash = password_hash($mk, PASSWORD_DEFAULT);
            $st = $conn->prepare("UPDATE `nhân viên` SET `Mật khẩu` = ? WHERE `Mã nhân viên` = ?");
            $st->bind_param('si', $hash, $id);
            $st->execute();
            bao('ok', 'Đã đặt lại mật khẩu.');
        }
    } catch (Throwable $ex) {
        bao('loi', 'Lỗi: ' . $ex->getMessage());
    }
    header('Location: quan_ly.php?muc=' . urlencode($muc));
    exit;
}

if (!in_array($muc, ['dich_vu', 'may', 'nhan_vien'], true)) $muc = 'dich_vu';

/* ---------- Lấy dữ liệu ---------- */
$kv_ds = $conn->query("SELECT `ID Khu vực` AS id, `Vị trí` AS vitri, `Mô tả` AS mota FROM `khu vực` ORDER BY `ID Khu vực`")->fetch_all(MYSQLI_ASSOC);
$dv_ds = $may_ds = $nv_ds = [];
if ($muc === 'dich_vu') {
    $dv_ds = $conn->query("SELECT `Mã dịch vụ` AS id, `Tên dịch vụ` AS ten, `Loại` AS loai, `Đơn giá` AS gia, `Số lượng tồn` AS ton FROM `dịch vụ` ORDER BY `Loại`, `Tên dịch vụ`")->fetch_all(MYSQLI_ASSOC);
} elseif ($muc === 'may') {
    $may_ds = $conn->query("SELECT `ID Máy` AS id, `Tên máy` AS ten, `Cấu hình` AS ch, `Trạng thái` AS tt, `Khu vực_ID Khu vực` AS kv FROM `máy tính` ORDER BY `ID Máy`")->fetch_all(MYSQLI_ASSOC);
} else {
    $nv_ds = $conn->query("SELECT `Mã nhân viên` AS id, `Họ và tên` AS ten, `SĐT` AS sdt, `Tên đăng nhập` AS tk, `Vai trò` AS vt FROM `nhân viên` ORDER BY `Mã nhân viên`")->fetch_all(MYSQLI_ASSOC);
}

dau_trang('Quản lý danh mục', 'quan_ly');
?>
<div class="tabs">
  <a class="tab<?= $muc === 'dich_vu' ? ' act' : '' ?>" href="quan_ly.php?muc=dich_vu">Dịch vụ</a>
  <a class="tab<?= $muc === 'may' ? ' act' : '' ?>" href="quan_ly.php?muc=may">Máy tính</a>
  <a class="tab<?= $muc === 'nhan_vien' ? ' act' : '' ?>" href="quan_ly.php?muc=nhan_vien">Nhân viên</a>
</div>

<div class="body">
<div class="zones">
<div class="bang">

<?php if ($muc === 'dich_vu'): ?>
  <div class="dong dau" style="--c:2fr 1.2fr 1.2fr .8fr 1fr auto"><span>Tên món</span><span>Loại</span><span>Đơn giá (đ)</span><span>Tồn</span><span>Nhập thêm</span><span></span></div>
  <?php foreach ($dv_ds as $d): ?>
  <form class="dong" method="post" style="--c:2fr 1.2fr 1.2fr .8fr 1fr auto">
    <input type="hidden" name="act" value="dv_sua"><input type="hidden" name="muc" value="dich_vu">
    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
    <b><?= e($d['ten']) ?></b>
    <span><?= e($d['loai']) ?></span>
    <input type="number" name="gia" value="<?= (int)$d['gia'] ?>" min="1000" step="500">
    <span class="<?= (int)$d['ton'] < 10 ? 'tt-no' : '' ?>"><?= (int)$d['ton'] ?></span>
    <input type="number" name="them" value="0" min="0">
    <button class="btn b1 nho">Lưu</button>
  </form>
  <?php endforeach; ?>

<?php elseif ($muc === 'may'): ?>
  <div class="dong dau" style="--c:1fr 1.6fr 2fr 1fr auto"><span>Tên máy</span><span>Khu vực</span><span>Cấu hình</span><span>Trạng thái</span><span></span></div>
  <?php foreach ($may_ds as $m): $khoa = $m['tt'] === 'Online'; ?>
  <form class="dong" method="post" style="--c:1fr 1.6fr 2fr 1fr auto">
    <input type="hidden" name="act" value="may_sua"><input type="hidden" name="muc" value="may">
    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
    <b><?= e($m['ten']) ?></b>
    <select name="kv"<?= $khoa ? ' disabled' : '' ?>>
      <?php foreach ($kv_ds as $k): ?><option value="<?= (int)$k['id'] ?>"<?= (int)$k['id'] === (int)$m['kv'] ? ' selected' : '' ?>><?= e($k['vitri'] . ' · ' . $k['mota']) ?></option><?php endforeach; ?>
    </select>
    <input name="cauhinh" value="<?= e($m['ch']) ?>"<?= $khoa ? ' disabled' : '' ?>>
    <span><?= e($m['tt']) ?></span>
    <button class="btn b1 nho"<?= $khoa ? ' disabled' : '' ?>>Lưu</button>
  </form>
  <?php endforeach; ?>

<?php else: ?>
  <div class="dong dau" style="--c:1.4fr 1fr 1fr 1.4fr 1.6fr"><span>Họ tên</span><span>Đăng nhập</span><span>SĐT</span><span>Vai trò</span><span>Đặt lại mật khẩu</span></div>
  <?php foreach ($nv_ds as $n): $tu_minh = (int)$n['id'] === (int)$nv['id']; ?>
  <div class="dong" style="--c:1.4fr 1fr 1fr 1.4fr 1.6fr">
    <b><?= e($n['ten']) ?><?= $tu_minh ? ' (bạn)' : '' ?></b>
    <span><?= e($n['tk']) ?></span>
    <span><?= e($n['sdt']) ?></span>
    <form method="post" class="ngang">
      <input type="hidden" name="act" value="nv_vaitro"><input type="hidden" name="muc" value="nhan_vien">
      <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
      <select name="vt"<?= $tu_minh ? ' disabled' : '' ?>>
        <?php foreach ($VAITRO as $v): ?><option<?= $v === $n['vt'] ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
      </select>
      <button class="btn b1 nho"<?= $tu_minh ? ' disabled' : '' ?>>Lưu</button>
    </form>
    <form method="post" class="ngang">
      <input type="hidden" name="act" value="nv_mk"><input type="hidden" name="muc" value="nhan_vien">
      <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
      <input type="password" name="mk" placeholder="Mật khẩu mới" minlength="6" required>
      <button class="btn b1 nho">Đặt</button>
    </form>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

</div>
</div>

<aside class="panel">
<?php if ($muc === 'dich_vu'): ?>
  <h2>Thêm món</h2>
  <form method="post">
    <input type="hidden" name="act" value="dv_them"><input type="hidden" name="muc" value="dich_vu">
    <label>Tên món<input name="ten" required></label>
    <label style="margin-top:10px">Loại (Đồ ăn, Nước uống...)<input name="loai" required></label>
    <label style="margin-top:10px">Đơn giá (đ)<input type="number" name="gia" min="1000" step="500" required></label>
    <label style="margin-top:10px">Tồn kho ban đầu<input type="number" name="ton" min="0" value="0" required></label>
    <button class="btn b2" style="margin-top:12px">Thêm món</button>
  </form>
<?php elseif ($muc === 'may'): ?>
  <h2>Thêm máy</h2>
  <form method="post">
    <input type="hidden" name="act" value="may_them"><input type="hidden" name="muc" value="may">
    <label>Tên máy (ví dụ May-07)<input name="ten" required></label>
    <label style="margin-top:10px">Cấu hình<input name="cauhinh" required placeholder="i5 / 16GB / RTX 3060"></label>
    <label style="margin-top:10px">Khu vực
      <select name="kv"><?php foreach ($kv_ds as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['vitri'] . ' · ' . $k['mota']) ?></option><?php endforeach; ?></select>
    </label>
    <button class="btn b2" style="margin-top:12px">Thêm máy</button>
  </form>
<?php else: ?>
  <h2>Thêm nhân viên</h2>
  <form method="post">
    <input type="hidden" name="act" value="nv_them"><input type="hidden" name="muc" value="nhan_vien">
    <label>Họ và tên<input name="ten" required></label>
    <label style="margin-top:10px">Số điện thoại<input name="sdt" required inputmode="numeric"></label>
    <label style="margin-top:10px">Tên đăng nhập<input name="user" required></label>
    <label style="margin-top:10px">Mật khẩu (từ 6 ký tự)<input type="password" name="mk" minlength="6" required></label>
    <label style="margin-top:10px">Vai trò
      <select name="vt"><?php foreach ($VAITRO as $v): ?><option<?= $v === 'Nhân viên' ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    </label>
    <button class="btn b2" style="margin-top:12px">Thêm nhân viên</button>
  </form>
<?php endif; ?>
</aside>
</div>
<?php cuoi_trang(); ?>