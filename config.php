<?php
// config.php
define('BASE_URL', 'http://localhost/HOST/onetime-send/public');
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', DATA_DIR . '/uploads');
define('JSON_FILE', DATA_DIR . '/files.json');
define('RATES_FILE', DATA_DIR . '/rates.json'); // NEW: Rate limiting
define('MAX_FILE_SIZE', 100 * 1024 * 1024);
define('DEFAULT_EXPIRY_HOURS', 24);
define('CODE_LENGTH_MIN', 3);
define('CODE_LENGTH_MAX', 4);
define('CODE_CHARS', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789');

// NEW: 2025 Dangerous File Extensions (from latest security research)
$dangerous_exts = [
    // Executables
    'exe', 'msi', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar', 'war', 'ear',
    // Scripts
    'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'inc', 'pl', 'py', 'rb', 'sh', 'asp', 'aspx', 'jsp',
    // Office Macros (2025 high-risk)
    'docm', 'xlsm', 'pptm', 'dotm', 'xltm', 'potm',
    // Archives with exploits
    'iso', 'img', 'bin', 'cue', 'mdf', 'mds',
    // New 2025 threats
    'wasm', 'dll', 'sys', 'cpl', 'htaccess', 'htpasswd', 'ps1', 'psm1'
];
define('DANGEROUS_EXTS', $dangerous_exts);

// Safe MIME types (double-check)
define('SAFE_MIMES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml',
    'application/pdf',
    'text/plain',
    'application/zip', 'application/x-rar-compressed',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
]);

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    foreach (parse_ini_file(__DIR__ . '/.env') as $key => $value) {
        $_ENV[$key] = $value;
    }
}
define('ADMIN_PASSWORD', $_ENV['ADMIN_PASSWORD'] ?? 'admin123');

// Create directories + rates file
foreach ([DATA_DIR, UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}
if (!file_exists(RATES_FILE)) {
    file_put_contents(RATES_FILE, json_encode([]));
}
if (!file_exists(JSON_FILE)) {
    file_put_contents(JSON_FILE, json_encode([], JSON_PRETTY_PRINT));
}
?>