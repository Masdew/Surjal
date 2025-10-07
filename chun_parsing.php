<?php
session_start();
require 'vendor/autoload.php';
use Spatie\PdfToText\Pdf;

$db = new mysqli("localhost", "root", "", "gabungan");
if ($db->connect_error) die("DB Error: " . $db->connect_error);

$pdfBin = 'C:/poppler-25.07.0/Library/bin/pdftotext.exe';

// ===================== Helper Functions =====================
function normalizeNum($val) {
    $val = trim(str_replace(',', '.', $val));
    return is_numeric($val) ? number_format((float)$val, 2, '.', '') : $val;
}

function mapItemNo($grade, $size) {
    $grade = strtoupper(trim(str_replace('*', '', $grade)));
    $size = (float)$size;

    $map = [
        'SWCH18A' => [
            2.42 => '80010195',
            2.51 => '80010198',
            2.70 => '80010550',
            3.49 => '80410416',
            4.01 => '80010095'
        ],
    ];
    return $map[$grade][$size] ?? null;
}
try {

// =======================================================
// =============== PARSE PACKING LIST (PL) ===============
// =======================================================
if (isset($_POST['upload_pl']) && isset($_FILES['plFile'])) {
    if ($_FILES['plFile']['error'] === UPLOAD_ERR_OK) {
        $pdfPath = $_FILES['plFile']['tmp_name'];
        $pdf = new Pdf($pdfBin);
        $text = $pdf->setPdf($pdfPath)->addOptions(['-layout'])->text();
        file_put_contents('debug_pl.txt', $text);

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $details = [];
        $currentGrade = '';
        $currentSize = '';
        $blockIndex = 0;

        // ---------------- Helper Parsing ----------------
        function parse_tokens_for_detail(array $tokens) {
            $clean = [];
            foreach ($tokens as $t) {
                $t = trim($t);
                if ($t === '') continue;
                $clean[] = $t;
            }
            if (count($clean) < 3) return false;

            $coilIndex = null;
            foreach ($clean as $i => $tok) {
                if (preg_match('/^\d{1,4}[A-Za-z]?$/', $tok)) {
                    $coilIndex = $i;
                    break;
                }
            }
            if ($coilIndex === null) return false;

            $nw = null;
            for ($i = $coilIndex + 1; $i < count($clean); $i++) {
                $cand = str_replace(',', '.', $clean[$i]);
                if (preg_match('/^[\d]+(?:\.[\d]+)?$/', $cand)) {
                    $nw = number_format((float)$cand, 2, '.', '');
                    break;
                }
            }

            $heatToken = null;
            for ($i = 0; $i < $coilIndex; $i++) {
                if (preg_match('/^R[\dA-Z\*]+$/i', $clean[$i])) {
                    $heatToken = $clean[$i];
                    break;
                }
            }

            $joToken = null;
            for ($i = $coilIndex - 1; $i >= 0; $i--) {
                $t = $clean[$i];
                if ($t === $heatToken) continue;
                if (strtoupper($t) === 'D,A,P,S' || strtolower($t) === 'diam.') continue;
                $joToken = $t;
                break;
            }

            if ($joToken === null || $heatToken === null) return false;

            return [
                'jo' => strtoupper($joToken),
                'heat' => strtoupper(str_replace('*', '', $heatToken)),
                'coilNo' => $clean[$coilIndex],
                'nw' => $nw
            ];
        }
        // ---------------- Loop Parsing per Line ----------------
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line));
            if ($line === '' || preg_match('/^(Dibuat|Putih:|Item No\.|PT\.|Factory:|Packing List|Page:|Grand Total|Sub Total)/i', $line))
                continue;

            // Baris baru dengan grade dan ukuran
            if (preg_match('/^(SWCH[0-9A-Z\*]+).*?([\d.]+)\s*m\/m(.*)$/i', $line, $m)) {
                $currentGrade = strtoupper(str_replace('*', '', trim($m[1])));
                $currentSize  = normalizeNum($m[2]);
                $blockIndex++;

                $tail = trim($m[3]);
                if ($tail !== '') {
                    $tokens = preg_split('/\s+/', $tail);
                    $parsed = parse_tokens_for_detail($tokens);
                    if ($parsed !== false) {
                        $item_no = mapItemNo($currentGrade, $currentSize);
                        $details[] = [
                            'block'   => $blockIndex,
                            'grade'   => preg_replace('/\s+/', '', $currentGrade),
                            'size'    => $currentSize,
                            'item_no' => $item_no,
                            'heat_no' => $parsed['jo'], 
                            'coil'    => $parsed['coilNo'] . '-' . $parsed['heat'], 
                            'nw'      => $parsed['nw'] !== null ? (float)$parsed['nw'] : null
                        ];
                    }
                }
                continue;
            }

            // Baris lanjutan
            $tokens = preg_split('/\s+/', $line);
            $parsed = parse_tokens_for_detail($tokens);
            if ($parsed !== false && $currentGrade && $currentSize) {
                $item_no = mapItemNo($currentGrade, $currentSize);
                $details[] = [
                    'block'   => $blockIndex,
                    'grade'   => preg_replace('/\s+/', '', $currentGrade),
                    'size'    => $currentSize,
                    'item_no' => $item_no,
                    'heat_no' => $parsed['jo'],
                    'coil'    => $parsed['coilNo'] . '-' . $parsed['heat'],
                    'nw'      => $parsed['nw'] !== null ? (float)$parsed['nw'] : null
                ];
            }

            // Reset jika ketemu Total
            if (preg_match('/^Total:/i', $line)) {
                $currentGrade = '';
                $currentSize = '';
                continue;
            }
        }

        file_put_contents('debug_parsed_details.txt', print_r($details, true));

        // Simpan hasil ke session
        $_SESSION['parsed'] = [
            'type' => 'packing',
            'header' => [
                'no_surat' => (preg_match('/U\d{6,8}/i', $text, $m) ? strtoupper($m[0]) : ''),
                'tanggal'  => (preg_match('/Date:\s*([A-Za-z]+\s*\d{1,2},\s*\d{4})/i', $text, $m)
                    ? date('Y-m-d', strtotime($m[1])) : null)
            ],
            'details' => $details
        ];

        $_SESSION['msg'] = "✅ Parsing PL selesai — ditemukan " . count($details) . " baris (urut sesuai PDF).";
        header("Location: chun_index.php");
        exit;
    }
}
// =======================================================
// =============== INSERT PACKING LIST ===================
// =======================================================
if (isset($_POST['insert_pl']) || isset($_POST['confirm_upload'])) {
    if (!isset($_SESSION['parsed']) || $_SESSION['parsed']['type'] !== 'packing') {
        $_SESSION['msg'] = "❌ Tidak ada data PL di preview.";
        header("Location: chun_index.php");
        exit;
    }

    $data = $_SESSION['parsed'];
    $hdr = $data['header'];
    $details = $data['details'];
    $no_surat = $hdr['no_surat'] ?? '';
    $tanggal = $hdr['tanggal'] ?? null;
    $username = $_SESSION['username'] ?? 'system';

    // --- Cek jika No Surat sudah ada
    $stmt = $db->prepare("SELECT id FROM chunpao_packing_header WHERE no_surat = ?");
    $stmt->bind_param("s", $no_surat);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $_SESSION['msg'] = "❌ No Surat '{$no_surat}' sudah ada.";
        header("Location: chun_index.php");
        exit;
    }
    $stmt->close();

    // --- Insert Header
    $stmt = $db->prepare("INSERT INTO chunpao_packing_header (no_surat, tanggal, username) VALUES (?,?,?)");
    $stmt->bind_param("sss", $no_surat, $tanggal, $username);
    $stmt->execute();
    $header_id = $stmt->insert_id;
    $stmt->close();

    // --- Insert Detail
    $stmt = $db->prepare("
        INSERT INTO chunpao_packing_detail (header_id, grade, size, item_no, heat_no, coil, nw)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($details as $d) {
        $item_no_val = $d['item_no'] ?? '';
        $nw_val = isset($d['nw']) ? (float)$d['nw'] : 0.0;
        $stmt->bind_param(
            "isssssd",
            $header_id,
            $d['grade'],
            $d['size'],
            $item_no_val,
            $d['heat_no'],
            $d['coil'],
            $nw_val
        );
        $stmt->execute();
    }
    $stmt->close();

    unset($_SESSION['parsed']);
    $_SESSION['msg'] = "✅ PL berhasil disimpan ({$no_surat}, " . count($details) . " baris).";
    header("Location: chun_index.php");
    exit;
}
// =======================================================
// =============== PARSE & UPDATE SURAT JALAN ============
// =======================================================
if (isset($_POST['upload_sj']) && isset($_FILES['sjFile'])) {
    if ($_FILES['sjFile']['error'] === UPLOAD_ERR_OK) {
        $pdfPath = $_FILES['sjFile']['tmp_name'];
        $pdf = new Pdf($pdfBin);
        $text = $pdf->setPdf($pdfPath)->addOptions(['-layout'])->text();
        file_put_contents('debug_sj.txt', $text);

        // --- Ambil nomor surat jalan
        $no_surat = (preg_match('/U\d{6,8}/i', $text, $m)) ? strtoupper($m[0]) : '';

        // --- Ambil semua order no (dengan posisi)
        preg_match_all('/Order\s+No\.\s*[:\-]?\s*(.+)/i', $text, $orders, PREG_OFFSET_CAPTURE);
        $order_nos = [];
        foreach ($orders[1] ?? [] as $o) {
            $order_nos[] = ['text' => trim($o[0]), 'pos' => $o[1]];
        }

        // --- Ambil tanggal
        $tanggal = (preg_match('/Date:\s*([A-Za-z]+\s*\d{1,2},\s*\d{4})/i', $text, $m))
            ? date('Y-m-d', strtotime($m[1])) : null;

        // --- Update header utama (isi order pertama)
        $first_order = !empty($order_nos) ? $order_nos[0]['text'] : '';
        $stmt = $db->prepare("UPDATE chunpao_packing_header SET order_no=?, tanggal=? WHERE no_surat=?");
        $stmt->bind_param("sss", $first_order, $tanggal, $no_surat);
        $stmt->execute();
        $stmt->close();

        // --- Ambil blok item per ukuran
        $pattern = '/(SWCH[0-9A-Z\*]+)\s+Steel Wire\s+diam\.?\s*([\d.]+)\s*m\/m[\s\S]*?(?:(\d+\s*(?:COIL|CARR(?:IER)?)[^\r\n]*))/i';


        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        file_put_contents('debug_sj_matches.txt', print_r($matches, true));

        // --- Mapping ukuran ke Order No (berdasarkan posisi)
        $orderMapSizes = [];
        foreach ($order_nos as $on) $orderMapSizes[$on['text']] = [];

        foreach ($matches as $blk) {
            $blkPos = $blk[0][1];
            $sizeVal = normalizeNum($blk[2][0]);

            $matchedOrder = null;
            foreach ($order_nos as $idx => $on) {
                $start = $on['pos'];
                $end = $order_nos[$idx + 1]['pos'] ?? PHP_INT_MAX;
                if ($blkPos >= $start && $blkPos < $end) {
                    $matchedOrder = $on['text'];
                    break;
                }
            }
            if ($matchedOrder === null && !empty($order_nos))
                $matchedOrder = $order_nos[0]['text'];

            if (!in_array($sizeVal, $orderMapSizes[$matchedOrder], true))
                $orderMapSizes[$matchedOrder][] = $sizeVal;
        }

        file_put_contents('debug_sj_map.txt', print_r($orderMapSizes, true));
        // --- Ambil header utama
        $hstmt = $db->prepare("SELECT id, username FROM chunpao_packing_header WHERE no_surat = ? ORDER BY id ASC LIMIT 1");
        $hstmt->bind_param("s", $no_surat);
        $hstmt->execute();
        $hres = $hstmt->get_result();
        $main_header = ($hres && $hres->num_rows) ? $hres->fetch_assoc() : null;
        $hstmt->close();

        if ($main_header) {
            $main_header_id = (int)$main_header['id'];
            $username = $main_header['username'] ?? 'system';

            // --- Tambahkan kolom parent_id jika belum ada
            $db->query("ALTER TABLE chunpao_packing_header ADD COLUMN IF NOT EXISTS parent_id INT NULL");

            // --- Duplikasi header jika ada lebih dari 1 order
            if (count($order_nos) > 1) {
                $rowRes = $db->query("SELECT no_surat, tanggal, username FROM chunpao_packing_header WHERE id = {$main_header_id}");
                $row = $rowRes->fetch_assoc();

                $dupStmt = $db->prepare("
                    INSERT INTO chunpao_packing_header (no_surat, tanggal, order_no, username, parent_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($order_nos as $idx => $on) {
                    if ($idx == 0) continue; // skip header utama
                    $ono = $on['text'];
                    $dupStmt->bind_param("ssssi", $row['no_surat'], $row['tanggal'], $ono, $row['username'], $main_header_id);
                    $dupStmt->execute();
                }
                $dupStmt->close();
            }

            // --- Mapping order_no => header_id
            $orderHeaderIds = [];
            if (!empty($order_nos)) $orderHeaderIds[$order_nos[0]['text']] = $main_header_id;
            if (count($order_nos) > 1) {
                $q = $db->prepare("SELECT id, order_no FROM chunpao_packing_header WHERE parent_id = ?");
                $q->bind_param("i", $main_header_id);
                $q->execute();
                $qr = $q->get_result();
                while ($r = $qr->fetch_assoc()) {
                    $orderHeaderIds[$r['order_no']] = (int)$r['id'];
                }
                $q->close();
            }

            // --- DEBUG: simpan peta order -> header id
            file_put_contents('debug_orderHeaderIds.txt', print_r($orderHeaderIds, true));

            // ==================== Update Detail ke Header Sesuai Order ====================
            foreach ($orderMapSizes as $ono => $sizes) {
                if (!isset($orderHeaderIds[$ono])) continue;
                $targetHeaderId = (int)$orderHeaderIds[$ono];

                foreach ($sizes as $sz) {
                    $szLike = number_format((float)$sz, 2, '.', '');
                    $u = $db->prepare("
                        UPDATE chunpao_packing_detail
                        SET header_id = ?
                        WHERE header_id IN (?, ?)
                          AND ABS(size - ?) < 0.01
                    ");
                    $u->bind_param("iiid", $targetHeaderId, $main_header_id, $targetHeaderId, $szLike);
                    $u->execute();
                    $u->close();
                }
            }
            // ==================== Update Deskripsi & Remark ====================
            $updated = 0;
            preg_match_all($pattern, $text, $matches2, PREG_SET_ORDER);
            foreach ($matches2 as $m) {
                $grade = strtoupper(trim(str_replace('*', '', $m[1])));
                $size = normalizeNum($m[2]);
                $proc = trim($m[3] ?? '');
                $pack_remark = trim($m[3] ?? '');
                $desc = "Steel Wire diam. {$m[2]} m/m";
                $remark = $pack_remark; // hanya COIL/CARRIER


                // tentukan header_id sesuai ukuran (size)
                $targetHeaderIdForSize = null;
                foreach ($orderMapSizes as $ono => $sizes) {
                    if (in_array($size, $sizes, true)) {
                        $targetHeaderIdForSize = $orderHeaderIds[$ono] ?? null;
                        break;
                    }
                }
                if ($targetHeaderIdForSize === null) $targetHeaderIdForSize = $main_header_id ?? null;
                if (!$targetHeaderIdForSize) continue;

                // update deskripsi dan remark
                $ustmt = $db->prepare("
                    UPDATE chunpao_packing_detail
                    SET description = ?, pack_remark = ?
                    WHERE header_id = ? AND grade LIKE ? AND size LIKE CONCAT(?, '%')
                ");
                $grade_like = preg_replace('/\s+/', '', $grade);
                $ustmt->bind_param("ssiss", $desc, $remark, $targetHeaderIdForSize, $grade_like, $size);
                $ustmt->execute();
                $updated += $ustmt->affected_rows;
                $ustmt->close();
            }
        }

        $_SESSION['msg'] = "✅ SJ berhasil di-update ({$updated} baris, No Surat {$no_surat}).";
        header("Location: chun_index.php");
        exit;
    }
}
} catch (Exception $e) {
    $_SESSION['msg'] = "❌ Error: " . $e->getMessage();
    header("Location: chun_index.php");
    exit;
}

?>
