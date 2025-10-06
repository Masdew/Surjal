<?php
require 'vendor/autoload.php';

use Spatie\PdfToText\Pdf;

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'test_merge';

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$header     = ['username' => '', 'do_num' => '', 'delv_date' => ''];
$data       = [];
$successMsg = $errMsg = "";
$gradeOrder = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insert_db'])) {
    $header = json_decode($_POST['header_json'], true);
    $data   = json_decode($_POST['data_json'], true);

    if ($header && $data) {
        $stmt = $conn->prepare("INSERT INTO header (do_num, delv_date, username) VALUES (?,?,?)");
        $stmt->bind_param("sss", $header['do_num'], $header['delv_date'], $header['username']);
        $stmt->execute();
        $header_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO detail (header_id, no, grade, size, shape, coil_no, heat_no, qty, pcs) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($data as $row) {
            $stmt->bind_param(
                "iisssssdi",
                $header_id,
                $row['No'],
                $row['Grade'],
                $row['Size'],
                $row['Shape'],
                $row['Coil_No'],
                $row['Heat_No'],
                $row['Qty'],
                $row['Pcs']
            );
            $stmt->execute();
        }
        $stmt->close();

        $successMsg = "✅ Data berhasil dimasukkan ke database!";
    } else {
        $errMsg = "❌ Data JSON tidak valid.";
    }
}

if (isset($_FILES['pdfFile']) && $_FILES['pdfFile']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $originalName = basename($_FILES['pdfFile']['name']);
    $ext          = pathinfo($originalName, PATHINFO_EXTENSION);
    $safeName     = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $newName      = $safeName . '_' . uniqid() . '.' . $ext;
    $pdfPath      = $uploadDir . $newName;

    if (!move_uploaded_file($_FILES['pdfFile']['tmp_name'], $pdfPath)) {
        $errMsg = "❌ Gagal memindahkan file ke folder uploads.";
    } else {
        $realPdfPath = realpath($pdfPath);

        if ($realPdfPath === false || !file_exists($realPdfPath)) {
            $errMsg = "❌ File tidak ditemukan setelah upload: {$pdfPath}";
        } else {
            try {
                $pdf = new Pdf('C:/poppler-25.07.0/Library/bin/pdftotext.exe');
                $text = $pdf->setPdf($realPdfPath)->addOptions(['-layout'])->text();

                // Ambil Header
                if (preg_match('/Username\s*:\s*(.+)/i', $text, $m)) {
                    $header['username'] = trim($m[1]);
                }
                if (preg_match('/DO Number\s*:\s*([A-Z0-9-]+)/i', $text, $m)) {
                    $header['do_num'] = $m[1];
                }
                if (preg_match('/(\d{1,2}-[A-Za-z]{3}-\d{2,4})/', $text, $m)) {
                    $header['delv_date'] = date('Y-m-d', strtotime($m[1]));
                }

                $lines        = preg_split('/\r\n|\r|\n/', $text);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;

                    // Parsing detail line (Grade + Length opsional)
                    if (preg_match('/^(\d+)\s+([A-Z0-9]+)\s+([\d.]+)\s+([A-Z])(?:\s+([\d.]+))?\s+(\S+)\s+(\S+)\s+([\d.,]+)\s+(\d+)$/', $line, $m)) {
                        $data[] = [
                            'No'      => (int)$m[1],
                            'Grade'   => $m[2],
                            'Size'    => $m[3],
                            'Shape'   => $m[4],
                            // $m[5] = Length (opsional) → sengaja di-skip
                            'Coil_No' => $m[6],
                            'Heat_No' => $m[7],
                            'Qty'     => (float)str_replace(',', '', $m[8]),
                            'Pcs'     => (int)$m[9],
                        ];
                        continue;
                    }
                }

            } catch (\Exception $e) {
                $errMsg = "❌ Error parsing PDF: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Preview Data PDF</title>
<style>
    body { font-family: Arial, sans-serif; padding:20px; }
    table { border-collapse: collapse; width:100%; margin-top:20px; }
    th, td { border:1px solid #ccc; padding:6px; text-align:center; font-size:14px; }
    th { background:#0077ff; color:#fff; }
    .msg { padding:10px; margin:10px 0; border-radius:5px; }
    .success { background:#d4edda; color:#155724; }
    .error { background:#f8d7da; color:#721c24; }
</style>
</head>
<body>

<h2>Preview Data dari PDF</h2>

<?php if ($successMsg): ?><div class="msg success"><?= $successMsg ?></div><?php endif; ?>
<?php if ($errMsg): ?><div class="msg error"><?= $errMsg ?></div><?php endif; ?>

<h3>Header</h3>
<ul>
    <li><b>Username:</b> <?= htmlspecialchars($header['username']) ?></li>
    <li><b>DO Number:</b> <?= htmlspecialchars($header['do_num']) ?></li>
    <li><b>Delivery Date:</b> <?= htmlspecialchars($header['delv_date']) ?></li>
</ul>

<h3>Detail</h3>
<form method="post">
    <input type="hidden" name="header_json" value='<?= json_encode($header) ?>'>
    <input type="hidden" name="data_json" value='<?= json_encode($data) ?>'>
    <table>
        <tr>
            <th>No</th><th>Grade</th><th>Size</th><th>Shape</th><th>Coil No</th><th>Heat No</th><th>Qty</th><th>Pcs</th>
        </tr>
        <?php foreach ($data as $row): ?>
        <tr>
            <td><?= $row['No'] ?></td>
            <td><?= $row['Grade'] ?></td>
            <td><?= $row['Size'] ?></td>
            <td><?= $row['Shape'] ?></td>
            <td><?= $row['Coil_No'] ?></td>
            <td><?= $row['Heat_No'] ?></td>
            <td><?= $row['Qty'] ?></td>
            <td><?= $row['Pcs'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (!empty($data)): ?>
        <button type="submit" name="insert_db">💾 Insert ke Database</button>
    <?php endif; ?>
</form>

</body>
</html>
