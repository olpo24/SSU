<?php
declare(strict_types=1);

/*
 * Fail closed with a generic response when configuration loading or the database
 * connection fails. Never send PDO/connection details to visitors.
 */
try {
    $config = require __DIR__ . '/../config.php';
    if (!is_array($config)
        || empty($config['db']['host'])
        || empty($config['db']['name'])
        || !array_key_exists('user', $config['db'])
        || !array_key_exists('pass', $config['db'])
        || empty($config['app']['api_secret'])) {
        throw new RuntimeException('ShortURL configuration is incomplete.');
    }

    date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Berlin');

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db']['host'],
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ]);
} catch (Throwable $e) {
    // Log only a generic event, exception class and code. Do not log credentials,
    // DSNs, SQL statements, or the raw exception message.
    error_log('[ShortURL bootstrap] ' . json_encode([
        'event' => 'bootstrap_unavailable',
        'exception' => get_class($e),
        'code' => (string) $e->getCode(),
    ], JSON_UNESCAPED_SLASHES));

    http_response_code(503);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (str_contains($script, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'service_unavailable']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vorübergehend nicht verfügbar</title><body><h1>Vorübergehend nicht verfügbar</h1><p>Der ShortURL Manager ist momentan nicht erreichbar. Bitte versuche es später erneut.</p></body></html>';
    }
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Harden session handling. Do not trust forwarded HTTPS headers unless a trusted
    // proxy is explicitly configured; shared-hosting requests normally expose HTTPS.
    $httpsOn = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name($config['app']['session_name'] ?? 'shorturl_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $httpsOn,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

function admin_required(): void {
    if (empty($_SESSION['admin_id'])) {
        redirect('login.php');
    }
}

/** Return true when this source is temporarily blocked from logging in. */
function login_is_rate_limited(PDO $pdo, string $username): bool {
    $ipHash = hash_hmac('sha256', client_ip(), (string) ($GLOBALS['config']['app']['api_secret'] ?? ''));
    $usernameHash = hash('sha256', strtolower(trim($username)));

    // Counts are bounded to the recent window and supported by migration indexes.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_login_attempts
        WHERE ip_hash = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
    $stmt->execute([$ipHash]);
    if ((int) $stmt->fetchColumn() >= 20) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_login_attempts
        WHERE ip_hash = ? AND username_hash = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
    $stmt->execute([$ipHash, $usernameHash]);
    return (int) $stmt->fetchColumn() >= 5;
}

function login_record_failure(PDO $pdo, string $username): void {
    $ipHash = hash_hmac('sha256', client_ip(), (string) ($GLOBALS['config']['app']['api_secret'] ?? ''));
    $usernameHash = hash('sha256', strtolower(trim($username)));
    $stmt = $pdo->prepare('INSERT INTO admin_login_attempts (ip_hash, username_hash, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$ipHash, $usernameHash]);

    // Opportunistic cleanup keeps the table small without running a cleanup job.
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM admin_login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}

function login_clear_failures(PDO $pdo): void {
    $ipHash = hash_hmac('sha256', client_ip(), (string) ($GLOBALS['config']['app']['api_secret'] ?? ''));
    $stmt = $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = ?');
    $stmt->execute([$ipHash]);
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function normalize_domain(string $domain): string {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#', '', $domain);
    return rtrim($domain, '/');
}

function valid_slug(string $slug): bool {
    return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$/', $slug);
}

function valid_target(string $url): bool {
    return (bool) filter_var($url, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}

function api_hash(string $key): string {
    global $config;
    return hash_hmac('sha256', $key, $config['app']['api_secret']);
}

function bot_check(string $ua): array {
    $ua = trim($ua);
    if ($ua === '') return [true, 'missing-user-agent'];

    $patterns = [
        'googlebot','bingbot','bingpreview','yandexbot','duckduckbot','baiduspider',
        'facebookexternalhit','facebot','twitterbot','linkedinbot','slackbot',
        'discordbot','telegrambot','whatsapp','pinterestbot','applebot',
        'ahrefsbot','semrushbot','mj12bot','dotbot','petalbot','bytespider',
        'sogou','exabot','seznambot','rogerbot','megaindex','serpstatbot',
        'dataforseo','crawler','spider','bot','headlesschrome','phantomjs',
        'python-requests','python-urllib','curl/','wget/','go-http-client',
        'java/','libwww-perl','okhttp','scrapy','httpclient'
    ];

    $lower = strtolower($ua);
    foreach ($patterns as $pattern) {
        if (str_contains($lower, $pattern)) {
            return [true, $pattern];
        }
    }

    return [false, null];
}

function client_ip(): string {
    // Do not trust X-Forwarded-For by default. Configure trusted proxies separately if needed.
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function client_ip_hash(): string {
    global $config;
    $ip = client_ip();
    return hash_hmac('sha256', $ip, $config['app']['api_secret']);
}

function is_rate_limited(PDO $pdo, int $linkId, string $ipHash): bool {
    // An existence check is cheaper than COUNT(*), especially on busy links.
    $stmt = $pdo->prepare(
        "SELECT 1 FROM clicks
         WHERE link_id = ? AND ip_hash = ? AND counted = 1
           AND created_at >= (NOW() - INTERVAL 30 SECOND)
         LIMIT 1"
    );
    $stmt->execute([$linkId, $ipHash]);
    return $stmt->fetchColumn() !== false;
}
