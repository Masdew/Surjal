<?php
session_start();
require 'vendor/autoload.php';
use Spatie\PdfToText\Pdf;

$db = new mysqli("localhost", "root", "", "gabungan");
if ($db->connect_error) die("DB Error: " . $db->connect_error);

$pdfBin = 'C:/poppler-25.07.0/Library/bin/pdftotext.exe';

function normalizeNum($val) {
    $val = trim(str_replace(',', '.', $val));
    return is_numeric($val) ? number_format((float)$val, 2, '.', '') : $val;
}

try {
    // ================= PARSE PACKING LIST =================
    if (isset($_POST['upload_pl']) && isset($_FILES['plFile'])) {
        if ($_FILES['plFile']['error'] === UPLOAD_ERR_OK) {
            $pdfPath = $_FILES['plFile']['tmp_name'];
            $pdf = new Pdf($pdfBin);
            $text = $pdf->setPdf($pdfPath)->addOptions(['-layout'])->text();

            // ambil no surat & tanggal
            $no_surat = '';
            if (preg_match('/U\d{6,8}/i', $text, $m)) $no_surat = strtoupper(trim($m[0]));
            $tanggal = '';
            if (preg_match('/([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})/', $text, $m)) {
    $bulanMap = [
        'January'=>'01','February'=>'02','March'=>'03','April'=>'04','May'=>'05','June'=>'06',
        'July'=>'07','August'=>'08','September'=>'09','October'=>'10','November'=>'11','December'=>'12'
    ];
    $month = $bulanMap[$m[1]] ?? '01';
    $tanggal = sprintf("%04d-%02d-%02d", $m[3], $month, $m[2]);
}


            // Parsing isi tabel
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $details = [];
            $currentGrade = '';
            $currentSize  = '';

            foreach ($lines as $line) {
                $line = trim(preg_replace('/\s+/', ' ', $line));
                if ($line === '' || preg_match('/^(Total|Dibuat|Putih:|Item No\.|PT\.|Factory:|Packing List)/i', $line)) continue;

                // baris utama
                if (preg_match('/^(SWCH[0-9A-Z*]+)\s+Steel\s+Wire\s+([\d.]+)\s*m\/m\s+([A-Z0-9*]+)\s+([A-Z0-9\/]+)\s+(\d+)\s+([\d.,]+)\s+([\d.,]+)/i', $line, $m)) {
                    $currentGrade = strtoupper($m[1]);
                    $currentSize  = normalizeNum($m[2]);
                    $heat  = strtoupper($m[3]);
                    $jo    = strtoupper($m[4]);
                    $coil  = $m[5];
                    $nw    = str_replace(',', '', $m[6]);
                    $gw    = str_replace(',', '', $m[7]);
                    $details[] = [
                        'item_no' => $currentGrade,
                        'size'    => $currentSize,
                        'jo_no'   => $jo,
                        'heat_no' => $heat,
                        'coil'    => $coil,
                        'nw'      => $nw,
                        'gw'      => $gw
                    ];
                    continue;
                }

                // baris lanjutan
                if (preg_match('/^\s*(?:D,A,P,S|diam\.)?\s*([A-Z0-9*]+)\s+([A-Z0-9\/]+)\s+(\d+)\s+([\d.,]+)\s+([\d.,]+)/i', $line, $m)) {
                    if (!$currentGrade || !$currentSize) continue;
                    $heat = strtoupper($m[1]);
                    $jo   = strtoupper($m[2]);
                    $coil = $m[3];
                    $nw   = str_replace(',', '', $m[4]);
                    $gw   = str_replace(',', '', $m[5]);
                    $details[] = [
                        'item_no' => $currentGrade,
                        'size'    => $currentSize,
                        'jo_no'   => $jo,
                        'heat_no' => $heat,
                        'coil'    => $coil,
                        'nw'      => $nw,
                        'gw'      => $gw
                    ];
                }
            }

            $_SESSION['parsed'] = [
                'type' => 'packing',
                'header' => ['no_surat' => $no_surat, 'tanggal' => $tanggal],
                'details' => $details
            ];

            $_SESSION['msg'] = "✅ Parsing Packing List selesai: " . count($details) . " baris ditemukan (No Surat $no_surat).";
            header("Location: chun_index.php");
            exit;
        }
    }

    // ================= INSERT PACKING LIST KE DB =================
    if (isset($_POST['insert_pl']) || isset($_POST['confirm_upload'])) {
        if (!isset($_SESSION['parsed']) || $_SESSION['parsed']['type'] !== 'packing') {
            $_SESSION['msg'] = "❌ Tidak ada data PL di preview. Parse dulu sebelum simpan.";
            header("Location: chun_index.php");
            exit;
        }

        $data = $_SESSION['parsed'];
        $hdr = $data['header'];
        $details = $data['details'];

        // Cek duplikat
        $stmt = $db->prepare("SELECT id FROM chunpao_packing_header WHERE no_surat = ?");
        $stmt->bind_param("s", $hdr['no_surat']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $_SESSION['msg'] = "❌ No Surat '{$hdr['no_surat']}' sudah ada di database.";
            header("Location: chun_index.php");
            exit;
        }
        $stmt->close();

        // insert header
        $stmt = $db->prepare("INSERT INTO chunpao_packing_header (no_surat, tanggal, username) VALUES (?,?,?)");
        $username = $_SESSION['username'] ?? 'system';
        $stmt->bind_param("sss", $hdr['no_surat'], $hdr['tanggal'], $username);
        $stmt->execute();
        $header_id = $stmt->insert_id;
        $stmt->close();

        // insert detail
        $stmt = $db->prepare("INSERT INTO chunpao_packing_detail (header_id, item_no, size, jo_no, heat_no, coil, nw, gw)
                              VALUES (?,?,?,?,?,?,?,?)");
        foreach ($details as $d) {
            $stmt->bind_param("isssssdd", $header_id, $d['item_no'], $d['size'], $d['jo_no'], $d['heat_no'], $d['coil'], $d['nw'], $d['gw']);
            $stmt->execute();
        }
        $stmt->close();

        unset($_SESSION['parsed']);
        $_SESSION['msg'] = "✅ PL Chunpao berhasil disimpan (No Surat {$hdr['no_surat']}, " . count($details) . " baris).";
        header("Location: chun_index.php");
        exit;
    }

   // ================= PARSE & UPDATE SURAT JALAN =================
// ================= PARSE & UPDATE SURAT JALAN =================
if (isset($_POST['upload_sj']) && isset($_FILES['sjFile'])) {
    if ($_FILES['sjFile']['error'] === UPLOAD_ERR_OK) {
        $pdfPath = $_FILES['sjFile']['tmp_name'];
        $pdf = new Pdf($pdfBin);
        $text = $pdf->setPdf($pdfPath)->addOptions(['-layout'])->text();

        // --- cari No Surat Jalan ---
        $no_surat = '';
        if (preg_match('/U\d{6,8}/i', $text, $m)) {
            $no_surat = strtoupper(trim($m[0]));
        }

        // --- cek header ada di DB ---
        $stmt = $db->prepare("SELECT id FROM chunpao_packing_header WHERE no_surat = ?");
        $stmt->bind_param("s", $no_surat);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $_SESSION['msg'] = "❌ PL {$no_surat} belum ada di DB.";
            header("Location: chun_index.php");
            exit;
        }
        $header_id = $res->fetch_assoc()['id'];
        $stmt->close();

        // --- cari semua grup SJ ---
        $groups = [];
        $pattern = '/Steel\s+Wire\s+diam\.?\s*([\d.]+)\s*m\/m\s+([\d.,]+)\s*KG\s+([\d.,]+)\s*(?:KG)?\s+([^\r\n]+)/i';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $groups[] = [
                    'size'   => normalizeNum($m[1]),
                    'desc'   => "Steel Wire diam. {$m[1]} m/m",
                    'remark' => trim($m[4]),
                ];
            }
        }

        if (empty($groups)) {
            echo "<pre style='background:#222;color:#0f0;padding:10px'>";
            echo "❌ Tidak ada grup ditemukan di file SJ.\n\nPreview isi PDF:\n-----------------\n";
            echo htmlspecialchars(implode("\n", array_slice(explode("\n", $text), 0, 50)));
            echo "</pre>";
            exit;
        }

        // --- update berdasarkan size yang cocok ---
        $updated = 0;
        foreach ($groups as $g) {
            $stmt = $db->prepare("UPDATE chunpao_packing_detail 
                                  SET description=?, pack_remark=? 
                                  WHERE header_id=? AND size=?");
            $stmt->bind_param("ssis", $g['desc'], $g['remark'], $header_id, $g['size']);
            $stmt->execute();
            $updated += $stmt->affected_rows;
            $stmt->close();
        }

        $_SESSION['msg'] = "✅ SJ berhasil diparse & update {$updated} baris (No Surat {$no_surat}).";
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
