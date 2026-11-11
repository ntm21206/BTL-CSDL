<?php
require 'config.php';
require 'giao_dien.php';
require 'phien.php';

function kh_bao($loai, $msg) { $_SESSION['kh_flash'] = [$loai, $msg]; }

tu_dong_ket_thuc_het_tien($conn);
$may_id = (int)($_GET['may'] ?? $_POST['may'] ?? 0);

/* ---------- Thao tác ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $kh = $_SESSION['kh'] ?? null;
    if ($kh) $may_id = (int)$kh['may'];
    try {
        if ($act === 'dang_nhap') {
            $u = trim($_POST['user'] ?? ''); $pw = $_POST['pass'] ?? '';
            $conn->begin_transaction();
            $st = $conn->prepare("SELECT `ID Tài khoản` AS id, `Mật khẩu` AS mk, `Trạng thái` AS tt, `Số dư còn lại` AS so_du FROM `tài khoản` WHERE `Tên đăng nhập` = ? FOR UPDATE");
            $st->bind_param('s', $u);
            $st->execute();
            $t = $st->get_result()->fetch_assoc();
            $ok = false;
            if ($t) {
                if (password_verify($pw, $t['mk'])) $ok = true;
                elseif (hash_equals($t['mk'], $pw)) {          // mật khẩu cũ lưu chữ thường: băm lại
                    $ok = true;
                    $h = password_hash($pw, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE `tài khoản` SET `Mật khẩu` = ? WHERE `ID Tài khoản` = ?");
                    $up->bind_param('si', $h, $t['id']);
                    $up->execute();
                }
            }
            if (!$ok) throw new Exception('Sai tên đăng nhập hoặc mật khẩu.');
            if ($t['tt'] !== 'Hoạt động') throw new Exception('Tài khoản đang bị khóa.');

            $st = $conn->prepare("SELECT m.`Trạng thái` AS tt, k.`Đơn giá` AS gia FROM `máy tính` m JOIN `khu vực` k ON k.`ID Khu vực` = m.`Khu vực_ID Khu vực` WHERE m.`ID Máy` = ? FOR UPDATE");
            $st->bind_param('i', $may_id);
            $st->execute();
            $m = $st->get_result()->fetch_assoc();
            if (!$m) throw new Exception('Không tìm thấy máy.');

            $st = $conn->prepare("SELECT `ID Phiên` AS id, `Máy tính_ID Máy` AS may FROM `phiên sử dụng máy` WHERE `Tài khoản_ID Tài khoản` = ? AND `Thời gian kết thúc` IS NULL LIMIT 1");
            $st->bind_param('i', $t['id']);
            $st->execute();
            $cu = $st->get_result()->fetch_assoc();
            if ($cu) {
                if ((int)$cu['may'] !== $may_id) throw new Exception('Tài khoản này đang chơi ở máy khác.');
                $phien = (int)$cu['id'];                        // đăng nhập lại cùng máy: nối lại phiên
            } else {
                if ($m['tt'] === 'Bảo trì') throw new Exception('Máy đang bảo trì.');
                if ($m['tt'] !== 'Offline') throw new Exception('Máy đang có người dùng.');
                $toi_thieu = tinh_tien_may(15, $m['gia']);
                if ((float)$t['so_du'] < $toi_thieu) throw new Exception('Số dư không đủ (cần tối thiểu ' . tien($toi_thieu) . '). Vui lòng nạp thêm tại quầy.');
                $gia = (float)$m['gia'];
                $tkid = (int)$t['id'];
                $st = $conn->prepare("INSERT INTO `phiên sử dụng máy` (`Thời gian bắt đầu`, `Thời gian kết thúc`, `Thời lượng`, `Đơn giá`, `Tiền sử dụng`, `Tài khoản_ID Tài khoản`, `Máy tính_ID Máy`) VALUES (NOW(), NULL, 0, ?, 0, ?, ?)");
                $st->bind_param('dii', $gia, $tkid, $may_id);
                $st->execute();
                $phien = (int)$conn->insert_id;
                $st = $conn->prepare("UPDATE `máy tính` SET `Trạng thái` = 'Online' WHERE `ID Máy` = ?");
                $st->bind_param('i', $may_id);
                $st->execute();
            }
            $conn->commit();
            session_regenerate_id(true);
            $_SESSION['kh'] = ['phien' => $phien, 'tk' => (int)$t['id'], 'may' => $may_id];
            kh_bao('ok', 'Đăng nhập thành công. Chúc bạn chơi vui!');

        } elseif ($act === 'goi') {
            if (!$kh) throw new Exception('Bạn chưa đăng nhập vào máy.');
            $dv = (int)($_POST['dv'] ?? 0); $sl = (int)($_POST['sl'] ?? 0);
            if ($sl < 1 || $sl > 20) throw new Exception('Số lượng phải từ 1 đến 20.');
            $conn->begin_transaction();
            $p = thong_tin_phien($conn, (int)$kh['phien'], true);
            if (!$p || (int)$p['tk'] !== (int)$kh['tk']) throw new Exception('Phiên đã kết thúc.');
            $st = $conn->prepare("SELECT `Tên dịch vụ` AS ten, `Đơn giá` AS gia, `Số lượng tồn` AS ton FROM `dịch vụ` WHERE `Mã dịch vụ` = ? FOR UPDATE");
            $st->bind_param('i', $dv);
            $st->execute();
            $m = $st->get_result()->fetch_assoc();
            if (!$m) throw new Exception('Không tìm thấy món.');
            if ((int)$m['ton'] < $sl) throw new Exception('Không đủ hàng: ' . $m['ten'] . ' chỉ còn ' . (int)$m['ton'] . '.');
            $gia = (float)$m['gia']; $thanh = $gia * $sl;
            $du_kien = round(($p['tien_may'] + $p['dv'] + $thanh) * (100 - $p['giam']) / 100);
            if ($du_kien > $p['so_du']) throw new Exception('Số dư không đủ để gọi món này. Vui lòng nạp thêm tại quầy.');
            $phien = (int)$kh['phien'];
            $st = $conn->prepare("INSERT INTO `phiên sử dụng dịch vụ` (`Thời gian`, `Số lượng`, `Đơn giá`, `Thành tiền`, `Dịch vụ_Mã dịch vụ`, `Phiên sử dụng máy_ID Phiên`) VALUES (NOW(), ?, ?, ?, ?, ?)");
            $st->bind_param('iddii', $sl, $gia, $thanh, $dv, $phien);
            $st->execute();
            $st = $conn->prepare("UPDATE `dịch vụ` SET `Số lượng tồn` = `Số lượng tồn` - ? WHERE `Mã dịch vụ` = ?");
            $st->bind_param('ii', $sl, $dv);
            $st->execute();
            $conn->commit();
            kh_bao('ok', 'Đã gọi ' . $sl . ' × ' . $m['ten'] . ' (' . tien($thanh) . '). Nhân viên sẽ mang ra tận máy.');

        } elseif ($act === 'thoat') {
            if (!$kh) throw new Exception('Bạn chưa đăng nhập vào máy.');
            $kq = ket_thuc_phien($conn, (int)$kh['phien'], 'Số dư tài khoản');
            unset($_SESSION['kh']);
            $st = $conn->prepare("SELECT `Số dư còn lại` AS s FROM `tài khoản` WHERE `ID Tài khoản` = ?");
            $st->bind_param('i', $kh['tk']);
            $st->execute();
            $con = $st->get_result()->fetch_assoc()['s'];
            kh_bao('ok', 'Đã kết thúc phiên: chơi ' . gio_phut($kq['phut']) . ', thanh toán ' . tien($kq['tong']) . ', số dư còn lại ' . tien($con) . '.');
        }
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
        kh_bao('loi', $ex->getMessage());
    }
    header('Location: may.php' . ($may_id ? '?may=' . $may_id : ''));
    exit;
}

/* ---------- Trạng thái hiện tại ---------- */
$kh = $_SESSION['kh'] ?? null;
$p = null;
if ($kh) {
    $p = thong_tin_phien($conn, (int)$kh['phien']);
    if (!$p || (int)$p['tk'] !== (int)$kh['tk']) {
        unset($_SESSION['kh']); $kh = null; $p = null;
        kh_bao('loi', 'Phiên của bạn đã kết thúc (hết tiền hoặc nhân viên đã đóng phiên).');
    } else {
        $may_id = (int)$kh['may'];
    }
}
$flash = $_SESSION['kh_flash'] ?? null;
unset($_SESSION['kh_flash']);

