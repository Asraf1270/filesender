<?php
// functions.php
require_once __DIR__ . '/config.php';

// ————————————————————
// CODE GENERATION
// ————————————————————
function generate_code(int $length = null): string {
    $length = $length ?? rand(CODE_LENGTH_MIN, CODE_LENGTH_MAX);
    $chars = CODE_CHARS;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function is_code_unique(string $code, array $metadata): bool {
    return !isset($metadata[$code]);
}

function generate_unique_code(array $metadata): string {
    do {
        $code = generate_code();
    } while (!is_code_unique($code, $metadata));
    return $code;
}

// ————————————————————
// METADATA
// ————————————————————
function get_metadata(): array {
    if (!file_exists(JSON_FILE)) return [];
    $json = file_get_contents(JSON_FILE);
    return json_decode($json, true) ?: [];
}

function save_metadata(array $data): void {
    file_put_contents(JSON_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ————————————————————
// CLEANUP EXPIRED ONLY (NOT USED)
// ————————————————————
function cleanup_expired_files(): void {
    $metadata = get_metadata();
    $now = new DateTime('UTC');
    $changed = false;

    foreach ($metadata as $code => $info) {
        $expires = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $info['expires_at'], new DateTimeZone('UTC'));
        if ($expires === false || $expires < $now) {
            if (isset($info['path']) && file_exists($info['path'])) {
                @unlink($info['path']);
            }
            unset($metadata[$code]);
            $changed = true;
        }
    }

    if ($changed) save_metadata($metadata);
}

// ————————————————————
// SEND FILE + DELETE AFTER
// ————————————————————
function send_file(string $path, string $name, string $mime): void {
    if (!file_exists($path) || !is_readable($path)) {
        http_response_code(404);
        echo "File not found.";
        exit;
    }

    // === CLEAR ALL BUFFERS ===
    while (ob_get_level()) {
        ob_end_clean();
    }

    // === SET HEADERS ===
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // === STREAM FILE SAFELY ===
    $handle = fopen($path, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle); // CRITICAL: Close handle
    }

    // === DELETE FILE AFTER SEND ===
    ignore_user_abort(true); // Continue even if user closes browser
    if (connection_status() == CONNECTION_NORMAL && file_exists($path)) {
        @unlink($path);
    }

    exit;
}

// ————————————————————
// RATE LIMIT
// ————————————————————
function check_rate_limit(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_' . md5($ip);
    $now = time();

    $rates = file_exists(RATES_FILE) ? json_decode(file_get_contents(RATES_FILE), true) : [];
    $rates[$key] = array_filter($rates[$key] ?? [], fn($t) => $t > $now - 60);

    if (count($rates[$key]) >= 10) {
        http_response_code(429);
        echo "<!DOCTYPE html><html><head><title>Too Many Requests</title></head><body style='text-align:center;margin:100px;font-family:system-ui;'><h2>Too Many Requests</h2><p>Wait 1 minute.</p></body></html>";
        exit;
    }

    $rates[$key][] = $now;
    file_put_contents(RATES_FILE, json_encode($rates));
}

// ————————————————————
// COUNTERS
// ————————————————————
function increment_counters(string $code, bool $success): void {
    $metadata = get_metadata();
    if (!isset($metadata[$code])) return;

    $metadata[$code]['total_attempts'] = ($metadata[$code]['total_attempts'] ?? 0) + 1;
    if (!$success) {
        $metadata[$code]['failed_attempts'] = ($metadata[$code]['failed_attempts'] ?? 0) + 1;
    }
    save_metadata($metadata);
}
?>