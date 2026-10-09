<?php
require 'config.php';
require 'giao_dien.php';
yeu_cau_quyen('so_do_may');
$nv = $_SESSION['nv'];

/* ---------- Xử lý thao tác ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $tid = (int)($_POST['tk'] ?? 0);
    try {
        if ($act === 'them') {
            $ten  = trim($_POST['ten'] ?? '');
            $sdt  = trim($_POST['sdt'] ?? '');
            $user = trim($_POST['user'] ?? '');
            $mk   = $_POST['mk'] ?? '';
            if ($ten === '' || $sdt === '' || $user === '' || strlen($mk) < 4) throw new Exception('Vui lòng nhập đủ thông tin (mật khẩu ít nhất 4 ký tự).');
            if (!preg_match('/^[0-9]{9,15}$/', $sdt)) throw new Exception('Số điện thoại chỉ gồm 9 đến 15 chữ số.');

            $conn->begin_transaction();
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM `khách hàng` WHERE `SĐT` = ?");
            $st->bind_param('s', $sdt);
            $st->execute();
            if ((int)$st->get_result()->fetch_assoc()['c'] > 0) throw new Exception('Số điện thoại này đã có khách hàng.');
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM `tài khoản` WHERE `Tên đăng nhập` = ?");
            $st->bind_param('s', $user);
            $st->execute();
            if ((int)$st->get_result()->fetch_assoc()['c'] > 0) throw new Exception('Tên đăng nhập đã tồn tại.');

            $hang = (int)$conn->query("SELECT `ID Hạng` AS id FROM `hạng thành viên` ORDER BY `Tổng nạp tối thiểu` ASC LIMIT 1")->fetch_assoc()['id'];
            $st = $conn->prepare("INSERT INTO `khách hàng` (`Họ và tên`, `SĐT`, `Hạng thành viên_ID Hạng`) VALUES (?, ?, ?)");
            $st->bind_param('ssi', $ten, $sdt, $hang);
            $st->execute();
            $kid = (int)$conn->insert_id;

            $hash = password_hash($mk, PASSWORD_DEFAULT);
            $st = $conn->prepare("INSERT INTO `tài khoản` (`Tên đăng nhập`, `Mật khẩu`, `Ngày đăng kí`, `Trạng thái`, `Số dư còn lại`, `Khách hàng_ID Khách hàng`) VALUES (?, ?, NOW(), 'Hoạt động', 0, ?)");
            $st->bind_param('ssi', $user, $hash, $kid);
            $st->execute();
            $tid = (int)$conn->insert_id;
            $conn->commit();
            bao('ok', 'Đã thêm khách hàng và tạo tài khoản ' . $user . '.');

        } elseif ($act === 'nap') {
            $sotien = (isset($_POST['nhanh']) && $_POST['nhanh'] !== '') ? (int)$_POST['nhanh'] : (int)($_POST['so_tien'] ?? 0);
            if ($sotien < 1000 || $sotien > 10000000) throw new Exception('Số tiền nạp phải từ 1.000đ đến 10.000.000đ.');

            $conn->begin_transaction();
            $st = $conn->prepare("SELECT `Số dư còn lại` AS so_du, `Trạng thái` AS tt, `Khách hàng_ID Khách hàng` AS kid FROM `tài khoản` WHERE `ID Tài khoản` = ? FOR UPDATE");
            $st->bind_param('i', $tid);
            $st->execute();
            $t = $st->get_result()->fetch_assoc();
            if (!$t) throw new Exception('Không tìm thấy tài khoản.');
            if ($t['tt'] !== 'Hoạt động') throw new Exception('Tài khoản đang bị khóa.');

            $nv_id = (int)$nv['id'];
            $ca = lay_ca($conn, $nv_id);
            $tien = (float)$sotien;
            $st = $conn->prepare("INSERT INTO `giao dịch nạp tiền` (`Thời gian nạp`, `Số tiền nạp`, `Ca làm_ID ca`, `Tài khoản_ID Tài khoản`, `Nhân viên_Mã nhân viên`) VALUES (NOW(), ?, ?, ?, ?)");
            $st->bind_param('diii', $tien, $ca, $tid, $nv_id);
            $st->execute();
            $gd = (int)$conn->insert_id;

            $moi = (float)$t['so_du'] + $tien;
            $st = $conn->prepare("UPDATE `tài khoản` SET `Số dư còn lại` = ? WHERE `ID Tài khoản` = ?");
            $st->bind_param('di', $moi, $tid);
            $st->execute();

            $st = $conn->prepare("INSERT INTO `lịch sử số dư` (`Loại biến động`, `Số tiền`, `Số dư sau`, `Thời gian`, `Mô tả`, `Tài khoản_ID Tài khoản`, `Giao dịch nạp tiền_Mã giao dịch`) VALUES ('Nạp', ?, ?, NOW(), 'Nạp tiền tại quầy', ?, ?)");
            $st->bind_param('ddii', $tien, $moi, $tid, $gd);
            $st->execute();

            // Tự nâng hạng theo tổng tiền đã nạp (chỉ nâng, không hạ)
            $st = $conn->prepare("SELECT COALESCE(SUM(`Số tiền nạp`), 0) AS s FROM `giao dịch nạp tiền` WHERE `Tài khoản_ID Tài khoản` = ?");
            $st->bind_param('i', $tid);
            $st->execute();
            $tong_nap = (float)$st->get_result()->fetch_assoc()['s'];
            $st = $conn->prepare("SELECT `ID Hạng` AS id, `Tên hạng` AS ten, `Tổng nạp tối thiểu` AS nguong FROM `hạng thành viên` WHERE `Tổng nạp tối thiểu` <= ? ORDER BY `Tổng nạp tối thiểu` DESC LIMIT 1");
            $st->bind_param('d', $tong_nap);
            $st->execute();
            $h = $st->get_result()->fetch_assoc();
            $st = $conn->prepare("SELECT h.`Tổng nạp tối thiểu` AS nguong FROM `khách hàng` k JOIN `hạng thành viên` h ON h.`ID Hạng` = k.`Hạng thành viên_ID Hạng` WHERE k.`ID Khách hàng` = ?");
            $st->bind_param('i', $t['kid']);
            $st->execute();
            $cur = (float)$st->get_result()->fetch_assoc()['nguong'];
            $msg = 'Đã nạp ' . tien($sotien) . '. Số dư mới: ' . tien($moi) . '.';
            if ($h && (float)$h['nguong'] > $cur) {
                $st = $conn->prepare("UPDATE `khách hàng` SET `Hạng thành viên_ID Hạng` = ? WHERE `ID Khách hàng` = ?");
                $st->bind_param('ii', $h['id'], $t['kid']);
                $st->execute();
                $msg .= ' Khách được nâng lên hạng ' . $h['ten'] . '.';
            }
            $conn->commit();
            bao('ok', $msg);
        }
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
        bao('loi', 'Lỗi: ' . $ex->getMessage());
    }
    header('Location: khach_hang.php' . ($tid ? '?tk=' . $tid : ''));
    exit;
}

/* ---------- Lấy dữ liệu hiển thị ---------- */
$q = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';
$st = $conn->prepare("SELECT k.`ID Khách hàng` AS kid, k.`Họ và tên` AS ten, k.`SĐT` AS sdt, h.`Tên hạng` AS hang,
        t.`ID Tài khoản` AS tid, t.`Tên đăng nhập` AS tk, t.`Trạng thái` AS tt, t.`Số dư còn lại` AS so_du
    FROM `khách hàng` k
    JOIN `hạng thành viên` h ON h.`ID Hạng` = k.`Hạng thành viên_ID Hạng`
    LEFT JOIN `tài khoản` t ON t.`Khách hàng_ID Khách hàng` = k.`ID Khách hàng`
    WHERE k.`Họ và tên` LIKE ? OR k.`SĐT` LIKE ? OR t.`Tên đăng nhập` LIKE ?
    ORDER BY k.`ID Khách hàng` DESC");
$st->bind_param('sss', $like, $like, $like);
$st->execute();
$ds = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$sel = (int)($_GET['tk'] ?? 0);
$chon = null;
foreach ($ds as $r) { if ((int)$r['tid'] === $sel) $chon = $r; }
$lich_su = [];
if (!$chon && $sel) {
    $st = $conn->prepare("SELECT k.`Họ và tên` AS ten, k.`SĐT` AS sdt, h.`Tên hạng` AS hang, t.`ID Tài khoản` AS tid, t.`Tên đăng nhập` AS tk, t.`Trạng thái` AS tt, t.`Số dư còn lại` AS so_du
        FROM `tài khoản` t JOIN `khách hàng` k ON k.`ID Khách hàng` = t.`Khách hàng_ID Khách hàng`
        JOIN `hạng thành viên` h ON h.`ID Hạng` = k.`Hạng thành viên_ID Hạng` WHERE t.`ID Tài khoản` = ?");
    $st->bind_param('i', $sel);
    $st->execute();
    $chon = $st->get_result()->fetch_assoc();
}
if ($chon) {
    $st = $conn->prepare("SELECT `Thời gian nạp` AS tg, `Số tiền nạp` AS st FROM `giao dịch nạp tiền` WHERE `Tài khoản_ID Tài khoản` = ? ORDER BY `Mã giao dịch` DESC LIMIT 5");
    $st->bind_param('i', $chon['tid']);
    $st->execute();
    $lich_su = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

dau_trang('Khách hàng & nạp tiền', 'khach_hang');
?>
<div class="body">
<div class="zones">
  <form class="tim" method="get">
    <input name="q" value="<?= e($q) ?>" placeholder="Tìm theo tên, SĐT hoặc tên đăng nhập" style="flex:1">
    <button class="btn b1">Tìm</button>
  </form>
  <div class="bang">
  <table>
    <tr><th>Khách hàng</th><th>SĐT</th><th>Tài khoản</th><th>Hạng</th><th class="r">Số dư</th><th></th></tr>
    <?php foreach ($ds as $r): ?>
    <tr class="<?= $chon && (int)$chon['tid'] === (int)$r['tid'] ? 'sel' : '' ?>">
      <td><?= e($r['ten']) ?></td>
      <td><?= e($r['sdt']) ?></td>
      <td><?= e($r['tk'] ?: '—') ?></td>
      <td><?= e($r['hang']) ?></td>
      <td class="r"><?= $r['tid'] ? tien($r['so_du']) : '—' ?></td>
      <td class="r"><?php if ($r['tid']): ?><a class="btn b1 nho" href="khach_hang.php?tk=<?= (int)$r['tid'] ?>">Nạp tiền</a><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$ds): ?><tr><td colspan="6" class="muted">Không tìm thấy khách hàng.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="cot">
  <?php if ($chon): ?>
  <aside class="panel">
    <h2>Nạp tiền</h2>
    <div class="row"><span>Khách hàng</span><b><?= e($chon['ten']) ?></b></div>
    <div class="row"><span>Tài khoản</span><b><?= e($chon['tk']) ?></b></div>
    <div class="row"><span>Hạng</span><b><?= e($chon['hang']) ?></b></div>
    <div class="tot"><span>Số dư</span><b><?= tien($chon['so_du']) ?></b></div>
    <form method="post">
      <input type="hidden" name="act" value="nap">
      <input type="hidden" name="tk" value="<?= (int)$chon['tid'] ?>">
      <label>Số tiền nạp (đ)
        <input type="number" name="so_tien" min="1000" step="1000" placeholder="Ví dụ 50000">
      </label>
      <button class="btn b2" style="margin-top:12px">Nạp tiền</button>
      <p class="muted" style="margin:12px 0 6px">Nạp nhanh:</p>
      <div class="nhanh">
        <button class="btn b1 nho" name="nhanh" value="20000">+20.000</button>
        <button class="btn b1 nho" name="nhanh" value="50000">+50.000</button>
        <button class="btn b1 nho" name="nhanh" value="100000">+100.000</button>
        <button class="btn b1 nho" name="nhanh" value="200000">+200.000</button>
      </div>
    </form>
    <?php if ($lich_su): ?>
    <div class="hr"></div>
    <p class="muted" style="margin:0">Lần nạp gần đây</p>
    <?php foreach ($lich_su as $l): ?>
    <div class="row"><span><?= e(substr($l['tg'], 5, 11)) ?></span><b><?= tien($l['st']) ?></b></div>
    <?php endforeach; ?>
    <?php endif; ?>
  </aside>
  <?php endif; ?>

  <aside class="panel">
    <h2>Thêm khách hàng</h2>
    <form method="post">
      <input type="hidden" name="act" value="them">
      <label>Họ và tên<input name="ten" required></label>
      <label style="margin-top:10px">Số điện thoại<input name="sdt" required inputmode="numeric"></label>
      <label style="margin-top:10px">Tên đăng nhập<input name="user" required></label>
      <label style="margin-top:10px">Mật khẩu<input name="mk" type="password" required></label>
      <button class="btn b2" style="margin-top:12px">Thêm &amp; tạo tài khoản</button>
    </form>
  </aside>
</div>
</div>
<?php cuoi_trang(); ?>