$may = null;
if ($may_id) {
    $st = $conn->prepare("SELECT m.`Tên máy` AS ten, m.`Trạng thái` AS tt, k.`Mô tả` AS kv, k.`Đơn giá` AS gia FROM `máy tính` m JOIN `khu vực` k ON k.`ID Khu vực` = m.`Khu vực_ID Khu vực` WHERE m.`ID Máy` = ?");
    $st->bind_param('i', $may_id);
    $st->execute();
    $may = $st->get_result()->fetch_assoc();
}
$mays = [];
if (!$may) {
    $mays = $conn->query("SELECT m.`ID Máy` AS id, m.`Tên máy` AS ten, m.`Trạng thái` AS tt, k.`Mô tả` AS kv, k.`Đơn giá` AS gia FROM `máy tính` m JOIN `khu vực` k ON k.`ID Khu vực` = m.`Khu vực_ID Khu vực` ORDER BY m.`ID Máy`")->fetch_all(MYSQLI_ASSOC);
}
$menu = $dong = [];
if ($p) {
    $rows = $conn->query("SELECT *, `Mã dịch vụ` AS id, `Tên dịch vụ` AS ten, `Loại` AS loai, `Đơn giá` AS gia, `Số lượng tồn` AS ton FROM `dịch vụ` ORDER BY `Loại`, `Tên dịch vụ`")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) $menu[$r['loai']][] = $r;
    $st = $conn->prepare("SELECT d.`Tên dịch vụ` AS ten, s.`Số lượng` AS sl, s.`Thành tiền` AS tt FROM `phiên sử dụng dịch vụ` s JOIN `dịch vụ` d ON d.`Mã dịch vụ` = s.`Dịch vụ_Mã dịch vụ` WHERE s.`Phiên sử dụng máy_ID Phiên` = ? ORDER BY s.`ID Phiên sử dụng dịch vụ`");
    $st->bind_param('i', $p['id']);
    $st->execute();
    $dong = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $gia_gio = $p['gia'] * (100 - $p['giam']) / 100;
    $con = $gia_gio > 0 ? max(0, (int)floor(($p['so_du'] - $p['tong']) / $gia_gio * 60)) : 0;
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $may ? e($may['ten']) . ' · ' : '' ?>Tiệm Net</title>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@600;700&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="khach-body">
<div class="khach">
<?php if ($flash): ?><p class="<?= $flash[0] === 'loi' ? 'loi' : 'ok' ?>"><?= e($flash[1]) ?></p><?php endif; ?>

