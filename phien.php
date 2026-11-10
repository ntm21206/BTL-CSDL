<?php
/* Hàm dùng chung cho phiên chơi. Nạp sau config.php và giao_dien.php */

function thong_tin_phien($conn, $phien, $khoa = false) {
    $sql = "SELECT p.`ID Phiên` AS id, p.`Đơn giá` AS gia, p.`Thời gian bắt đầu` AS bd, p.`Máy tính_ID Máy` AS may_id,
            p.`Tài khoản_ID Tài khoản` AS tk, TIMESTAMPDIFF(SECOND, p.`Thời gian bắt đầu`, NOW()) AS giay,
            t.`Tên đăng nhập` AS tk_ten, t.`Số dư còn lại` AS so_du, h.`Tên hạng` AS hang, COALESCE(h.`Phần trăm giảm giá`, 0) AS giam
        FROM `phiên sử dụng máy` p
        LEFT JOIN `tài khoản` t ON t.`ID Tài khoản` = p.`Tài khoản_ID Tài khoản`
        LEFT JOIN `khách hàng` k ON k.`ID Khách hàng` = t.`Khách hàng_ID Khách hàng`
        LEFT JOIN `hạng thành viên` h ON h.`ID Hạng` = k.`Hạng thành viên_ID Hạng`
        WHERE p.`ID Phiên` = ? AND p.`Thời gian kết thúc` IS NULL" . ($khoa ? ' FOR UPDATE' : '');
    $st = $conn->prepare($sql);
    $st->bind_param('i', $phien);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    if (!$r) return null;
    $st = $conn->prepare("SELECT COALESCE(SUM(`Thành tiền`), 0) AS s FROM `phiên sử dụng dịch vụ` WHERE `Phiên sử dụng máy_ID Phiên` = ?");
    $st->bind_param('i', $phien);
    $st->execute();
    $r['dv'] = (float)$st->get_result()->fetch_assoc()['s'];
    $r['giay'] = (int)$r['giay'];
    $r['phut'] = intdiv($r['giay'], 60);
    $r['giam'] = (float)$r['giam'];
    $r['so_du'] = (float)$r['so_du'];
    $r['tien_may'] = tinh_tien_may($r['phut'], $r['gia']);
    $r['tong'] = round(($r['tien_may'] + $r['dv']) * (100 - $r['giam']) / 100);
    return $r;
}

/* Kết thúc phiên, trừ tiền, xuất hóa đơn. $nv_id và $ca_id để null nếu khách tự phục vụ. */
function ket_thuc_phien($conn, $phien, $pt, $nv_id = null, $ca_id = null) {
    if (!in_array($pt, ['Tiền mặt', 'QR', 'Thẻ ngân hàng', 'Số dư tài khoản'], true)) throw new Exception('Phương thức thanh toán không hợp lệ.');
    $conn->begin_transaction();
    try {
        $p = thong_tin_phien($conn, $phien, true);
        if (!$p) throw new Exception('Phiên này đã kết thúc hoặc không tồn tại.');
        $tk = $p['tk'] ? (int)$p['tk'] : 0;
        $phut = max(1, $p['phut']);
        $tien_may = (float)$p['tien_may'];
        $dv = (float)$p['dv'];
        $tong = (float)$p['tong'];
        $tm2 = round($tien_may * (100 - $p['giam']) / 100);

        if ($pt === 'Số dư tài khoản') {
            if (!$tk) throw new Exception('Khách vãng lai không có số dư để trừ.');
            if ($p['so_du'] < $tong) $tong = $p['so_du'];      // hết tiền: chỉ trừ phần còn lại
            $tm2 = min($tm2, $tong);
            $dv2 = $tong - $tm2;
            $moi = $p['so_du'] - $tong;
            $st = $conn->prepare("UPDATE `tài khoản` SET `Số dư còn lại` = ? WHERE `ID Tài khoản` = ?");
            $st->bind_param('di', $moi, $tk);
            $st->execute();
            $ls = $conn->prepare("INSERT INTO `lịch sử số dư` (`Loại biến động`, `Số tiền`, `Số dư sau`, `Thời gian`, `Mô tả`, `Tài khoản_ID Tài khoản`, `Phiên sử dụng máy_ID Phiên`) VALUES (?, ?, ?, NOW(), ?, ?, ?)");
            if ($tm2 > 0) {
                $loai = 'Trừ tiền chơi'; $sotien = -$tm2; $sdsau = $p['so_du'] - $tm2; $mota = 'Chơi máy ' . $phut . ' phút';
                $ls->bind_param('sddsii', $loai, $sotien, $sdsau, $mota, $tk, $phien);
                $ls->execute();
            }
            if ($dv2 > 0) {
                $loai = 'Trừ tiền dịch vụ'; $sotien = -$dv2; $sdsau = $moi; $mota = 'Dịch vụ trong phiên';
                $ls->bind_param('sddsii', $loai, $sotien, $sdsau, $mota, $tk, $phien);
                $ls->execute();
            }
        }

        $st = $conn->prepare("UPDATE `phiên sử dụng máy` SET `Thời gian kết thúc` = NOW(), `Thời lượng` = ?, `Tiền sử dụng` = ? WHERE `ID Phiên` = ?");
        $st->bind_param('idi', $phut, $tien_may, $phien);
        $st->execute();

        $st = $conn->prepare("INSERT INTO `hóa đơn` (`Tiền dịch vụ`, `Tổng hóa đơn`, `Trạng thái`, `Ngày xuất`, `Phương thức thanh toán`, `Ca làm_ID ca`, `Phiên sử dụng máy_ID Phiên`, `Nhân viên_Mã nhân viên`) VALUES (?, ?, 'Đã thanh toán', NOW(), ?, ?, ?, ?)");
        $st->bind_param('ddsiii', $dv, $tong, $pt, $ca_id, $phien, $nv_id);
        $st->execute();

        $st = $conn->prepare("UPDATE `máy tính` SET `Trạng thái` = 'Offline' WHERE `ID Máy` = ?");
        $mid = (int)$p['may_id'];
        $st->bind_param('i', $mid);
        $st->execute();
        $conn->commit();
        return ['tong' => $tong, 'phut' => $phut];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
        throw $e;
    }
}

/* Tự đóng các phiên (có tài khoản) mà tiền chơi + dịch vụ đã hết số dư */
function tu_dong_ket_thuc_het_tien($conn) {
    $ids = $conn->query("SELECT `ID Phiên` AS id FROM `phiên sử dụng máy` WHERE `Thời gian kết thúc` IS NULL AND `Tài khoản_ID Tài khoản` IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
    $n = 0;
    foreach ($ids as $x) {
        $p = thong_tin_phien($conn, (int)$x['id']);
        if ($p && $p['tong'] >= $p['so_du']) {
            try { ket_thuc_phien($conn, (int)$x['id'], 'Số dư tài khoản'); $n++; } catch (Throwable $e) {}
        }
    }
    return $n;
}