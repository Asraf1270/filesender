<?php
// admin.php
require_once 'config.php';
require_once 'functions.php';
session_start();

$error = '';
if (isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['admin'] = true;
    } else {
        $error = "Wrong password.";
    }
}

if (!isset($_SESSION['admin'])) {
    show_login($error);
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header('Location: admin.php');
    exit;
}

if (isset($_GET['delete'])) {
    $code = $_GET['delete'];
    $metadata = get_metadata();
    if (isset($metadata[$code])) {
        if (file_exists($metadata[$code]['path'])) unlink($metadata[$code]['path']);
        unset($metadata[$code]);
        save_metadata($metadata);
    }
    header('Location: admin.php');
    exit;
}

$metadata = get_metadata();
cleanup_expired_files();
$total = count($metadata);
$size = array_sum(array_column($metadata, 'size'));

show_admin($metadata, $total, $size);
exit;

function show_login($error = '') {
    ?>
    <!DOCTYPE html><html><head><title>Admin Login</title>
    <style>
        body{font-family:system-ui;max-width:400px;margin:100px auto;text-align:center}
        input,button{width:100%;padding:12px;margin:8px 0;font-size:1em;border-radius:8px}
        button{background:#007bff;color:white;border:none;cursor:pointer}
        .error{color:#d00}
    </style></head><body>
    <h2>Admin Login</h2>
    <?php if($error): ?><p class="error"><?=$error?></p><?php endif; ?>
    <form method="post">
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
    </body></html>
    <?php
}

function show_admin($files, $total, $size) {
    ?>
    <!DOCTYPE html><html><head><title>Admin Panel</title>
    <style>
        body{font-family:system-ui;max-width:900px;margin:40px auto;padding:20px}
        table{width:100%;border-collapse:collapse;margin-top:20px}
        th,td{padding:10px;border-bottom:1px solid #ddd;text-align:left}
        th{background:#f8f8f8}
        .btn{padding:5px 10px;border-radius:4px;text-decoration:none;font-size:0.9em}
        .delete{background:#dc3545;color:white}
        .stats{background:#e9ecef;padding:15px;border-radius:8px;margin-bottom:20px}
        .logout{float:right;color:#666}
    </style></head><body>
    <h1>OneTimeSend Admin</h1>
    <a href="?logout" class="logout">Logout</a>
    <div class="stats">
        <strong><?=$total?></strong> active files | 
        <strong><?=number_format($size/1024/1024,2)?> MB</strong> total
    </div>
    <?php if(empty($files)): ?>
        <p>No active files.</p>
    <?php else: ?>
        <input type="text" id="search" placeholder="Search files..." onkeyup="filterTable()">
    <table>
        <tr><th>Code</th><th>File</th><th>Size</th><th>Expires</th><th>Password</th><th>Link</th><th>Total Attempts</th><th>Failed</th><th>Success Rate</th><th>Action</th></tr>
        <?php foreach($files as $code => $f):
            $expires = new DateTime($f['expires_at'], new DateTimeZone('UTC'));
            $link = BASE_URL . '/r/' . $code;
        ?>
        <tr>
            <td><code><?=$code?></code></td>
            <td><?=htmlspecialchars($f['original_name'])?></td>
            <td><?=number_format($f['size']/1024,1)?> KB</td>
            <td><?=$expires->format('M j, H:i')?> UTC</td>
            <td><?=$f['password']?></td>
            <td><a href="<?=$link?>" target="_blank">Open</a></td>
            <td><?= $f['total_attempts'] ?? 0 ?></td>
            <td><?= $f['failed_attempts'] ?? 0 ?></td>
            <td><?= round(100 - (($f['failed_attempts'] ?? 0) / max(1, $f['total_attempts'] ?? 1) * 100)) ?>%</td>
            <td><a href="?delete=<?=$code?>" class="btn delete" onclick="return confirm('Delete?')">Delete</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <script>
        function filterTable() {
    const q = document.getElementById('search').value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
    </script>
    <?php endif; ?>
    <hr><p><a href="public/">Back to Upload</a></p>
    </body></html>
    <?php
}
?>