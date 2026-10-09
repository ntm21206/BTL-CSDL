<?php
require 'config.php';
require 'giao_dien.php';
yeu_cau_quyen('goi_mon');

if ($_SERVER['REQUEST_METHOD'] === 'POST') 
    {
    $act = $_POST['act'] ?? '';
    $phien = (int)($_POST['phien'] ?? 0);
    try 
    {
        if ($act === 'them') 
            {
            $dv = (int)($_POST['dv'] ?? 0);
            $sl = (int)($_POST['sl'] ?? 0);
            if ($sl < 1 || $sl > 50) throw new Exception('Số lượng phải từ 1 đến 50.');
            $conn->begin_transaction();
            $st = $conn->prepare("SELECT `ID Phiên` FROM `Phiên sử dụng máy` WHERE `ID Phiên` = ? AND `Thời gian kết thúc` IS NULL FOR UPDATE");
            $st->bind_param('i', $phien);
            $st->execute();
            if (!$st->get_result()->fetch_assoc()) throw new Exception('Phiên này đã kết thúc, không thể gọi thêm món.');
            $st = $conn->prepare("SELECT `Tên dịch vụ` AS ten, `Đơn giá` AS gia, `Số lượng tồn` AS ton FROM `Dịch vụ` WHERE `Mã dịch vụ` = ? FOR UPDATE");
            $st->bind_param('i', $dv);
            $st->execute();
            $m = $st->get_result()->fetch_assoc();
            if (!$m) throw new Exception('Không tìm thấy món.');
            if ((int)$m['ton'] < $sl) throw new Exception('Không đủ hàng: ' . $m['ten'] . ' chỉ còn ' . (int)$m['ton'] . '.');
            $gia = (float)$m['gia'];
            $thanh = $gia * $sl;
            $st = $conn->prepare("INSERT INTO `Phiên sử dụng dịch vụ` (`Thời gian`, `Số lượng`, `Đơn giá`, `Thành tiền`, `Dịch vụ_Mã dịch vụ`, `Phiên sử dụng máy_ID Phiên`) VALUES (NOW(), ?, ?, ?, ?, ?)");
            $st->bind_param('iddii', $sl, $gia, $thanh, $dv, $phien);
            $st->execute();
            $st = $conn->prepare("UPDATE `Dịch vụ` SET `Số lượng tồn` = `Số lượng tồn` - ? WHERE `Mã dịch vụ` = ?");
            $st->bind_param('ii', $sl, $dv);
            $st->execute();
            $conn->commit();
            bao('ok', 'Đã thêm ' . $sl . ' × ' . $m['ten'] . ' (' . tien($thanh) . ').');

        } 
        elseif ($act === 'xoa') 
        {
            $id = (int)($_POST['id'] ?? 0);
            $conn->begin_transaction();
            $st = $conn->prepare("SELECT s.`Số lượng` AS sl, s.`Dịch vụ_Mã dịch vụ` AS dv, s.`Phiên sử dụng máy_ID Phiên` AS phien FROM `Phiên sử dụng dịch vụ` s WHERE s.`ID Phiên sử dụng dịch vụ` = ? FOR UPDATE");
            $st->bind_param('i', $id);
            $st->execute();
            $d = $st->get_result()->fetch_assoc();
            if (!$d) throw new Exception('Không tìm thấy dòng món.');
            $phien = (int)$d['phien'];
            $st = $conn->prepare("SELECT `ID Phiên` FROM `Phiên sử dụng máy` WHERE `ID Phiên` = ? AND `Thời gian kết thúc` IS NULL FOR UPDATE");
            $st->bind_param('i', $phien);
            $st->execute();
            if (!$st->get_result()->fetch_assoc()) throw new Exception('Phiên đã kết thúc, không thể hủy món.');
            $st = $conn->prepare("DELETE FROM `Phiên sử dụng dịch vụ` WHERE `ID Phiên sử dụng dịch vụ` = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $st = $conn->prepare("UPDATE `Dịch vụ` SET `Số lượng tồn` = `Số lượng tồn` + ? WHERE `Mã dịch vụ` = ?");
            $st->bind_param('ii', $d['sl'], $d['dv']);
            $st->execute();
            $conn->commit();
            bao('ok', 'Đã hủy món và hoàn lại tồn kho.');
        }
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
        bao('loi', 'Lỗi: ' . $ex->getMessage());
    }
    header('Location: goi_mon.php?phien=' . $phien);
    exit;
}

/* ---------- Lấy dữ liệu hiển thị ---------- */
$phiens = $conn->query("SELECT p.`ID Phiên` AS id, m.`Tên máy` AS may, t.`Tên đăng nhập` AS tk,
        TIMESTAMPDIFF(MINUTE, p.`Thời gian bắt đầu`, NOW()) AS phut
    FROM `phiên sử dụng máy` p
    JOIN `máy tính` m ON m.`ID Máy` = p.`Máy tính_ID Máy`
    LEFT JOIN `Tài khoản` t ON t.`ID Tài khoản` = p.`Tài khoản_ID Tài khoản`
    WHERE p.`Thời gian kết thúc` IS NULL
    ORDER BY m.`ID Máy`")->fetch_all(MYSQLI_ASSOC);

$sel = (int)($_GET['phien'] ?? 0);
$chon = null;
foreach ($phiens as $p) { if ((int)$p['id'] === $sel) $chon = $p; }
if (!$chon && $phiens) $chon = $phiens[0];

$menu = $conn->query("SELECT `Mã dịch vụ` AS id, `Tên dịch vụ` AS ten, `Loại` AS loai, `Đơn giá` AS gia, `Số lượng tồn` AS ton FROM `Dịch vụ` ORDER BY `Loại`, `Tên dịch vụ`")->fetch_all(MYSQLI_ASSOC);
$nhom = [];
foreach ($menu as $m) $nhom[$m['loai']][] = $m;

$dong = []; $tong_dv = 0;
if ($chon) {
    $st = $conn->prepare("SELECT s.`ID Phiên sử dụng dịch vụ` AS id, d.`Tên dịch vụ` AS ten, s.`Số lượng` AS sl, s.`Thành tiền` AS tt FROM `Phiên sử dụng dịch vụ` s JOIN `Dịch vụ` d ON d.`Mã dịch vụ` = s.`Dịch vụ_Mã dịch vụ` WHERE s.`Phiên sử dụng máy_ID Phiên` = ? ORDER BY s.`ID Phiên sử dụng dịch vụ`");
    $st->bind_param('i', $chon['id']);
    $st->execute();
    $dong = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($dong as $d) $tong_dv += (float)$d['tt'];
}

dau_trang('Gọi món', 'goi_mon');
?>
<?php if (!$phiens): ?>
  <p class="muted">Hiện không có máy nào đang chơi. Hãy mở phiên ở trang <a href="so_do_may.php" style="color:#5eead4">Sơ đồ máy</a> trước.</p>
<?php else: ?>
<div class="body">
<div class="zones">
  <section>
    <div class="zt"><span>Chọn máy đang chơi</span></div>
    <div class="grid">
      <?php foreach ($phiens as $p): ?>
      <a class="m on<?= (int)$chon['id'] === (int)$p['id'] ? ' sel' : '' ?>" href="goi_mon.php?phien=<?= (int)$p['id'] ?>">
        <b><?= e($p['may']) ?></b><i class="s"><?= e($p['tk'] ?: 'Khách vãng lai') ?></i><span><?= gio_phut($p['phut']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php foreach ($nhom as $loai => $ds): ?>
  <section>
    <div class="zt"><span><?= e($loai) ?></span></div>
    <div class="menu">
      <?php foreach ($ds as $m): ?>
      <form method="post" class="mon">
        <input type="hidden" name="act" value="them">
        <input type="hidden" name="phien" value="<?= (int)$chon['id'] ?>">
        <input type="hidden" name="dv" value="<?= (int)$m['id'] ?>">
        <b><?= e($m['ten']) ?></b>
        <span><?= tien($m['gia']) ?> · <?= (int)$m['ton'] > 0 ? 'Còn ' . (int)$m['ton'] : 'Hết hàng' ?></span>
        <div class="sl">
          <input type="number" name="sl" value="1" min="1" max="<?= max(1, (int)$m['ton']) ?>">
          <button class="btn b2 nho"<?= (int)$m['ton'] < 1 ? ' disabled' : '' ?>>Thêm</button>
        </div>
      </form>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>

<aside class="panel">
  <h2><?= e($chon['may']) ?></h2>
  <div class="row"><span>Khách</span><b><?= e($chon['tk'] ?: 'Khách vãng lai') ?></b></div>
  <div class="row"><span>Đã chơi</span><b><?= gio_phut($chon['phut']) ?></b></div>
  <div class="hr"></div>
  <?php if (!$dong): ?><p class="muted" style="margin:0">Chưa gọi món nào.</p><?php endif; ?>
  <?php foreach ($dong as $d): ?>
  <div class="row">
    <span><?= e($d['ten']) ?> × <?= (int)$d['sl'] ?></span>
    <b><?= tien($d['tt']) ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Hủy món này?')">
        <input type="hidden" name="act" value="xoa">
        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <input type="hidden" name="phien" value="<?= (int)$chon['id'] ?>">
        <button class="xoa" title="Hủy món">✕</button>
      </form>
    </b>
  </div>
  <?php endforeach; ?>
  <div class="hr"></div>
  <div class="tot"><span>Tiền dịch vụ</span><b><?= tien($tong_dv) ?></b></div>
  <a class="btn b1" href="so_do_may.php">Thanh toán ở Sơ đồ máy</a>
</aside>
</div>
<?php endif; ?>
<?php cuoi_trang(); ?>