<?php
require 'config.php';
require 'giao_dien.php';
yeu_cau_quyen('hoa_don');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'thanh_toan') {
    $id = (int)($_POST['id'] ?? 0);
    $pt = $_POST['pt'] ?? '';
    try {
        if (!in_array($pt, ['Tiền mặt', 'QR', 'Thẻ ngân hàng'], true)) throw new Exception('Phương thức thanh toán không hợp lệ.');
        $st = $conn->prepare("UPDATE `hóa đơn` SET `Trạng thái` = 'Đã thanh toán', `Phương thức thanh toán` = ? WHERE `Mã hóa đơn` = ? AND `Trạng thái` = 'Chưa thanh toán'");
        $st->bind_param('si', $pt, $id);
        $st->execute();
        if ($st->affected_rows === 0) throw new Exception('Hóa đơn này đã được thanh toán.');
        bao('ok', 'Đã ghi nhận thanh toán hóa đơn #' . $id . '.');
    } catch (Throwable $ex) {
        bao('loi', 'Lỗi: ' . $ex->getMessage());
    }
    header('Location: hoa_don.php?id=' . $id);
    exit;
}

$hom = $conn->query("SELECT CURDATE() AS d, CURDATE() - INTERVAL 6 DAY AS d6")->fetch_assoc();
$tu  = $_GET['tu']  ?? $hom['d6'];
$den = $_GET['den'] ?? $hom['d'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tu))  $tu  = $hom['d6'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $den)) $den = $hom['d'];

