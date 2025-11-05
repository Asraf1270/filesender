<?php
// public/index.php
require_once '../config.php';
require_once '../functions.php';

// === PHP BUILT-IN SERVER SUPPORT ===
if (php_sapi_name() === 'cli-server') {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $uri;
    if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
        return false;
    }
}

// === CLEANUP & RATE LIMIT ===
cleanup_expired_files();
check_rate_limit();

// === EXTRACT PATH ===
$request = $_SERVER['REQUEST_URI'];
$base = rtrim(parse_url(BASE_URL, PHP_URL_PATH), '/');
$path = preg_replace('#^' . preg_quote($base, '#') . '#', '', $request);
$path = ltrim($path, '/');

// === ROUTING ===
if (preg_match('#^r/([A-Za-z0-9]{3,6})$#', $path, $matches)) {
    $code = $matches[1];
    handle_download($code);
    exit;
}

// AJAX Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    handle_ajax_upload();
    exit;
}

// Default: Upload form
handle_upload_form();
exit;

// ————————————————————
// AJAX UPLOAD
// ————————————————————
function handle_ajax_upload(): void {
    header('Content-Type: application/json');
    $response = ['success' => false];

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $response['error'] = 'Upload failed.';
        echo json_encode($response);
        return;
    }

    $file = $_FILES['file'];
    $password = trim($_POST['password'] ?? '');

    // === FILE TYPE CHECK ===
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, DANGEROUS_EXTS)) {
        $response['error'] = 'File type blocked for security.';
        echo json_encode($response);
        return;
    }
    if (!in_array($file['type'], SAFE_MIMES)) {
        $response['error'] = 'Invalid file type.';
        echo json_encode($response);
        return;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        $response['error'] = 'File too large.';
        echo json_encode($response);
        return;
    }

    $metadata = get_metadata();
    $code = generate_unique_code($metadata);
    $safeName = $code . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = UPLOAD_DIR . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $response['error'] = 'Failed to save file.';
        echo json_encode($response);
        return;
    }

    $expires = (new DateTime('UTC'))->modify('+24 hours');
    $link = BASE_URL . '/r/' . $code;
    $qrApi = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($link);

    $metadata[$code] = [
        'original_name' => $file['name'],
        'path' => $destPath,
        'mime' => $file['type'],
        'size' => $file['size'],
        'password' => $password,
        'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
        'used' => false,
        'total_attempts' => 0,
        'failed_attempts' => 0
    ];
    save_metadata($metadata);

    $response = [
        'success' => true,
        'code' => $code,
        'link' => $link,
        'qr' => $qrApi
    ];
    echo json_encode($response);
}

// ————————————————————
// DOWNLOAD + PREVIEW
// ————————————————————
function handle_download(string $code): void {
    increment_counters($code, false);

    $metadata = get_metadata();
    if (!isset($metadata[$code])) {
        show_message("File not found or already downloaded.", 'error');
        return;
    }

    $info = $metadata[$code];
    $now = new DateTime('UTC');
    $expires = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $info['expires_at'], new DateTimeZone('UTC'));

    if ($expires < $now) {
        show_message("File has expired.", 'error');
        return;
    }

    // === PREVIEW IF NO PASSWORD ===
    if (empty($info['password']) && in_array($info['mime'], ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain'])) {
        show_preview($code);
        return;
    }

    // === PASSWORD PROTECTED ===
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $correct = hash_equals($info['password'], $_POST['password']);
        increment_counters($code, $correct);

        if (!$correct) {
            show_password_form($code, "Incorrect password.");
            return;
        }

        // === MARK AS USED + DOWNLOAD ===
        $metadata[$code]['used'] = true;
        save_metadata($metadata);

        send_file($info['path'], $info['original_name'], $info['mime']);
            exit; // ← NO unlink()
    }

    show_password_form($code);
}

