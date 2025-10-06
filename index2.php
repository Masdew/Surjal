<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload PDF</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9f9; text-align: center; }
        .container { margin-top: 100px; background: #fff; padding: 30px; border-radius: 8px; width: 400px; margin-left:auto; margin-right:auto; box-shadow: 0 0 10px rgba(0,0,0,0.1);}
        input[type="file"] { margin: 10px 0; }
        button { background: #007BFF; color: #fff; border: none; padding: 10px 20px; cursor: pointer; border-radius: 5px; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
<div class="container">
    <h2>Upload PDF</h2>
    <form action="parse_final.php" method="post" enctype="multipart/form-data">
        <input type="file" name="pdfFile" accept="application/pdf" required><br>
        <button type="submit">Upload & Parse</button>
    </form>
</div>
</body>
</html>
