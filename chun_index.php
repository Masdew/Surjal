<?php
session_start();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Chunpao — Upload & Parser</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f8fa; padding:30px; }
    .card { border-radius:12px; }
    pre.debug { background:#111; color:#0f0; padding:10px; border-radius:6px; overflow:auto; }
  </style>
</head>
<body>
<div class="container" style="max-width:900px;">
  <div class="card p-4 shadow-sm">
    <h3 class="mb-3">📦 Chunpao — Parser (PL & SJ)</h3>

    <?php if (isset($_SESSION['msg'])): ?>
      <div class="alert alert-info"><?= nl2br(htmlspecialchars($_SESSION['msg'])) ?></div>
      <?php unset($_SESSION['msg']); ?>
    <?php endif; ?>

    <div class="row">
      <div class="col-md-6">
        <h6>Upload Packing List (PDF)</h6>
        <form action="chun_parsing.php" method="post" enctype="multipart/form-data">
          <div class="mb-2">
            <input type="file" name="plFile" accept="application/pdf" class="form-control" required>
          </div>
          <button class="btn btn-primary" name="upload_pl" type="submit">Parse PL</button>
          <button class="btn btn-success mt-2" name="insert_pl" type="submit"
            onclick="return confirm('Simpan hasil parsing PL ke database? Pastikan sudah parse sebelumnya.');">Simpan PL ke DB</button>
        </form>
      </div>

      <div class="col-md-6">
        <h6>Upload Surat Jalan (SJ) (PDF)</h6>
        <form action="chun_parsing.php" method="post" enctype="multipart/form-data">
          <div class="mb-2">
            <input type="file" name="sjFile" accept="application/pdf" class="form-control" required>
          </div>
          <button class="btn btn-warning" name="upload_sj" type="submit">Parse & Update SJ</button>
        </form>
      </div>
    </div>

    <hr>

    <?php if (isset($_SESSION['parsed'])): 
        $parsed = $_SESSION['parsed'];
    ?>
      <h5>Hasil Parsing (Preview) — <?= strtoupper(htmlspecialchars($parsed['type'])) ?></h5>
      <p><b>No Surat:</b> <?= htmlspecialchars($parsed['header']['no_surat'] ?? '-') ?> |
         <b>Tanggal:</b> <?= htmlspecialchars($parsed['header']['tanggal'] ?? '-') ?></p>

      <?php if ($parsed['type'] === 'packing'): ?>
        <table class="table table-sm table-bordered">
          <thead class="table-light">
            <tr><th>Grade</th><th>Size</th><th>J/O No</th><th>Heat No</th><th>Coil</th><th>NW</th><th>GW</th></tr>
          </thead>
          <tbody>
            <?php foreach ($parsed['details'] as $d): ?>
              <tr>
                <td><?= htmlspecialchars($d['item_no'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['size'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['jo_no'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['heat_no'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['coil'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['nw'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['gw'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <table class="table table-sm table-bordered">
          <thead class="table-light">
            <tr><th>Item No</th><th>Description</th><th>Pack & Remark</th></tr>
          </thead>
          <tbody>
            <?php foreach ($parsed['details'] as $d): ?>
              <tr>
                <td><?= htmlspecialchars($d['item_no'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['description'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['pack_remark'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <form action="chun_parsing.php" method="post">
        <button class="btn btn-success" name="confirm_upload" type="submit">Upload ke Database</button>
        <a class="btn btn-outline-secondary" href="chun_index.php?clear=1">Batal / Hapus Preview</a>
      </form>
    <?php endif; ?>

    <?php if (isset($_GET['clear'])) { unset($_SESSION['parsed']); header('Location: chun_index.php'); exit; } ?>

  </div>
</div>
</body>
</html>
