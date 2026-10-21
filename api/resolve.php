<?php
declare(strict_types=1);
$requestStarted = microtime(true);
require __DIR__ . '/../includes/bootstrap.php';
$bootstrapFinished = microtime(true);

$timingId = '';
$timingDebug = false;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'method_not_allowed']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$domain = normalize_domain((string)($body['domain'] ?? ''));
$slug = trim((string)($body['slug'] ?? ''));
$key = trim((string)($body['api_key'] ?? ''));
$timingId = (string)($body['timing_id'] ?? '');
$timingDebug = !empty($body['timing_debug']) && preg_match('/^[a-f0-9]{12}$/', $timingId) === 1;

if ($domain === '' || !valid_slug($slug) || $key === '') {
    http_response_code(400); echo json_encode(['error'=>'invalid_request']); exit;
}

$lookupStarted = microtime(true);
// Load the project and matching link in one query to avoid an extra DB roundtrip.
$st = $pdo->prepare(
    "SELECT p.id AS project_id, p.api_key_hash, l.id AS link_id, l.target_url
     FROM projects p
     LEFT JOIN links l ON l.project_id = p.id AND l.slug = ? AND l.active = 1
     WHERE p.domain = ? AND p.active = 1
     LIMIT 1"
);
$st->execute([$slug, $domain]);
$row = $st->fetch();

if (!$row || !hash_equals($row['api_key_hash'], api_hash($key))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
if (empty($row['link_id'])) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$projectId = (int) $row['project_id'];
$linkId = (int) $row['link_id'];
$targetUrl = (string) $row['target_url'];
$lookupFinished = microtime(true);

$trackingStarted = microtime(true);
[$isBot, $reason] = bot_check($_SERVER['HTTP_USER_AGENT'] ?? '');
$ipHash = client_ip_hash();

/*
 * Tracking must not take down an otherwise valid redirect once the target URL
 * has been resolved. The named lock only serializes the same link + IP pair.
 * If tracking fails, log a minimal diagnostic and still return the validated
 * target URL; do not expose database details to the helper/visitor.
 */
$dedupeLockName = 'su_dedupe_' . substr(hash('sha256', $linkId . ':' . $ipHash), 0, 48);
$dedupeLockAcquired = false;
$counted = false;
$trackingRecorded = false;
$trackingError = null;

try {
    if (!$isBot) {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 1)');
        $lockStmt->execute([$dedupeLockName]);
        $dedupeLockAcquired = (int) $lockStmt->fetchColumn() === 1;

        // If the lock is unavailable, record this request as uncounted. This
        // avoids a duplicate counter increment while preserving the redirect.
        if ($dedupeLockAcquired) {
            $counted = !is_rate_limited($pdo, $linkId, $ipHash);
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO clicks(link_id,project_id,created_at,ip_hash,user_agent,referrer,is_bot,bot_reason,counted) VALUES(?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $linkId,
            $projectId,
            now(),
            $ipHash,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000),
            substr($_SERVER['HTTP_REFERER'] ?? '', 0, 1000),
            $isBot ? 1 : 0,
            $reason,
            $counted ? 1 : 0,
        ]);
        if ($counted) {
            $pdo->prepare('UPDATE links SET clicks = clicks + 1 WHERE id = ?')->execute([$linkId]);
        }
        $pdo->commit();
        $trackingRecorded = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    $trackingError = get_class($e);
    // Do not log IP hashes, API keys, target URLs, user agents, or raw SQL
    // exception messages. The link/project IDs are internal diagnostic IDs.
    error_log('[ShortURL tracking] ' . json_encode([
        'event' => 'tracking_failed',
        'project_id' => $projectId,
        'link_id' => $linkId,
        'exception' => $trackingError,
        'code' => (string) $e->getCode(),
    ], JSON_UNESCAPED_SLASHES));
} finally {
    if ($dedupeLockAcquired) {
        try {
            $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStmt->execute([$dedupeLockName]);
        } catch (Throwable $ignored) {
            // A connection close also releases a MySQL named lock.
        }
    }
}

$trackingFinished = microtime(true);
if ($timingDebug) {
    error_log('[ShortURL timing api] ' . json_encode([
        'id' => $timingId,
        'domain' => $domain,
        'slug' => $slug,
        'bootstrap_ms' => round(($bootstrapFinished - $requestStarted) * 1000, 2),
        'lookup_ms' => round(($lookupFinished - $lookupStarted) * 1000, 2),
        'tracking_ms' => round(($trackingFinished - $trackingStarted) * 1000, 2),
        'tracking_recorded' => $trackingRecorded,
        'api_total_ms' => round(($trackingFinished - $requestStarted) * 1000, 2),
    ], JSON_UNESCAPED_SLASHES));
}

// A valid target has already been found and validated by the manager query.
// Extra metadata is backward-compatible; older helpers use only success/url.
echo json_encode([
    'success' => true,
    'url' => $targetUrl,
    'tracking_recorded' => $trackingRecorded,
], JSON_UNESCAPED_SLASHES);
