<?php
declare(strict_types=1);

/*
 * Branding-domain helper.
 * Upload this file as index.php to the document root of each go.* domain.
 * Fill in the central API URL and project API key.
 */
$API_URL = 'https://short.example.com/api/resolve.php';
$API_KEY = 'PASTE_PROJECT_API_KEY_HERE';

// Temporarily enable timing diagnostics while investigating slow redirects.
// Measurements are written to the PHP error log, never shown to visitors.
$TIMING_DEBUG = false;
$timingStarted = microtime(true);
$timingId = bin2hex(random_bytes(6));

$domain = strtolower($_SERVER['HTTP_HOST'] ?? '');
$domain = preg_replace('/:\d+$/', '', $domain);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$slug = trim($path, '/');

if ($slug === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$/', $slug)) {
    http_response_code(404); exit('Not found');
}

$payload = json_encode([
    'domain' => $domain,
    'slug' => $slug,
    'api_key' => $API_KEY,
    'timing_id' => $TIMING_DEBUG ? $timingId : null,
    'timing_debug' => $TIMING_DEBUG,
]);

$ch = curl_init($API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlInfo = curl_getinfo($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($TIMING_DEBUG) {
    $helperTotalMs = (microtime(true) - $timingStarted) * 1000;
    error_log('[ShortURL timing helper] ' . json_encode([
        'id' => $timingId,
        'domain' => $domain,
        'slug' => $slug,
        'http_status' => $status,
        'dns_ms' => round(((float)($curlInfo['namelookup_time'] ?? 0)) * 1000, 2),
        'connect_ms' => round(((float)($curlInfo['connect_time'] ?? 0)) * 1000, 2),
        'tls_ms' => round(max(0, ((float)($curlInfo['appconnect_time'] ?? 0) - (float)($curlInfo['connect_time'] ?? 0))) * 1000, 2),
        'api_ttfb_ms' => round(((float)($curlInfo['starttransfer_time'] ?? 0)) * 1000, 2),
        'api_total_ms' => round(((float)($curlInfo['total_time'] ?? 0)) * 1000, 2),
        'helper_elapsed_ms' => round($helperTotalMs, 2),
        'curl_error' => $curlError !== '' ? $curlError : null,
    ], JSON_UNESCAPED_SLASHES));
}

if ($response === false) {
    http_response_code(502);
    exit('Short link service unavailable');
}

// Preserve the API's meaning: an unknown slug is a 404, not an outage.
if ($status === 404) {
    http_response_code(404);
    exit('Short link not found');
}

if ($status === 403) {
    http_response_code(500);
    exit('Short link service configuration error');
}

if ($status !== 200) {
    http_response_code(502);
    exit('Short link service unavailable');
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data['success']) || empty($data['url'])
    || !is_string($data['url'])
    || !filter_var($data['url'], FILTER_VALIDATE_URL)
    || !in_array(strtolower((string) parse_url($data['url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
    http_response_code(502);
    exit('Invalid response from short link service');
}

header('Cache-Control: no-store');
header('Location: ' . $data['url'], true, 302);
exit;
