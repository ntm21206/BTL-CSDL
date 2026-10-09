<?php
require 'config.php';
require 'giao_dien.php';
yeu_cau_quyen('bao_cao');
$nv = $_SESSION['nv'];
$day_du = ($nv['vaitro'] === 'Quản lý');

function ngan($x) {
    $x = (float)$x;
    if ($x >= 1000000) return rtrim(rtrim(number_format($x / 1000000, 2, ',', '.'), '0'), ',') . 'tr';
    if ($x >= 1000) return round($x / 1000) . 'k';
    return (string)round($x);
}
function truy_van($conn, $sql, $a, $b) {
    $st = $conn->prepare($sql);
    $st->bind_param('ss', $a, $b);
    $st->execute();
    return $st->get_result();
}

/* ---------- Kết thúc ca ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'ket_ca') {
    try {
        $cuoi = (float)($_POST['cuoi'] ?? -1);
        if ($cuoi < 0) throw new Exception('Vui lòng nhập số tiền cuối ca.');
        $nv_id = (int)$nv['id'];
        $st = $conn->prepare("UPDATE `Ca làm` SET `Giờ ra` = NOW(), `Tiền cuối ca` = ? WHERE `Nhân viên_Mã nhân viên` = ? AND `Giờ ra` IS NULL");
        $st->bind_param('di', $cuoi, $nv_id);
        $st->execute();
        if ($st->affected_rows === 0) throw new Exception('Bạn không có ca nào đang mở.');
        bao('ok', 'Đã kết thúc ca làm việc.');
    } catch (Throwable $ex) {
        bao('loi', 'Lỗi: ' . $ex->getMessage());
    }
    header('Location: bao_cao.php');
    exit;
}

/* ---------- Ca đang mở của người đăng nhập ---------- */
$st = $conn->prepare("SELECT `ID ca` AS id, `Giờ vào` AS vao, `Tiền đầu ca` AS dau FROM `Ca làm` WHERE `Nhân viên_Mã nhân viên` = ? AND `Giờ ra` IS NULL ORDER BY `ID ca` DESC LIMIT 1");
$st->bind_param('i', $nv['id']);
$st->execute();
$ca_mo = $st->get_result()->fetch_assoc();
$ket_ca = 0;
if ($ca_mo) {
    $st = $conn->prepare("SELECT COALESCE(SUM(`Tổng hóa đơn`), 0) AS s FROM `Hóa đơn` WHERE `Ca làm_ID ca` = ? AND `Phương thức thanh toán` = 'Tiền mặt' AND `Trạng thái` = 'Đã thanh toán'");
    $st->bind_param('i', $ca_mo['id']);
    $st->execute();
    $tm = (float)$st->get_result()->fetch_assoc()['s'];
    $st = $conn->prepare("SELECT COALESCE(SUM(`Số tiền nạp`), 0) AS s FROM `Giao dịch nạp tiền` WHERE `Ca làm_ID ca` = ?");
    $st->bind_param('i', $ca_mo['id']);
    $st->execute();
    $nap_ca = (float)$st->get_result()->fetch_assoc()['s'];
    $ket_ca = (float)$ca_mo['dau'] + $tm + $nap_ca;
}

