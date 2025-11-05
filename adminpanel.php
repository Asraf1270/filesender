<?php
// adminpanel.php
require_once 'config.php';
require_once 'functions.php';
session_start();

// === AUTH ===
if (!isset($_SESSION['admin']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['admin'] = true;
    } else {
        $login_error = "Wrong password.";
    }
}

if (!isset($_SESSION['admin'])) {
    show_login_form($login_error ?? '');
    exit;
}

// === LOGOUT ===
if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header('Location: adminpanel.php');
    exit;
}

// === DELETE FILE (Manual by Admin) ===
if (isset($_GET['delete'])) {
    $code = $_GET['delete'];
    $metadata = get_metadata();
    if (isset($metadata[$code])) {
        if (file_exists($metadata[$code]['path'])) {
            @unlink($metadata[$code]['path']);
        }
        unset($metadata[$code]);
        save_metadata($metadata);
    }
    header('Location: adminpanel.php');
    exit;
}

// === CLEANUP EXPIRED (Run on load) ===
cleanup_expired_files();

// === GET DATA ===
$metadata = get_metadata();
$total_files = count($metadata);
$total_size = array_sum(array_column($metadata, 'size'));

show_admin_panel($metadata, $total_files, $total_size);
exit;

// ————————————————————
// LOGIN FORM
// ————————————————————
function show_login_form(string $error = ''): void {
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>Admin Login</title>
    <style>
        body{font-family:system-ui;max-width:400px;margin:100px auto;text-align:center;background:#f4f6f9}
        .card{background:#fff;padding:40px;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.1)}
        input,button{width:100%;padding:14px;margin:10px 0;border-radius:8px;font-size:1.1em}
        button{background:#007bff;color:white;border:none;cursor:pointer}
        .error{color:#d00;margin:10px 0;font-weight:bold}
    </style></head><body>
    <div class="card">
        <h2>OneTimeSend Admin</h2>
        <?php if($error): ?><p class="error"><?=$error?></p><?php endif; ?>
        <form method="post">
            <input type="password" name="password" placeholder="Enter admin password" required autofocus>
            <button type="submit">Login</button>
        </form>
    </div>
    </body></html>
    <?php
}

// ————————————————————
// MAIN ADMIN PANEL
// ————————————————————
function show_admin_panel(array $files, int $total, float $size): void {
    $base_url = BASE_URL;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Admin Panel - OneTimeSend</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            :root { --primary: #007bff; --danger: #dc3545; --success: #28a745; }
            body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f4f6f9; }
            .header { background: #fff; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.1); text-align: center; }
            .header h1 { margin: 0; color: var(--primary); }
            .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
            .stats { display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: wrap; }
            .stat-card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.08); flex: 1; min-width: 180px; text-align: center; }
            .stat-card strong { display: block; font-size: 1.8em; color: var(--primary); }
            table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
            th, td { padding: 14px; text-align: left; border-bottom: 1px solid #eee; }
            th { background: #f8f9fa; font-weight: 600; color: #333; }
            tr:hover { background: #f8f9fa; }
            .btn { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.9em; font-weight: 600; }
            .btn-delete { background: var(--danger); color: white; }
            .btn-delete:hover { background: #c82333; }
            .btn-view { background: var(--primary); color: white; }
            .opened-yes { color: var(--success); font-weight: bold; }
            .opened-no { color: #dc3545; font-weight: bold; }
            .search { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 1em; }
            .logout { float: right; color: #666; font-size: 0.9em; margin-top: 10px; }
        </style>
        <script>
        function filterTable() {
            const query = document.getElementById('search').value.toLowerCase();
            document.querySelectorAll('tbody tr').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }
        </script>
    </head>
    <body>
        <div class="header">
            <h1>OneTimeSend Admin Panel</h1>
            <a href="?logout" class="logout">Logout</a>
        </div>
        <div class="container">
            <div class="stats">
                <div class="stat-card">
                    <strong><?= $total ?></strong>
                    <span>Active Files</span>
                </div>
                <div class="stat-card">
                    <strong><?= number_format($size / 1024 / 1024, 2) ?> MB</strong>
                    <span>Total Size</span>
                </div>
            </div>

            <input type="text" id="search" class="search" placeholder="Search files, codes, IPs..." onkeyup="filterTable()">

            <?php if (empty($files)): ?>
                <p style="text-align:center;color:#666;padding:40px;background:#fff;border-radius:12px;">No active files.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Expires</th>
                        <th>Password</th>
                        <th>Opened</th>
                        <th>Attempts</th>
                        <th>Failed</th>
                        <th>Success Rate</th>
                        <th>Link</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $code => $f):
                        $expires = new DateTime($f['expires_at'], new DateTimeZone('UTC'));
                        $link = $base_url . '/r/' . $code;
                        $opened = !empty($f['used']) ? 'Yes' : 'No';
                        $total = $f['total_attempts'] ?? 0;
                        $failed = $f['failed_attempts'] ?? 0;
                        $success_rate = $total > 0 ? round((($total - $failed) / $total) * 100) : 100;
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($code) ?></code></td>
                        <td><?= htmlspecialchars($f['original_name']) ?></td>
                        <td><?= number_format($f['size'] / 1024, 1) ?> KB</td>
                        <td><?= $expires->format('M j, H:i') ?> UTC</td>
                        <td><?= $f['password'] ? 'Yes' : 'No' ?></td>
                        <td class="opened-<?= strtolower($opened) ?>"><?= $opened ?></td>
                        <td><?= $total ?></td>
                        <td><?= $failed ?></td>
                        <td><?= $success_rate ?>%</td>
                        <td><a href="<?= $link ?>" target="_blank" class="btn btn-view">Open</a></td>
                        <td><a href="?delete=<?= $code ?>" class="btn btn-delete" onclick="return confirm('Delete this file?')">Delete</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <p style="text-align:center;margin-top:30px;">
                <a href="public/">Back to Upload</a>
            </p>
        </div>
    </body>
    </html>
    <?php
}
?>