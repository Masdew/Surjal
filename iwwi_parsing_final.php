<?php
session_start();
require 'vendor/autoload.php';
use Spatie\PdfToText\Pdf;

$db = new mysqli("localhost","root","","gabungan");
if ($db->connect_error) die("DB Error: " . $db->connect_error);

$pdfBin = 'C:/poppler-25.07.0/Library/bin/pdftotext.exe';

function normalizeDoNum($do) {
    $do = strtoupper(trim($do));
    $do = preg_replace('/[^A-Z0-9\-]/', '', $do);
    return $do;
}

function normalizeSize($size) {
    return number_format((float)$size, 2, '.', '');
}

try {

    // ======================= UPLOAD & PARSE PL =======================
    if (isset($_POST['upload_pl']) && isset($_FILES['plFile'])) {
        if ($_FILES['plFile']['error'] === UPLOAD_ERR_OK) {
            $pdfPath = $_FILES['plFile']['tmp_name'];
            $pdf = new Pdf($pdfBin);
            $text = $pdf->setPdf($pdfPath)->addOptions(['-layout'])->text();

            $header = ['username' => '', 'do_num' => '', 'delv_date' => ''];
            if (preg_match('/Username\s*:\s*(.+)/i', $text, $m)) $header['username'] = trim($m[1]);
            if (preg_match('/DO\s*Number\s*:\s*([A-Z0-9\-]+)/i', $text, $m)) $header['do_num'] = normalizeDoNum($m[1]);
            if (preg_match('/(\d{1,2}-[A-Za-z]{3}-\d{2,4})/', $text, $m)) $header['delv_date'] = date('Y-m-d', strtotime($m[1]));

            $details = [];
            $lines = preg_split('/\r\n|\r|\n/', $text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^(\d+)\s+([A-Z0-9]+)\s+([\d.]+)\s+([A-Z])\s+(?:0.00\s+)?([\d\-.A-Z0-9]+)\s+([A-Z0-9]+)\s+([\d.,]+)\s+(\d+)$/', $line, $m)) {
                    $details[] = [
                        'no'      => $m[1],
                        'grade'   => $m[2],
                        'size'    => normalizeSize($m[3]),
                        'shape'   => $m[4],
                        'coil_no' => $m[5],
                        'heat_no' => $m[6],
                        'qty'     => str_replace(",", "", $m[7]),
                        'pcs'     => $m[8]
                    ];
                }
            }

            if (!empty($header['do_num']) && count($details) > 0) {
                $_SESSION['pl_data'] = ['header' => $header, 'details' => $details];
                $_SESSION['msg'] = "✅ Packing List berhasil diparse. " . count($details) . " items ditemukan.";
            } else {
                $_SESSION['msg'] = "❌ Gagal memparse Packing List. Pastikan format sesuai.";
            }
        }
    }

    // ======================= INSERT PL ke DB =======================
    if (isset($_POST['insert_pl']) && isset($_SESSION['pl_data'])) {
        $header = $_SESSION['pl_data']['header'];
        $details = $_SESSION['pl_data']['details'];

        $checkStmt = $db->prepare("SELECT id FROM iwwi_packing_header WHERE do_num = ?");
        $checkStmt->bind_param("s", $header['do_num']);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        if ($result->num_rows > 0) {
            $_SESSION['msg'] = "❌ DO Number '{$header['do_num']}' sudah ada di database.";
            $checkStmt->close();
            header("Location: index3.php");
            exit;
        }
        $checkStmt->close();

        $stmt = $db->prepare("INSERT INTO iwwi_packing_header (do_num, delv_date, username) VALUES (?,?,?)");
        $stmt->bind_param("sss", $header['do_num'], $header['delv_date'], $header['username']);
        $stmt->execute();
        $header_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO iwwi_packing_detail 
            (header_id, no, grade, size, shape, coil_no, heat_no, qty, pcs, no_po, item_no) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");

        foreach ($details as $d) {
            $null = null;
            $stmt->bind_param("iisssssdiis",
                $header_id,
                $d['no'], $d['grade'], $d['size'], $d['shape'],
                $d['coil_no'], $d['heat_no'],
                $d['qty'], $d['pcs'],
                $null, $null
            );
            $stmt->execute();
        }
        $stmt->close();

        unset($_SESSION['pl_data']);
        $_SESSION['msg'] = "✅ Packing List berhasil disimpan ke DB.";
    }

    // ======================= UPLOAD & PARSE SJ (gabungan) =======================
    if (isset($_POST['upload_sj']) && isset($_FILES['sjFile'])) {
        if ($_FILES['sjFile']['error'] === UPLOAD_ERR_OK) {
            $pdfPath = $_FILES['sjFile']['tmp_name'];
            $pdf = new Pdf($pdfBin);
            $text = $pdf->setPdf($pdfPath)->addOptions(['-layout'])->text();

            // --- Ambil semua No. PO ---
            preg_match_all('/(I\d+\/PO\/[A-Z0-9\/]+)/', $text, $matches);
            $uniquePOs = array_unique($matches[1] ?? []);
            $totalPO = count($uniquePOs);

            // --- Dapatkan DO Number ---
            $headerDo = '';
            if (preg_match('/([A-Z0-9\-]+)\s*\n*\d{1,2}\/[A-Za-z]+\/\d{4}/i', $text, $doMatch)) {
                $headerDo = strtoupper(trim($doMatch[1]));
            }

            if (empty($headerDo)) {
                $_SESSION['msg'] = "❌ Gagal menemukan DO Number di file SJ.";
                header("Location: index3.php");
                exit;
            }

            $stmt = $db->prepare("SELECT id FROM iwwi_packing_header WHERE do_num = ?");
            $stmt->bind_param("s", $headerDo);
            $stmt->execute();
            $res = $stmt->get_result();
            $headerRow = $res->fetch_assoc();
            $stmt->close();

            if (!$headerRow) {
                $_SESSION['msg'] = "❌ DO Number '{$headerDo}' belum ada di database.";
                header("Location: index3.php");
                exit;
            }
            $headerId = $headerRow['id'];

            // ==============================================
            // MODE 1 → BEBERAPA NO.PO BERBEDA
            // ==============================================
            if ($totalPO > 1) {
                $sjMap = [];
                if (preg_match_all('/\/(SWCH\d{2}[A-Z]?|SCM\d{3})\/ROUND\/0*([0-9]+(?:[.,]\d+)?)[\s\S]*?(I\d+\/PO\/[A-Z0-9\/]+)\s*\((\d+)\)/i', $text, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $grade = strtoupper(trim($m[1]));
                        $size  = str_replace(',', '.', trim($m[2]));
                        $size  = ltrim($size, '0');
                        $noPo  = trim($m[3]);
                        $itemNo= trim($m[4]);
                        $key = "{$grade}_{$size}";
                        $sjMap[$key] = ['no_po' => $noPo, 'item_no' => $itemNo];
                    }
                }

                $updated = 0;
                $res = $db->query("SELECT id, grade, size FROM iwwi_packing_detail WHERE header_id={$headerId} ORDER BY id ASC");
                while ($row = $res->fetch_assoc()) {
                    $grade = strtoupper(trim($row['grade']));
                    $size  = ltrim(str_replace(',', '.', trim($row['size'])), '0');
                    $key   = "{$grade}_{$size}";

                    if (isset($sjMap[$key])) {
                        $noPo = $sjMap[$key]['no_po'];
                        $itemNo = $sjMap[$key]['item_no'];
                    } else {
                        $noPo = NULL;
                        $itemNo = NULL;
                    }

                    $stmt = $db->prepare("UPDATE iwwi_packing_detail SET no_po=?, item_no=? WHERE id=?");
                    $stmt->bind_param("ssi", $noPo, $itemNo, $row['id']);
                    $stmt->execute();
                    $updated += $stmt->affected_rows;
                    $stmt->close();
                }

                $_SESSION['msg'] = "✅ SJ berhasil diparse (mode multi-PO) — {$updated} baris diupdate untuk DO {$headerDo}.";
            } 
            // ==============================================
            // MODE 2 → SEMUA NO.PO SAMA
            // ==============================================
            else {
                $noPo = $uniquePOs[0] ?? null;
                $updated = 0;

                if ($noPo) {
                    $stmt = $db->prepare("UPDATE iwwi_packing_detail SET no_po=? WHERE header_id=?");
                    $stmt->bind_param("si", $noPo, $headerId);
                    $stmt->execute();
                    $stmt->close();

                    // mapping item berdasarkan kisi SJ (bisa kamu ubah sesuai supplier)
                    $kisi = [
                        "SWCH18A|3.22" => "80410222",
                        "SWCH18A|4.01" => "80010208",
                        "SWCH45K|4.85" => "80410397",
                        "SCM435|11.90" => "80410504",
                        "SWCH10A|5.24" => "80010035",
                        "SCM435|7.85"  => "80010152",
                        "SWCH18A|4.57" => "80410236",
                    ];

                    $res = $db->query("SELECT id, grade, size FROM iwwi_packing_detail WHERE header_id={$headerId}");
                    while ($row = $res->fetch_assoc()) {
                        $key = strtoupper($row['grade']) . "|" . number_format((float)$row['size'], 2, '.', '');
                        if (isset($kisi[$key])) {
                            $itemNo = $kisi[$key];
                            $stmt = $db->prepare("UPDATE iwwi_packing_detail SET item_no=? WHERE id=?");
                            $stmt->bind_param("si", $itemNo, $row['id']);
                            $stmt->execute();
                            $updated += $stmt->affected_rows;
                            $stmt->close();
                        }
                    }
                }

                $_SESSION['msg'] = "✅ SJ berhasil diparse (mode single-PO) — {$updated} baris item_no diisi untuk DO {$headerDo}.";
            }

            header("Location: iwwi_index_final.php");
            exit;
        }
    }

} catch (Exception $e) {
    $_SESSION['msg'] = "❌ Error: " . $e->getMessage();
    header("Location: iwwi_index_final.php");
    exit;
}