/* ---------- Số liệu toàn tiệm (chỉ Quản lý) ---------- */
if ($day_du) {
    $hom = $conn->query("SELECT CURDATE() AS d, CURDATE() - INTERVAL 6 DAY AS d6")->fetch_assoc();
    $tu  = $_GET['tu']  ?? $hom['d6'];
    $den = $_GET['den'] ?? $hom['d'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tu))  $tu  = $hom['d6'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $den)) $den = $hom['d'];
    $d1 = new DateTime($tu); $d2 = new DateTime($den);
    if ($d2 < $d1) { $t = $d1; $d1 = $d2; $d2 = $t; }
    if ($d1->diff($d2)->days > 30) $d1 = (clone $d2)->modify('-30 days');
    $tu = $d1->format('Y-m-d'); $den = $d2->format('Y-m-d');

    $theo_ngay = [];
    $r = truy_van($conn, "SELECT DATE(`Ngày xuất`) AS d, SUM(`Tổng hóa đơn`) AS s, COUNT(*) AS c FROM `Hóa đơn` WHERE `Trạng thái` = 'Đã thanh toán' AND DATE(`Ngày xuất`) BETWEEN ? AND ? GROUP BY DATE(`Ngày xuất`)", $tu, $den);
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $x) $theo_ngay[$x['d']] = $x;
    $ngay = []; $max = 0; $tong_dt = 0; $so_hd = 0;
    for ($d = clone $d1; $d <= $d2; $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $s = isset($theo_ngay[$k]) ? (float)$theo_ngay[$k]['s'] : 0;
        $ngay[] = ['k' => $d->format('d/m'), 's' => $s];
        $max = max($max, $s); $tong_dt += $s;
        $so_hd += isset($theo_ngay[$k]) ? (int)$theo_ngay[$k]['c'] : 0;
    }
    $nap = (float)truy_van($conn, "SELECT COALESCE(SUM(`Số tiền nạp`), 0) AS s FROM `Giao dịch nạp tiền` WHERE DATE(`Thời gian nạp`) BETWEEN ? AND ?", $tu, $den)->fetch_assoc()['s'];
    $ph = truy_van($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(`Thời lượng`), 0) AS p FROM `Phiên sử dụng máy` WHERE `Thời gian kết thúc` IS NOT NULL AND DATE(`Thời gian bắt đầu`) BETWEEN ? AND ?", $tu, $den)->fetch_assoc();
    $theo_pt = truy_van($conn, "SELECT `Phương thức thanh toán` AS pt, SUM(`Tổng hóa đơn`) AS s, COUNT(*) AS c FROM `Hóa đơn` WHERE `Trạng thái` = 'Đã thanh toán' AND DATE(`Ngày xuất`) BETWEEN ? AND ? GROUP BY `Phương thức thanh toán` ORDER BY s DESC", $tu, $den)->fetch_all(MYSQLI_ASSOC);
    $top_may = truy_van($conn, "SELECT m.`Tên máy` AS ten, COUNT(p.`ID Phiên`) AS n, SUM(p.`Thời lượng`) AS phut, SUM(p.`Tiền sử dụng`) AS tien
        FROM `phiên sử dụng máy` p JOIN `Máy tính` m ON m.`ID Máy` = p.`Máy tính_ID Máy`
        WHERE p.`Thời gian kết thúc` IS NOT NULL AND DATE(p.`Thời gian bắt đầu`) BETWEEN ? AND ?
        GROUP BY m.`ID Máy`, m.`Tên máy` ORDER BY n DESC, phut DESC LIMIT 5", $tu, $den)->fetch_all(MYSQLI_ASSOC);
    $top_dv = truy_van($conn, "SELECT d.`Tên dịch vụ` AS ten, SUM(s.`Số lượng`) AS sl, SUM(s.`Thành tiền`) AS tien
        FROM `phiên sử dụng dịch vụ` s JOIN `Dịch vụ` d ON d.`Mã dịch vụ` = s.`Dịch vụ_Mã dịch vụ`
        WHERE DATE(s.`Thời gian`) BETWEEN ? AND ?
        GROUP BY d.`Mã dịch vụ`, d.`Tên dịch vụ` ORDER BY sl DESC LIMIT 5", $tu, $den)->fetch_all(MYSQLI_ASSOC);
    $cas = truy_van($conn, "SELECT c.`ID ca` AS id, c.`Giờ vào` AS vao, c.`Giờ ra` AS ra, c.`Tiền đầu ca` AS dau, c.`Tiền cuối ca` AS cuoi, n.`Họ và tên` AS nv,
            (SELECT COALESCE(SUM(h.`Tổng hóa đơn`), 0) FROM `Hóa đơn` h WHERE h.`Ca làm_ID ca` = c.`ID ca` AND h.`Trạng thái` = 'Đã thanh toán') AS dt,
            (SELECT COALESCE(SUM(g.`Số tiền nạp`), 0) FROM `Giao dịch nạp tiền` g WHERE g.`Ca làm_ID ca` = c.`ID ca`) AS nap
        FROM `ca làm` c JOIN `Nhân viên` n ON n.`Mã nhân viên` = c.`Nhân viên_Mã nhân viên`
        WHERE DATE(c.`Giờ vào`) BETWEEN ? AND ? ORDER BY c.`ID ca` DESC", $tu, $den)->fetch_all(MYSQLI_ASSOC);
}

dau_trang('Báo cáo', 'bao_cao');
?>
<?php if ($day_du): ?>
<form class="loc" method="get">
  <label>Từ ngày<input type="date" name="tu" value="<?= e($tu) ?>"></label>
  <label>Đến ngày<input type="date" name="den" value="<?= e($den) ?>"></label>
  <button class="btn b1">Xem báo cáo</button>
  <span class="muted">Tối đa 31 ngày</span>
</form>

<div class="stats">
  <div class="stat"><small>Doanh thu (hóa đơn đã thanh toán)</small><b><?= tien($tong_dt) ?></b></div>
  <div class="stat"><small>Số hóa đơn</small><b><?= $so_hd ?></b></div>
  <div class="stat"><small>Tiền nạp vào tài khoản</small><b><?= tien($nap) ?></b></div>
  <div class="stat"><small>Phiên đã chơi · tổng giờ</small><b><?= (int)$ph['c'] ?> · <?= gio_phut($ph['p']) ?></b></div>
</div>

<section>
  <div class="zt"><span>Doanh thu theo ngày</span><em><?= e($d1->format('d/m/Y')) ?> – <?= e($d2->format('d/m/Y')) ?></em></div>
  <div class="bieu">
    <?php foreach ($ngay as $n): $h = $max > 0 ? max(2, round($n['s'] / $max * 100)) : 0; ?>
    <div class="cb">
      <em><?= $n['s'] > 0 ? ngan($n['s']) : '' ?></em>
      <div class="vung"><div class="th" style="height:<?= $h ?>%"></div></div>
      <small><?= e($n['k']) ?></small>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
<p class="muted" style="margin:0">Vai trò Nhân viên chỉ xem được ca làm việc của mình. Báo cáo doanh thu toàn tiệm dành cho Quản lý.</p>
<?php endif; ?>

<div class="luoi2">
<?php if ($day_du): ?>
  <section>
    <div class="zt"><span>Máy dùng nhiều nhất</span></div>
    <div class="bang"><table>
      <tr><th>Máy</th><th class="r">Số phiên</th><th class="r">Giờ chơi</th><th class="r">Tiền máy</th></tr>
      <?php foreach ($top_may as $x): ?>
      <tr><td><?= e($x['ten']) ?></td><td class="r"><?= (int)$x['n'] ?></td><td class="r"><?= gio_phut($x['phut']) ?></td><td class="r"><?= tien($x['tien']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$top_may): ?><tr><td colspan="4" class="muted">Chưa có dữ liệu.</td></tr><?php endif; ?>
    </table></div>
  </section>
  <section>
    <div class="zt"><span>Món bán chạy</span></div>
    <div class="bang"><table>
      <tr><th>Món</th><th class="r">Đã bán</th><th class="r">Doanh thu</th></tr>
      <?php foreach ($top_dv as $x): ?>
      <tr><td><?= e($x['ten']) ?></td><td class="r"><?= (int)$x['sl'] ?></td><td class="r"><?= tien($x['tien']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$top_dv): ?><tr><td colspan="3" class="muted">Chưa có dữ liệu.</td></tr><?php endif; ?>
    </table></div>
  </section>
  <section>
    <div class="zt"><span>Theo phương thức thanh toán</span></div>
    <div class="bang"><table>
      <tr><th>Phương thức</th><th class="r">Số hóa đơn</th><th class="r">Doanh thu</th></tr>
      <?php foreach ($theo_pt as $x): ?>
      <tr><td><?= e($x['pt']) ?></td><td class="r"><?= (int)$x['c'] ?></td><td class="r"><?= tien($x['s']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$theo_pt): ?><tr><td colspan="3" class="muted">Chưa có dữ liệu.</td></tr><?php endif; ?>
    </table></div>
  </section>
<?php endif; ?>

  <section>
    <div class="zt"><span>Ca làm việc của bạn</span></div>
    <div class="panel" style="flex:none">
    <?php if ($ca_mo): ?>
      <div class="row"><span>Vào ca lúc</span><b><?= e(substr($ca_mo['vao'], 5, 11)) ?></b></div>
      <div class="row"><span>Tiền đầu ca</span><b><?= tien($ca_mo['dau']) ?></b></div>
      <div class="row"><span>Tiền mặt dự kiến trong két</span><b><?= tien($ket_ca) ?></b></div>
      <p class="muted" style="margin:0;font-size:12px">= đầu ca + hóa đơn tiền mặt + tiền nạp tại quầy trong ca.</p>
      <form method="post" onsubmit="return confirm('Kết thúc ca làm việc?')">
        <input type="hidden" name="act" value="ket_ca">
        <label>Tiền thực tế cuối ca (đ)<input type="number" name="cuoi" min="0" step="1000" value="<?= (int)$ket_ca ?>" required></label>
        <button class="btn b2" style="margin-top:12px;width:100%">Kết thúc ca</button>
      </form>
    <?php else: ?>
      <p class="muted" style="margin:0">Bạn chưa có ca đang mở. Ca mới sẽ tự tạo khi bạn thu tiền hoặc nạp tiền lần đầu.</p>
    <?php endif; ?>
    </div>
  </section>
</div>

<?php if ($day_du): ?>
<section>
  <div class="zt"><span>Doanh thu theo ca</span></div>
  <div class="bang"><table>
    <tr><th>Ca</th><th>Nhân viên</th><th>Vào</th><th>Ra</th><th class="r">Đầu ca</th><th class="r">Hóa đơn</th><th class="r">Nạp tiền</th><th class="r">Cuối ca</th></tr>
    <?php foreach ($cas as $x): ?>
    <tr>
      <td>#<?= (int)$x['id'] ?></td><td><?= e($x['nv']) ?></td>
      <td><?= e(substr($x['vao'], 5, 11)) ?></td>
      <td><?= $x['ra'] ? e(substr($x['ra'], 5, 11)) : '<span class="tt-ok">Đang mở</span>' ?></td>
      <td class="r"><?= tien($x['dau']) ?></td><td class="r"><?= tien($x['dt']) ?></td><td class="r"><?= tien($x['nap']) ?></td>
      <td class="r"><?= $x['cuoi'] !== null ? tien($x['cuoi']) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$cas): ?><tr><td colspan="8" class="muted">Không có ca trong khoảng ngày này.</td></tr><?php endif; ?>
  </table></div>
</section>
<?php endif; ?>
<?php cuoi_trang(); ?>