<?php if ($p): /* ===== ĐANG CHƠI ===== */ ?>
  <div class="top">
    <h1><?= e($may['ten']) ?> <span class="muted" style="font-size:16px">· <?= e($may['kv']) ?> · <?= tien($p['gia']) ?>/giờ</span></h1>
    <div class="who">Xin chào <b><?= e($p['tk_ten']) ?></b> · Hạng <?= e($p['hang']) ?><?= $p['giam'] > 0 ? ' (giảm ' . rtrim(rtrim((string)$p['giam'], '0'), '.') . '%)' : '' ?></div>
  </div>

  <div class="stats">
    <div class="stat"><small>Thời gian đã chơi</small><b class="dongho" id="dongho">00:00:00</b></div>
    <div class="stat"><small>Số dư</small><b><?= tien($p['so_du']) ?></b></div>
    <div class="stat"><small>Tạm tính (tiền máy + dịch vụ)</small><b><?= tien($p['tong']) ?></b></div>
    <div class="stat <?= $con < 10 ? 'het-gio' : '' ?>"><small>Còn chơi được khoảng</small><b><?= gio_phut($con) ?></b></div>
  </div>
  <?php if ($con < 10): ?><p class="loi">Số dư sắp hết, máy sẽ tự đóng khi hết tiền. Vui lòng nạp thêm tại quầy.</p><?php endif; ?>

  <div class="body">
    <div class="zones">
      <?php foreach ($menu as $loai => $ds): ?>
      <section>
        <div class="zt"><span><?= e($loai) ?></span></div>
        <div class="menu">
          <?php foreach ($ds as $m): ?>
          <form method="post" class="mon">
            <input type="hidden" name="act" value="goi">
            <input type="hidden" name="dv" value="<?= (int)$m['id'] ?>">
            <?php if (!empty($m['Hình ảnh'])): ?><img class="anh" src="hinh_anh/<?= e($m['Hình ảnh']) ?>" alt="<?= e($m['ten']) ?>" loading="lazy"><?php endif; ?>
            <b><?= e($m['ten']) ?></b>
            <span><?= tien($m['gia']) ?> · <?= (int)$m['ton'] > 0 ? 'Còn ' . (int)$m['ton'] : 'Hết hàng' ?></span>
            <div class="sl">
              <input type="number" name="sl" value="1" min="1" max="<?= max(1, min(20, (int)$m['ton'])) ?>">
              <button class="btn b2 nho"<?= (int)$m['ton'] < 1 ? ' disabled' : '' ?>>Gọi</button>
            </div>
          </form>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    </div>

    <aside class="panel">
      <h2>Chi phí phiên này</h2>
      <div class="row"><span>Tiền máy</span><b><?= tien($p['tien_may']) ?></b></div>
      <?php foreach ($dong as $d): ?>
      <div class="row"><span><?= e($d['ten']) ?> × <?= (int)$d['sl'] ?></span><b><?= tien($d['tt']) ?></b></div>
      <?php endforeach; ?>
      <div class="hr"></div>
      <div class="tot"><span>Tạm tính</span><b><?= tien($p['tong']) ?></b></div>
      <form method="post" onsubmit="return confirm('Kết thúc phiên và thanh toán bằng số dư?')">
        <input type="hidden" name="act" value="thoat">
        <button class="btn b2" style="width:100%">Kết thúc phiên &amp; đăng xuất</button>
      </form>
    </aside>
  </div>