$st = $conn->prepare("SELECT h.`Mã hóa đơn` AS id, h.`Ngày xuất` AS ngay, h.`Tổng hóa đơn` AS tong, h.`Trạng thái` AS tt, h.`Phương thức thanh toán` AS pt,
        m.`Tên máy` AS may, t.`Tên đăng nhập` AS tk, n.`Họ và tên` AS nv
    FROM `hóa đơn` h
    JOIN `phiên sử dụng máy` p ON p.`ID Phiên` = h.`Phiên sử dụng máy_ID Phiên`
    JOIN `máy tính` m ON m.`ID Máy` = p.`Máy tính_ID Máy`
    LEFT JOIN `tài khoản` t ON t.`ID Tài khoản` = p.`Tài khoản_ID Tài khoản`
    JOIN `nhân viên` n ON n.`Mã nhân viên` = h.`Nhân viên_Mã nhân viên`
    WHERE DATE(h.`Ngày xuất`) BETWEEN ? AND ?
    ORDER BY h.`Mã hóa đơn` DESC LIMIT 200");
$st->bind_param('ss', $tu, $den);
$st->execute();
$ds = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$tong_thu = 0;
foreach ($ds as $r) { if ($r['tt'] === 'Đã thanh toán') $tong_thu += (float)$r['tong']; }

$sel = (int)($_GET['id'] ?? 0);
$ct = null; $dv_ds = [];
if ($sel) {
    $st = $conn->prepare("SELECT h.`Mã hóa đơn` AS id, h.`Ngày xuất` AS ngay, h.`Tiền dịch vụ` AS dv, h.`Tổng hóa đơn` AS tong, h.`Trạng thái` AS tt, h.`Phương thức thanh toán` AS pt,
            h.`Phiên sử dụng máy_ID Phiên` AS phien,
            p.`Thời gian bắt đầu` AS bd, p.`Thời gian kết thúc` AS kt, p.`Thời lượng` AS tl, p.`Đơn giá` AS gia, p.`Tiền sử dụng` AS tm,
            m.`Tên máy` AS may, t.`Tên đăng nhập` AS tk, n.`Họ và tên` AS nv
        FROM `hóa đơn` h
        JOIN `phiên sử dụng máy` p ON p.`ID Phiên` = h.`Phiên sử dụng máy_ID Phiên`
        JOIN `máy tính` m ON m.`ID Máy` = p.`Máy tính_ID Máy`
        LEFT JOIN `tài khoản` t ON t.`ID Tài khoản` = p.`Tài khoản_ID Tài khoản`
        JOIN `nhân viên` n ON n.`Mã nhân viên` = h.`Nhân viên_Mã nhân viên`
        WHERE h.`Mã hóa đơn` = ?");
    $st->bind_param('i', $sel);
    $st->execute();
    $ct = $st->get_result()->fetch_assoc();
    if ($ct) {
        $st = $conn->prepare("SELECT d.`Tên dịch vụ` AS ten, s.`Số lượng` AS sl, s.`Đơn giá` AS gia, s.`Thành tiền` AS tt FROM `phiên sử dụng dịch vụ` s JOIN `dịch vụ` d ON d.`Mã dịch vụ` = s.`Dịch vụ_Mã dịch vụ` WHERE s.`Phiên sử dụng máy_ID Phiên` = ?");
        $st->bind_param('i', $ct['phien']);
        $st->execute();
        $dv_ds = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

dau_trang('Hóa đơn', 'hoa_don');
?>
<div class="body">
<div class="zones no-print">
  <form class="loc" method="get">
    <label>Từ ngày<input type="date" name="tu" value="<?= e($tu) ?>"></label>
    <label>Đến ngày<input type="date" name="den" value="<?= e($den) ?>"></label>
    <button class="btn b1">Lọc</button>
  </form>
  <p class="muted" style="margin:0"><?= count($ds) ?> hóa đơn · Đã thu: <b style="color:#5eead4"><?= tien($tong_thu) ?></b></p>
  <div class="bang">
  <table>
    <tr><th>Mã</th><th>Ngày xuất</th><th>Máy</th><th>Khách</th><th class="r">Tổng</th><th>Thanh toán</th><th></th></tr>
    <?php foreach ($ds as $r): ?>
    <tr class="<?= $sel === (int)$r['id'] ? 'sel' : '' ?>">
      <td>#<?= (int)$r['id'] ?></td>
      <td><?= e(substr($r['ngay'], 5, 11)) ?></td>
      <td><?= e($r['may']) ?></td>
      <td><?= e($r['tk'] ?: 'Vãng lai') ?></td>
      <td class="r"><?= tien($r['tong']) ?></td>
      <td><span class="<?= $r['tt'] === 'Đã thanh toán' ? 'tt-ok' : 'tt-no' ?>"><?= e($r['tt']) ?></span><br><small class="muted"><?= e($r['pt']) ?></small></td>
      <td class="r"><a class="btn b1 nho" href="hoa_don.php?id=<?= (int)$r['id'] ?>&tu=<?= e($tu) ?>&den=<?= e($den) ?>">Xem</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$ds): ?><tr><td colspan="7" class="muted">Không có hóa đơn trong khoảng ngày này.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<?php if ($ct): ?>
<aside class="panel">
  <h2>Hóa đơn #<?= (int)$ct['id'] ?></h2>
  <div class="row"><span>Ngày xuất</span><b><?= e(substr($ct['ngay'], 0, 16)) ?></b></div>
  <div class="row"><span>Máy</span><b><?= e($ct['may']) ?></b></div>
  <div class="row"><span>Khách</span><b><?= e($ct['tk'] ?: 'Khách vãng lai') ?></b></div>
  <div class="row"><span>Thu ngân</span><b><?= e($ct['nv']) ?></b></div>
  <div class="hr"></div>
  <div class="row"><span>Giờ chơi</span><b><?= e(substr($ct['bd'], 11, 5)) ?> – <?= e(substr((string)$ct['kt'], 11, 5)) ?> (<?= gio_phut($ct['tl']) ?>)</b></div>
  <div class="row"><span>Tiền máy (<?= tien($ct['gia']) ?>/giờ)</span><b><?= tien($ct['tm']) ?></b></div>
  <?php foreach ($dv_ds as $d): ?>
  <div class="row"><span><?= e($d['ten']) ?> × <?= (int)$d['sl'] ?></span><b><?= tien($d['tt']) ?></b></div>
  <?php endforeach; ?>
  <?php $giam = max(0, (float)$ct['tm'] + (float)$ct['dv'] - (float)$ct['tong']); if ($giam > 0): ?>
  <div class="row"><span>Giảm giá thành viên</span><b>-<?= tien($giam) ?></b></div>
  <?php endif; ?>
  <div class="hr"></div>
  <div class="tot"><span>Tổng cộng</span><b><?= tien($ct['tong']) ?></b></div>
  <div class="row"><span>Trạng thái</span><b class="<?= $ct['tt'] === 'Đã thanh toán' ? 'tt-ok' : 'tt-no' ?>"><?= e($ct['tt']) ?> · <?= e($ct['pt']) ?></b></div>

  <?php if ($ct['tt'] === 'Chưa thanh toán'): ?>
  <form method="post" class="no-print">
    <input type="hidden" name="act" value="thanh_toan">
    <input type="hidden" name="id" value="<?= (int)$ct['id'] ?>">
    <label>Phương thức
      <select name="pt"><option>Tiền mặt</option><option>QR</option><option>Thẻ ngân hàng</option></select>
    </label>
    <button class="btn b2" style="margin-top:12px">Ghi nhận đã thanh toán</button>
  </form>
  <?php endif; ?>
  <button class="btn b1 no-print" onclick="window.print()">In hóa đơn</button>
</aside>
<?php endif; ?>
</div>
<?php cuoi_trang(); ?>