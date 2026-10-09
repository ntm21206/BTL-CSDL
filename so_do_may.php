<?php
require 'config.php';
require 'giao_dien.php';
yeu_cau_quyen('so_do_may');
$nv = $_SESSION['nv'];
$thu_ngan = in_array($nv['vaitro'], ['Quản lý', 'Nhân viên'], true);
$bao_tri  = in_array($nv['vaitro'], ['Quản lý', 'Kỹ thuật'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $may_id = (int)($_POST['may'] ?? 0);
    try {
              if (in_array($act, ['mo_phien', 'ket_thuc'], true) && !$thu_ngan) throw new Exception('Bạn không có quyền mở hoặc kết thúc phiên.');
        if ($act === 'bao_tri' && !$bao_tri) throw new Exception('Bạn không có quyền đổi trạng thái bảo trì.');
        if ($act === 'mo_phien') {
            $tk = (int)($_POST['tk'] ?? 0);
            $conn->begin_transaction();
            $st = $conn->prepare("SELECT m.`Trạng thái` AS tt, k.`Đơn giá` AS gia FROM `Máy tính` m JOIN `Khu vực` k ON k.`ID Khu vực` = m.`Khu vực_ID Khu vực` WHERE m.`ID Máy` = ? FOR UPDATE");
            $st->bind_param('i', $may_id);
            $st->execute();
            $m = $st->get_result()->fetch_assoc();
            if (!$m || $m['tt'] !== 'Offline') throw new Exception('Máy này không sẵn sàng để mở phiên.');
            if ($tk > 0) {
                $st = $conn->prepare("SELECT COUNT(*) AS c FROM `Phiên sử dụng máy` WHERE `Tài khoản_ID Tài khoản` = ? AND `Thời gian kết thúc` IS NULL");
                $st->bind_param('i', $tk);
                $st->execute();
                if ((int)$st->get_result()->fetch_assoc()['c'] > 0) throw new Exception('Tài khoản này đang chơi ở máy khác.');
            }
            $gia = (float)$m['gia'];
            $tk_val = $tk > 0 ? $tk : null;
            $st = $conn->prepare("INSERT INTO `Phiên sử dụng máy` (`Thời gian bắt đầu`, `Thời gian kết thúc`, `Thời lượng`, `Đơn giá`, `Tiền sử dụng`, `Tài khoản_ID Tài khoản`, `Máy tính_ID Máy`) VALUES (NOW(), NULL, 0, ?, 0, ?, ?)");
            $st->bind_param('dii', $gia, $tk_val, $may_id);
            $st->execute();
            $st = $conn->prepare("UPDATE `Máy tính` SET `Trạng thái` = 'Online' WHERE `ID Máy` = ?");
            $st->bind_param('i', $may_id);
            $st->execute();
            $conn->commit();
            bao('ok', 'Đã mở phiên.');

        } elseif ($act === 'ket_thuc') {
            $pt = $_POST['pt'] ?? 'Tiền mặt';
            if (!in_array($pt, ['Tiền mặt', 'QR', 'Thẻ ngân hàng', 'Số dư tài khoản'], true)) throw new Exception('Phương thức thanh toán không hợp lệ.');
            $conn->begin_transaction();
            $st = $conn->prepare("SELECT p.`ID Phiên` AS id, p.`Đơn giá` AS gia, p.`Tài khoản_ID Tài khoản` AS tk, TIMESTAMPDIFF(MINUTE, p.`Thời gian bắt đầu`, NOW()) AS phut FROM `Phiên sử dụng máy` p WHERE p.`Máy tính_ID Máy` = ? AND p.`Thời gian kết thúc` IS NULL LIMIT 1 FOR UPDATE");
            $st->bind_param('i', $may_id);
            $st->execute();
            $ph = $st->get_result()->fetch_assoc();
            if (!$ph) throw new Exception('Máy này không có phiên đang chạy.');
            $phien = (int)$ph['id'];
            $tk = $ph['tk'] ? (int)$ph['tk'] : 0;
            $phut = max(1, (int)$ph['phut']);
            $tien_may = tinh_tien_may($phut, $ph['gia']);

            $st = $conn->prepare("SELECT COALESCE(SUM(`Thành tiền`), 0) AS s FROM `Phiên sử dụng dịch vụ` WHERE `Phiên sử dụng máy_ID Phiên` = ?");
            $st->bind_param('i', $phien);
            $st->execute();
            $dv = (float)$st->get_result()->fetch_assoc()['s'];

            $giam = 0; $so_du = 0;
            if ($tk) {
                $st = $conn->prepare("SELECT t.`Số dư còn lại` AS so_du, h.`Phần trăm giảm giá` AS giam FROM `Tài khoản` t JOIN `Khách hàng` k ON k.`ID Khách hàng` = t.`Khách hàng_ID Khách hàng` JOIN `Hạng thành viên` h ON h.`ID Hạng` = k.`Hạng thành viên_ID Hạng` WHERE t.`ID Tài khoản` = ? FOR UPDATE");
                $st->bind_param('i', $tk);
                $st->execute();
                $r = $st->get_result()->fetch_assoc();
                if ($r) { $giam = (float)$r['giam']; $so_du = (float)$r['so_du']; }
            }
            $tong = round(($tien_may + $dv) * (100 - $giam) / 100);
            $tm2  = round($tien_may * (100 - $giam) / 100);
            $dv2  = $tong - $tm2;

            if ($pt === 'Số dư tài khoản') {
                if (!$tk) throw new Exception('Khách vãng lai không có số dư để trừ.');
                if ($so_du < $tong) throw new Exception('Số dư không đủ (' . tien($so_du) . ' < ' . tien($tong) . ').');
                $moi = $so_du - $tong;
                $st = $conn->prepare("UPDATE `Tài khoản` SET `Số dư còn lại` = ? WHERE `ID Tài khoản` = ?");
                $st->bind_param('di', $moi, $tk);
                $st->execute();
                $ls = $conn->prepare("INSERT INTO `Lịch sử số dư` (`Loại biến động`, `Số tiền`, `Số dư sau`, `Thời gian`, `Mô tả`, `Tài khoản_ID Tài khoản`, `Phiên sử dụng máy_ID Phiên`) VALUES (?, ?, ?, NOW(), ?, ?, ?)");
                $loai = 'Trừ tiền chơi'; $sotien = -$tm2; $sdsau = $so_du - $tm2; $mota = 'Chơi máy ' . $phut . ' phút';
                $ls->bind_param('sddsii', $loai, $sotien, $sdsau, $mota, $tk, $phien);
                $ls->execute();
                if ($dv2 > 0) {
                    $loai = 'Trừ tiền dịch vụ'; $sotien = -$dv2; $sdsau = $moi; $mota = 'Dịch vụ trong phiên';
                    $ls->execute();
                }
            }

            $st = $conn->prepare("UPDATE `Phiên sử dụng máy` SET `Thời gian kết thúc` = NOW(), `Thời lượng` = ?, `Tiền sử dụng` = ? WHERE `ID Phiên` = ?");
            $st->bind_param('idi', $phut, $tien_may, $phien);
            $st->execute();

            $ca = lay_ca($conn, (int)$nv['id']);
            $nv_id = (int)$nv['id'];
            $st = $conn->prepare("INSERT INTO `Hóa đơn` (`Tiền dịch vụ`, `Tổng hóa đơn`, `Trạng thái`, `Ngày xuất`, `Phương thức thanh toán`, `Ca làm_ID ca`, `Phiên sử dụng máy_ID Phiên`, `Nhân viên_Mã nhân viên`) VALUES (?, ?, 'Đã thanh toán', NOW(), ?, ?, ?, ?)");
            $st->bind_param('ddsiii', $dv, $tong, $pt, $ca, $phien, $nv_id);
            $st->execute();

            $st = $conn->prepare("UPDATE `Máy tính` SET `Trạng thái` = 'Offline' WHERE `ID Máy` = ?");
            $st->bind_param('i', $may_id);
            $st->execute();
            $conn->commit();
            bao('ok', 'Đã kết thúc phiên và xuất hóa đơn ' . tien($tong) . ' (' . $pt . ').');

        } elseif ($act === 'bao_tri') {
            $st = $conn->prepare("SELECT `Trạng thái` AS tt FROM `Máy tính` WHERE `ID Máy` = ?");
            $st->bind_param('i', $may_id);
            $st->execute();
            $m = $st->get_result()->fetch_assoc();
            if (!$m || $m['tt'] === 'Online') throw new Exception('Không thể đổi trạng thái máy đang có người chơi.');
            $moi = $m['tt'] === 'Bảo trì' ? 'Offline' : 'Bảo trì';
            $st = $conn->prepare("UPDATE `Máy tính` SET `Trạng thái` = ? WHERE `ID Máy` = ?");
            $st->bind_param('si', $moi, $may_id);
            $st->execute();
            bao('ok', 'Đã chuyển máy sang trạng thái ' . $moi . '.');
        }
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
        bao('loi', 'Lỗi: ' . $ex->getMessage());
    }
    header('Location: so_do_may.php?may=' . $may_id);
    exit;
}

/* ---------- Lấy dữ liệu hiển thị ---------- */
$ds = $conn->query("SELECT m.`ID Máy` AS id, m.`Tên máy` AS ten, m.`Trạng thái` AS tt, m.`Cấu hình` AS cauhinh,
        k.`ID Khu vực` AS kv, k.`Vị trí` AS vitri, k.`Mô tả` AS mota, k.`Đơn giá` AS gia,
        p.`ID Phiên` AS phien, TIMESTAMPDIFF(MINUTE, p.`Thời gian bắt đầu`, NOW()) AS phut, t.`Tên đăng nhập` AS tk_ten
    FROM `máy tính` m
    JOIN `khu vực` k ON k.`ID Khu vực` = m.`Khu vực_ID Khu vực`
    LEFT JOIN `Phiên sử dụng máy` p ON p.`Máy tính_ID Máy` = m.`ID Máy` AND p.`Thời gian kết thúc` IS NULL
    LEFT JOIN `Tài khoản` t ON t.`ID Tài khoản` = p.`Tài khoản_ID Tài khoản`
    ORDER BY k.`ID Khu vực`, m.`ID Máy`")->fetch_all(MYSQLI_ASSOC);

$nhom = []; $online = 0; $chon = null;
$sel = (int)($_GET['may'] ?? 0);
foreach ($ds as $m) {
    $nhom[$m['kv']]['ten'] = $m['vitri'] . ' · ' . $m['mota'];
    $nhom[$m['kv']]['gia'] = $m['gia'];
    $nhom[$m['kv']]['may'][] = $m;
    if ($m['tt'] === 'Online') $online++;
    if ((int)$m['id'] === $sel) $chon = $m;
}
$doanh_thu = $conn->query("SELECT COALESCE(SUM(`Tổng hóa đơn`), 0) AS s FROM `Hóa đơn` WHERE DATE(`Ngày xuất`) = CURDATE() AND `Trạng thái` = 'Đã thanh toán'")->fetch_assoc()['s'];
$chua_tt = $conn->query("SELECT COUNT(*) AS c FROM `Hóa đơn` WHERE `Trạng thái` = 'Chưa thanh toán'")->fetch_assoc()['c'];

$ct = null; $dv_ds = []; $tong_tam = 0; $dv_tong = 0; $tien_may = 0;
if ($chon && $chon['phien']) {
    $st = $conn->prepare("SELECT p.`Thời gian bắt đầu` AS bd, t.`Tên đăng nhập` AS tk, t.`Số dư còn lại` AS so_du, h.`Tên hạng` AS hang, h.`Phần trăm giảm giá` AS giam
        FROM `phiên sử dụng máy` p
        LEFT JOIN `Tài khoản` t ON t.`ID Tài khoản` = p.`Tài khoản_ID Tài khoản`
        LEFT JOIN `Khách hàng` k ON k.`ID Khách hàng` = t.`Khách hàng_ID Khách hàng`
        LEFT JOIN `Hạng thành viên` h ON h.`ID Hạng` = k.`Hạng thành viên_ID Hạng`
        WHERE p.`ID Phiên` = ?");
    $st->bind_param('i', $chon['phien']);
    $st->execute();
    $ct = $st->get_result()->fetch_assoc();
    $st = $conn->prepare("SELECT d.`Tên dịch vụ` AS ten, s.`Số lượng` AS sl, s.`Thành tiền` AS tt FROM `Phiên sử dụng dịch vụ` s JOIN `Dịch vụ` d ON d.`Mã dịch vụ` = s.`Dịch vụ_Mã dịch vụ` WHERE s.`Phiên sử dụng máy_ID Phiên` = ?");
    $st->bind_param('i', $chon['phien']);
    $st->execute();
    $dv_ds = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($dv_ds as $d) $dv_tong += (float)$d['tt'];
    $tien_may = tinh_tien_may($chon['phut'], $chon['gia']);
    $tong_tam = round(($tien_may + $dv_tong) * (100 - (float)$ct['giam']) / 100);
}

$tk_ds = [];
if ($chon && $chon['tt'] === 'Offline') {
    $tk_ds = $conn->query("SELECT `ID Tài khoản` AS id, `Tên đăng nhập` AS ten, `Số dư còn lại` AS so_du FROM `Tài khoản`
        WHERE `Trạng thái` = 'Hoạt động' AND `ID Tài khoản` NOT IN (SELECT `Tài khoản_ID Tài khoản` FROM `Phiên sử dụng máy` WHERE `Thời gian kết thúc` IS NULL AND `Tài khoản_ID Tài khoản` IS NOT NULL)
        ORDER BY `Tên đăng nhập`")->fetch_all(MYSQLI_ASSOC);
}

dau_trang('Sơ đồ máy', 'so_do_may');
?>
<div class="stats">
  <div class="stat"><small>Máy đang online</small><b><?= $online ?> / <?= count($ds) ?></b></div>
  <div class="stat"><small>Doanh thu hôm nay</small><b><?= tien($doanh_thu) ?></b></div>
  <div class="stat"><small>Phiên đang chơi</small><b><?= $online ?></b></div>
  <div class="stat"><small>Hóa đơn chưa thanh toán</small><b><?= (int)$chua_tt ?></b></div>
</div>

<div class="body">
<div class="zones">
  <div class="legend">
    <span><i class="dot" style="background:#34d399"></i>Online</span>
    <span><i class="dot" style="background:#c3c9e8"></i>Offline</span>
    <span><i class="dot" style="background:#fbbf24"></i>Bảo trì</span>
  </div>
  <?php foreach ($nhom as $g): ?>
  <section>
    <div class="zt"><span><?= e($g['ten']) ?></span><em><?= tien($g['gia']) ?> / giờ</em></div>
    <div class="grid">
      <?php foreach ($g['may'] as $m):
        $cls = $m['tt'] === 'Online' ? 'on' : ($m['tt'] === 'Offline' ? 'off' : 'mt');
        if ($m['tt'] === 'Online') $sub = ($m['tk_ten'] ?: 'Khách vãng lai') . ' · ' . gio_phut($m['phut']);
        elseif ($m['tt'] === 'Offline') $sub = 'Sẵn sàng';
        else $sub = 'Đang bảo trì';
      ?>
      <a class="m <?= $cls ?><?= $chon && $chon['id'] === $m['id'] ? ' sel' : '' ?>" href="so_do_may.php?may=<?= (int)$m['id'] ?>">
        <b><?= e($m['ten']) ?></b><i class="s"><?= e($m['tt']) ?></i><span><?= e($sub) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>

<aside class="panel">
<?php if (!$chon): ?>
  <h2>Chi tiết máy</h2>
  <p class="muted">Bấm vào một máy để xem chi tiết hoặc mở phiên.</p>

<?php elseif ($chon['tt'] === 'Online' && $ct): ?>
  <h2><?= e($chon['ten']) ?></h2>
  <div class="row"><span>Tài khoản</span><b><?= e($ct['tk'] ?: 'Khách vãng lai') ?></b></div>
  <?php if ($ct['tk']): ?>
  <div class="row"><span>Hạng thành viên</span><b><?= e($ct['hang']) ?> · giảm <?= rtrim(rtrim($ct['giam'], '0'), '.') ?>%</b></div>
  <div class="row"><span>Số dư</span><b><?= tien($ct['so_du']) ?></b></div>
  <?php endif; ?>
  <div class="row"><span>Bắt đầu</span><b><?= e(substr($ct['bd'], 11, 5)) ?></b></div>
  <div class="row"><span>Thời lượng</span><b><?= gio_phut($chon['phut']) ?></b></div>
  <div class="hr"></div>
  <div class="row"><span>Tiền máy</span><b><?= tien($tien_may) ?></b></div>
  <?php foreach ($dv_ds as $d): ?>
  <div class="row"><span><?= e($d['ten']) ?> × <?= (int)$d['sl'] ?></span><b><?= tien($d['tt']) ?></b></div>
  <?php endforeach; ?>
  <div class="hr"></div>
  <div class="tot"><span>Tạm tính</span><b><?= tien($tong_tam) ?></b></div>
  <?php if ($thu_ngan): ?>
  <form method="post" onsubmit="return confirm('Kết thúc phiên và xuất hóa đơn?')">
    <input type="hidden" name="act" value="ket_thuc">
    <input type="hidden" name="may" value="<?= (int)$chon['id'] ?>">
    <label>Thanh toán bằng
      <select name="pt">
        <?php if ($ct['tk']): ?><option>Số dư tài khoản</option><?php endif; ?>
        <option>Tiền mặt</option><option>QR</option><option>Thẻ ngân hàng</option>
      </select>
    </label>
    <button class="btn b2" style="width:100%;margin-top:12px">Kết thúc phiên &amp; thanh toán</button>
  </form>
  <?php else: ?>
  <p class="muted" style="margin:0">Vai trò của bạn chỉ được xem phiên này.</p>
  <?php endif; ?>

<?php elseif ($chon['tt'] === 'Offline'): ?>
  <h2><?= e($chon['ten']) ?></h2>
  <div class="row"><span>Cấu hình</span><b><?= e($chon['cauhinh']) ?></b></div>
  <div class="row"><span>Đơn giá</span><b><?= tien($chon['gia']) ?> / giờ</b></div>
  <?php if ($thu_ngan): ?>
  <form method="post">
    <input type="hidden" name="act" value="mo_phien">
    <input type="hidden" name="may" value="<?= (int)$chon['id'] ?>">
    <label>Khách chơi
      <select name="tk">
        <option value="0">Khách vãng lai (không tài khoản)</option>
        <?php foreach ($tk_ds as $t): ?>
        <option value="<?= (int)$t['id'] ?>"><?= e($t['ten']) ?> · <?= tien($t['so_du']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn b2" style="width:100%;margin-top:12px">Mở phiên</button>
  </form>
  <?php endif; ?>
  <?php if ($bao_tri): ?>
  <form method="post">
    <input type="hidden" name="act" value="bao_tri">
    <input type="hidden" name="may" value="<?= (int)$chon['id'] ?>">
    <button class="btn b1" style="width:100%">Chuyển sang bảo trì</button>
  </form>
  <?php endif; ?>

<?php else: ?>
  <h2><?= e($chon['ten']) ?></h2>
  <p class="muted">Máy đang bảo trì.</p>
  <?php if ($bao_tri): ?>
  <form method="post">
    <input type="hidden" name="act" value="bao_tri">
    <input type="hidden" name="may" value="<?= (int)$chon['id'] ?>">
    <button class="btn b2" style="width:100%">Đưa vào hoạt động</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</aside>