<script>
var giay = <?= (int)$p['giay'] ?>, el = document.getElementById('dongho');
function hien() { var h = Math.floor(giay / 3600), m = Math.floor(giay % 3600 / 60), s = giay % 60;
  el.textContent = [h, m, s].map(function (x) { return String(x).padStart(2, '0'); }).join(':'); }
hien(); setInterval(function () { giay++; hien(); }, 1000);
setInterval(function () { var a = document.activeElement; if (!a || a.tagName !== 'INPUT') location.reload(); }, 30000);
</script>

<?php elseif ($may): /* ===== ĐĂNG NHẬP VÀO MÁY ===== */ ?>
  <form method="post" class="login-box dang-nhap">
    <h1 class="logo"><?= e($may['ten']) ?></h1>
    <p class="muted"><?= e($may['kv']) ?> · <?= tien($may['gia']) ?>/giờ</p>
    <?php if ($may['tt'] === 'Bảo trì'): ?>
      <p class="loi">Máy đang bảo trì, vui lòng chọn máy khác.</p>
    <?php else: ?>
      <input type="hidden" name="act" value="dang_nhap">
      <input type="hidden" name="may" value="<?= (int)$may_id ?>">
      <label>Tên đăng nhập<input name="user" required autofocus></label>
      <label>Mật khẩu<input name="pass" type="password" required></label>
      <button class="btn b2">Đăng nhập &amp; bắt đầu chơi</button>
      <p class="muted" style="font-size:13px">Chưa có tài khoản hoặc hết tiền? Liên hệ quầy để đăng ký và nạp tiền.</p>
    <?php endif; ?>
    <a class="muted" style="font-size:13px" href="may.php">← Chọn máy khác</a>
  </form>

<?php else: /* ===== CHỌN MÁY (dùng khi demo) ===== */ ?>
  <h1 class="logo" style="text-align:center">Chọn máy bạn đang ngồi</h1>
  <p class="muted" style="text-align:center"></p>
  <div class="grid">
    <?php foreach ($mays as $m): $cls = $m['tt'] === 'Online' ? 'on' : ($m['tt'] === 'Offline' ? 'off' : 'mt'); ?>
    <a class="m <?= $cls ?>" href="may.php?may=<?= (int)$m['id'] ?>">
      <b><?= e($m['ten']) ?></b><i class="s"><?= $m['tt'] === 'Offline' ? 'Trống' : ($m['tt'] === 'Online' ? 'Đang có người' : 'Bảo trì') ?></i><span><?= e($m['kv']) ?> · <?= tien($m['gia']) ?>/giờ</span>
    </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
</body>
</html>