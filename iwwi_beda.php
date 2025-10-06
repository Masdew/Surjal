<?php
session_start();
?>
<!DOCTYPE html> 
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Upload & Parsing PDF</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f8fb; padding: 20px; }
    h2 { margin-top: 30px; }
    .msg { margin: 15px 0; color: green; font-weight: bold; }
    .form-box { margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 8px; }
    table { border-collapse: collapse; width: 100%; margin-top: 15px; background: #fff; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
    th { background: #eee; }
    .btn { padding: 6px 12px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px; }
    .btn-primary { background: #007bff; color: #fff; }
    .btn-success { background: #28a745; color: #fff; }
    .btn-warning { background: #ffc107; color: #000; }
  </style>
</head>
<body>

<h1>📄 Upload & Parsing PDF</h1>

<?php if (!empty($_SESSION['msg'])): ?>
  <div class="msg"><?= $_SESSION['msg'] ?></div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<!-- Upload PL -->
<h2>Upload Packing List (PL)</h2>
<form method="post" action="parsing.php" enctype="multipart/form-data" class="form-box">
  <input type="file" name="plFile" accept="application/pdf" required>
  <button type="submit" name="upload_pl" class="btn btn-primary">Upload & Parse PL</button>
</form>

<!-- Preview dan Tombol Insert PL -->
<?php if (!empty($_SESSION['pl_data'])): ?>
    <h3>Preview Packing List</h3>
    
    <div class="form-box">
        <h4>Header Info:</h4>
        <p><strong>DO Number:</strong> <?= htmlspecialchars($_SESSION['pl_data']['header']['do_num']) ?></p>
        <p><strong>Delivery Date:</strong> <?= htmlspecialchars($_SESSION['pl_data']['header']['delv_date']) ?></p>
        <p><strong>Username:</strong> <?= htmlspecialchars($_SESSION['pl_data']['header']['username']) ?></p>
        
        <h4>Items (<?= count($_SESSION['pl_data']['details']) ?> items):</h4>
        <table>
            <tr>
                <th>No</th>
                <th>Grade</th>
                <th>Size</th>
                <th>Shape</th>
                <th>Coil No</th>
                <th>Heat No</th>
                <th>Qty</th>
                <th>Pcs</th>
            </tr>
            <?php foreach ($_SESSION['pl_data']['details'] as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['no']) ?></td>
                <td><?= htmlspecialchars($item['grade']) ?></td>
                <td><?= htmlspecialchars($item['size']) ?></td>
                <td><?= htmlspecialchars($item['shape']) ?></td>
                <td><?= htmlspecialchars($item['coil_no']) ?></td>
                <td><?= htmlspecialchars($item['heat_no']) ?></td>
                <td><?= htmlspecialchars($item['qty']) ?></td>
                <td><?= htmlspecialchars($item['pcs']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <form method="post" action="iwwi_parsing_beda.php" style="margin-top: 15px;">
            <button type="submit" name="insert_pl" class="btn btn-success">Insert Packing List ke Database</button>
        </form>
    </div>
<?php endif; ?>

<!-- Upload SJ -->
<h2>Upload Surat Jalan (SJ)</h2>
<form method="post" action="parsing.php" enctype="multipart/form-data" class="form-box">
  <input type="file" name="sjFile" accept="application/pdf" required>
  <button type="submit" name="upload_sj" class="btn btn-primary">Upload & Update DB</button>
</form>

<!-- Preview SJ update -->
<?php if (!empty($_SESSION['sj_data'])): ?>
    <h3>Hasil Update dari SJ</h3>
    <table>
        <tr>
            <th>No PO</th>
            <th>Item No</th>
        </tr>
        <?php foreach ($_SESSION['sj_data'] as $sj): ?>
        <tr>
            <td><?= htmlspecialchars($sj['no_po']) ?></td>
            <td><?= htmlspecialchars($sj['item_no']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php unset($_SESSION['sj_data']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['parsed_sj'])): ?>
    <h3>Debug Parsing SJ</h3>
    <pre><?php print_r($_SESSION['parsed_sj']); ?></pre>
    <?php unset($_SESSION['parsed_sj']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['raw_sj_text'])): ?>
    <h3>Debug Raw Text dari PDF</h3>
    <pre><?= $_SESSION['raw_sj_text'] ?></pre>
    <?php unset($_SESSION['raw_sj_text']); ?>
<?php endif; ?>

</body>
</html>