// ————————————————————
// FILE PREVIEW
// ————————————————————
function show_preview(string $code): void {
    $metadata = get_metadata();
    if (!isset($metadata[$code])) {
        show_message("File not found.", 'error');
        return;
    }

    $info = $metadata[$code];
    $file_path = $info['path'];
    $mime = $info['mime'];
    $name = $info['original_name'];

    // === DOWNLOAD TRIGGER ===
    if (isset($_GET['download'])) {
        $metadata[$code]['used'] = true;
        save_metadata($metadata);

        send_file($file_path, $name, $mime);

        // DELETE FILE ONLY — KEEP JSON
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
        exit;
    }

    $preview_html = '';
    if (file_exists($file_path)) {
        $data_uri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file_path));

        if (strpos($mime, 'image/') === 0) {
            $preview_html = "<img src='$data_uri' style='max-width:100%;max-height:500px;display:block;margin:20px auto;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);'>";
        } elseif ($mime === 'application/pdf') {
            $preview_html = "<embed src='$data_uri' type='application/pdf' width='100%' height='700px' style='border:1px solid #ddd;border-radius:8px;'>";
        } elseif ($mime === 'text/plain') {
            $text = htmlspecialchars(file_get_contents($file_path), ENT_QUOTES, 'UTF-8');
            $preview_html = "<pre style='background:#f8f9fa;padding:16px;border-radius:8px;max-height:500px;overflow:auto;border:1px solid #ddd;font-family:monospace;'>$text</pre>";
        }
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Preview - <?= htmlspecialchars($name) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { font-family: system-ui; background: #f9f9fb; margin: 0; padding: 20px; }
            .container { max-width: 900px; margin: 40px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            h1 { text-align: center; color: #007bff; }
            .download-btn { display: block; width: 200px; margin: 25px auto; background: #dc3545; color: white; padding: 12px; border-radius: 8px; text-align: center; text-decoration: none; font-weight: bold; }
            .back { text-align: center; margin-top: 30px; color: #007bff; font-size: 0.9em; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>File Preview</h1>
            <?php if ($preview_html): ?>
                <?= $preview_html ?>
                <a href="?download=1" class="download-btn">Download File</a>
            <?php else: ?>
                <p style="text-align:center;color:#666;">Preview not available.</p>
                <a href="?download=1" class="download-btn">Download</a>
            <?php endif; ?>
            <a href="/" class="back">Back to Upload</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ————————————————————
// UPLOAD FORM
// ————————————————————
function handle_upload_form(): void {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>OneTimeSend</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            :root { --primary: #007bff; --success: #28a745; }
            body { font-family: system-ui; max-width: 600px; margin: 40px auto; padding: 20px; background: #f9f9fb; }
            .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
            h1 { text-align: center; color: var(--primary); }
            p { text-align: center; color: #666; }
            input[type="file"], input[type="password"] { width: 100%; padding: 12px; margin: 12px 0; border: 1px solid #ddd; border-radius: 8px; }
            progress { width: 100%; height: 24px; border-radius: 8px; margin: 15px 0; }
            button { background: var(--primary); color: white; padding: 14px; font-size: 1.1em; border-radius: 8px; cursor: pointer; width: 100%; border: none; }
            .success { background: #d4edda; color: #155724; padding: 16px; border-radius: 8px; margin: 15px 0; }
            .copy-btn { background: var(--success); padding: 8px 16px; font-size: 0.9em; margin-left: 8px; border: none; border-radius: 6px; color: white; cursor: pointer; }
            .qr-img { max-width: 200px; margin: 15px auto; display: block; border: 1px solid #ddd; border-radius: 8px; }
            .admin-link { text-align: center; margin-top: 20px; font-size: 0.9em; }
        </style>
        <script>
        function uploadFile() {
            const file = document.getElementById('file').files[0];
            const password = document.getElementById('password').value;
            const progress = document.getElementById('progress');
            const result = document.getElementById('result');
            if (!file) return alert('Choose a file');

            const formData = new FormData();
            formData.append('file', file);
            formData.append('password', password);
            formData.append('ajax', '1');

            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = e => {
                if (e.lengthComputable) progress.value = (e.loaded / e.total) * 100;
            };
            xhr.onload = () => {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    result.innerHTML = `
                        <div class="success">
                            <p><strong>File uploaded!</strong></p>
                            <p>Share link:</p>
                            <input type="text" value="${res.link}" readonly onclick="this.select()" style="width:100%;padding:8px;font-family:monospace;">
                            <button class="copy-btn" onclick="copy('${res.link}')">Copy</button>
                            <p><strong>QR Code:</strong></p>
                            <img src="${res.qr}" class="qr-img" alt="QR">
                            <p><a href="${res.qr}&download=1" download="qr.png" style="color:var(--success);">Download QR</a></p>
                        </div>
                    `; 
                    document.getElementById('upload-form').style.display = 'none';
                } else {
                    alert(res.error || 'Upload failed');
                }
            };
            xhr.open('POST', '');
            xhr.send(formData);
        }
        function copy(text) {
            navigator.clipboard.writeText(text).then(() => alert('Copied!'));
        }
        </script>
    </head>
    <body>
        <div class="card">
            <h1>OneTimeSend</h1>
            <p>Upload once. Download once. Gone forever.</p>
            <div id="upload-form">
                <input type="file" id="file" required>
                <progress id="progress" value="0" max="100"></progress>
                <input type="password" id="password" placeholder="Optional password">
                <button onclick="uploadFile()">Upload & Generate Link</button>
            </div>
            <div id="result"></div>
            <small style="display:block;text-align:center;margin-top:15px;">
                Max: <?= number_format(MAX_FILE_SIZE/1024/1024) ?> MB
            </small>
        </div>
    </body>
    </html>
    <?php
}

// ————————————————————
// TEMPLATES
// ————————————————————
function show_password_form(string $code, string $error = ''): void {
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>Password Required</title>
    <style>
        body{display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f0f2f5}
        .card{background:white;padding:40px;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.1);width:100%;max-width:400px;text-align:center}
        input,button{width:100%;padding:14px;margin:15px 0;border-radius:8px;font-size:1.1em}
        button{background:#28a745;color:white;border:none;cursor:pointer}
        .error{color:#d00;margin:10px 0}
    </style></head><body>
    <div class="card">
        <h2>Password Required</h2>
        <?php if($error): ?><p class="error"><?=htmlspecialchars($error)?></p><?php endif; ?>
        <form method="post">
            <input type="password" name="password" placeholder="Enter password" required autofocus>
            <button type="submit">Download</button>
        </form>
    </div>
    </body></html>
    <?php
}

function show_message(string $msg, string $type = 'info'): void {
    $color = $type === 'error' ? '#d00' : '#666';
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>OneTimeSend</title>
    <style>body{font-family:system-ui;max-width:500px;margin:80px auto;text-align:center;color:<?=$color?>}</style>
    </head><body>
    <h2><?=htmlspecialchars($msg)?></h2>
    <p><a href="/">Back to Upload</a></p>
    </body></html>
    <?php
}
?>