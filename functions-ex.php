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
// METADATA & FILE OPS
// ————————————————————
function get_metadata(): array {
    if (!file_exists(JSON_FILE)) {
        return [];
    }
    $json = file_get_contents(JSON_FILE);
    return json_decode($json, true) ?: [];
}

function save_metadata(array $data): void {
    file_put_contents(JSON_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ————————————————————
// CLEANUP EXPIRED FILES
// ————————————————————
function cleanup_expired_files(): void {
    $metadata = get_metadata();
    $now = new DateTime('UTC');
    $changed = false;

    foreach ($metadata as $code => $info) {
        $expires = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $info['expires_at'], new DateTimeZone('UTC'));
        if ($expires < $now) {
            if (isset($info['path']) && file_exists($info['path'])) {
                @unlink($info['path']);
            }
            unset($metadata[$code]);
            $changed = true;
        }
    }

    if ($changed) {
        save_metadata($metadata);
    }
}

// ————————————————————
// SEND FILE (Download)
// ————————————————————
function send_file(string $path, string $name, string $mime): void {
    if (!file_exists($path) || !is_readable($path)) {
        http_response_code(404);
        echo "File not found or inaccessible.";
        exit;
    }

    // Clear output buffer
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('Pragma: no-cache');

    // Stream file
    $handle = fopen($path, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    }
    exit;
}

// ————————————————————
// RATE LIMITING (10 attempts/min per IP)
// ————————————————————
function check_rate_limit(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_' . md5($ip);
    $now = time();

    $rates = [];
    if (file_exists(RATES_FILE)) {
        $json = file_get_contents(RATES_FILE);
        $rates = json_decode($json, true) ?: [];
    }

    // Initialize if not exists
    if (!isset($rates[$key])) {
        $rates[$key] = [];
    }

    // Clean old attempts (>60 seconds)
    $rates[$key] = array_filter($rates[$key], fn($t) => $t > $now - 60);

    // Block if too many
    if (count($rates[$key]) >= 10) {
        http_response_code(429);
        ?>
        <!DOCTYPE html>
        <html><head><meta charset="UTF-8"><title>Too Many Requests</title>
        <style>body{font-family:system-ui;text-align:center;margin:100px auto;color:#d00}</style>
        </head><body>
        <h2>Too Many Requests</h2>
        <p>Please wait 1 minute before trying again.</p>
        </body></html>
        <?php
        exit;
    }

    // Record attempt
    $rates[$key][] = $now;
    file_put_contents(RATES_FILE, json_encode($rates));
}

// ————————————————————
// DOWNLOAD COUNTERS & ANALYTICS
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