<?php
session_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IWWI Parser Gabungan</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
    body {
        background-color: #f8f9fa;
    }
    .container {
        max-width: 700px;
        margin-top: 60px;
        background: white;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    h1 {
        font-size: 24px;
        margin-bottom: 20px;
    }
    .btn {
        width: 100%;
        font-weight: bold;
        letter-spacing: 0.5px;
    }
    .msg {
        margin-top: 15px;
        font-weight: 600;
    }
</style>
</head>
<body>

<div class="container">
    <h1 class="text-center">📦 IWWI Parser (Gabungan)</h1>
    <p class="text-center text-muted">Unggah file <b>Packing List</b> atau <b>Surat Jalan</b> untuk diproses otomatis.</p>
    <hr>

    <!-- ====== UPLOAD PL ====== -->
    <form action="iwwi_parsing_final.php" method="POST" enctype="multipart/form-data" class="mb-4">
        <div class="mb-3">
            <label for="plFile" class="form-label">📄 Upload Packing List (.pdf)</label>
            <input type="file" class="form-control" name="plFile" id="plFile" accept="application/pdf" required>
        </div>
        <button type="submit" name="upload_pl" class="btn btn-primary">🔍 Parse Packing List</button>
        <button type="submit" name="insert_pl" class="btn btn-success mt-2">💾 Simpan ke Database</button>
    </form>

    <hr>

    <!-- ====== UPLOAD SJ ====== -->
    <form action="iwwi_parsing_final.php" method="POST" enctype="multipart/form-data">
        <div class="mb-3">
            <label for="sjFile" class="form-label">🚚 Upload Surat Jalan (.pdf)</label>
            <input type="file" class="form-control" name="sjFile" id="sjFile" accept="application/pdf" required>
        </div>
        <button type="submit" name="upload_sj" class="btn btn-warning">⚙️ Parse & Update Surat Jalan</button>
    </form>

    <!-- ====== NOTIFIKASI ====== -->
    <?php if (isset($_SESSION['msg'])): ?>
        <div class="alert alert-info msg text-center mt-4">
            <?= nl2br(htmlspecialchars($_SESSION['msg'])) ?>
        </div>
        <?php unset($_SESSION['msg']); ?>
    <?php endif; ?>
</div>

</body>
</html>
