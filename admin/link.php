<?php
require __DIR__ . '/../includes/bootstrap.php';
admin_required();

$linkId = (int)($_GET['id'] ?? 0);
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;

$stmt = $pdo->prepare(
    "SELECT l.*, p.name AS project_name, p.domain
     FROM links l JOIN projects p ON p.id = l.project_id
     WHERE l.id = ? LIMIT 1"
);
$stmt->execute([$linkId]);
$link = $stmt->fetch();
if (!$link) {
    http_response_code(404);
    exit('Kurzlink nicht gefunden.');
}

$title = 'Statistik · ' . ($link['title'] ?: $link['slug']);
$from = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
$to = date('Y-m-d 23:59:59');

$summaryStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS requests,
        SUM(CASE WHEN is_bot = 0 AND counted = 1 THEN 1 ELSE 0 END) AS real_clicks,
        SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) AS bot_requests,
        SUM(CASE WHEN is_bot = 0 AND counted = 0 THEN 1 ELSE 0 END) AS duplicate_requests
     FROM clicks
     WHERE link_id = ? AND created_at BETWEEN ? AND ?"
);
$summaryStmt->execute([$linkId, $from, $to]);
$summary = $summaryStmt->fetch() ?: [];

$dailyStmt = $pdo->prepare(
    "SELECT DATE(created_at) AS day,
            SUM(CASE WHEN is_bot = 0 AND counted = 1 THEN 1 ELSE 0 END) AS real_clicks,
            SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) AS bot_requests
     FROM clicks
     WHERE link_id = ? AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at)
     ORDER BY day ASC"
);
$dailyStmt->execute([$linkId, $from, $to]);
$dailyRaw = $dailyStmt->fetchAll();
$daily = [];
$cursor = new DateTimeImmutable(substr($from, 0, 10));
$end = new DateTimeImmutable(substr($to, 0, 10));
$rawByDay = [];
foreach ($dailyRaw as $row) $rawByDay[$row['day']] = $row;
while ($cursor <= $end) {
    $day = $cursor->format('Y-m-d');
    $daily[] = [
        'day' => $day,
        'real_clicks' => (int)($rawByDay[$day]['real_clicks'] ?? 0),
        'bot_requests' => (int)($rawByDay[$day]['bot_requests'] ?? 0),
    ];
    $cursor = $cursor->modify('+1 day');
}
$maxDay = 1;
foreach ($daily as $row) $maxDay = max($maxDay, $row['real_clicks'], $row['bot_requests']);

$botStmt = $pdo->prepare(
    "SELECT COALESCE(bot_reason, 'unbekannt') AS reason, COUNT(*) AS total
     FROM clicks
     WHERE link_id = ? AND is_bot = 1 AND created_at BETWEEN ? AND ?
     GROUP BY bot_reason ORDER BY total DESC LIMIT 10"
);
$botStmt->execute([$linkId, $from, $to]);
$botReasons = $botStmt->fetchAll();

$refStmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(referrer, ''), '(Direkt / unbekannt)') AS referrer, COUNT(*) AS total
     FROM clicks
     WHERE link_id = ? AND is_bot = 0 AND counted = 1 AND created_at BETWEEN ? AND ?
     GROUP BY referrer ORDER BY total DESC LIMIT 10"
);
$refStmt->execute([$linkId, $from, $to]);
$referrers = $refStmt->fetchAll();

$recentStmt = $pdo->prepare(
    "SELECT created_at, is_bot, counted, bot_reason, referrer, user_agent
     FROM clicks WHERE link_id = ? AND created_at BETWEEN ? AND ?
     ORDER BY id DESC LIMIT 30"
);
$recentStmt->execute([$linkId, $from, $to]);
$recent = $recentStmt->fetchAll();

$shortUrl = 'https://' . $link['domain'] . '/' . $link['slug'];
include __DIR__ . '/../includes/header.php';
?>
<div class="topbar">
    <div>
        <div class="eyebrow"><a href="links.php">Kurzlinks</a> / Statistik</div>
        <h1><?=e($link['title'] ?: $link['slug'])?></h1>
        <div class="detail-url"><a href="<?=e($shortUrl)?>" target="_blank" rel="noopener"><?=e($shortUrl)?></a> <span>→</span> <?=e($link['target_url'])?></div>
    </div>
    <div class="topbar-actions">
        <a class="button" href="links.php?edit=<?=$link['id']?>">Bearbeiten</a>
    </div>
</div>

<section class="range-tabs">
    <?php foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '1 Jahr'] as $value => $label): ?>
        <a class="<?= $days === $value ? 'active' : '' ?>" href="?id=<?=$link['id']?>&days=<?=$value?>"><?=e($label)?></a>
    <?php endforeach; ?>
</section>

<div class="stats stats-4">
    <div class="stat-card"><span>Echte Klicks</span><strong><?=number_format((int)$summary['real_clicks'],0,',','.')?></strong><small>gezählt</small></div>
    <div class="stat-card"><span>Bot / Scanner</span><strong><?=number_format((int)$summary['bot_requests'],0,',','.')?></strong><small>nicht gezählt</small></div>
    <div class="stat-card"><span>Wiederholungen</span><strong><?=number_format((int)$summary['duplicate_requests'],0,',','.')?></strong><small>innerhalb 30 Sek.</small></div>
    <div class="stat-card"><span>Anfragen gesamt</span><strong><?=number_format((int)$summary['requests'],0,',','.')?></strong><small>im gewählten Zeitraum</small></div>
</div>

<section class="panel">
    <div class="panel-head"><div><h2>Klickverlauf</h2><p class="hint">Echte Klicks und erkannte Bot-/Scanner-Anfragen pro Tag.</p></div></div>
    <div class="chart" aria-label="Klickverlauf">
        <?php foreach ($daily as $row): ?>
            <?php $realHeight = $row['real_clicks'] ? max(4, round(($row['real_clicks'] / $maxDay) * 100)) : 0; $botHeight = $row['bot_requests'] ? max(4, round(($row['bot_requests'] / $maxDay) * 100)) : 0; ?>
            <div class="chart-day" title="<?=e($row['day'])?>: <?=$row['real_clicks']?> echte, <?=$row['bot_requests']?> Bots">
                <div class="chart-bars"><i class="bar real" style="height:<?=$realHeight?>%"></i><i class="bar bot" style="height:<?=$botHeight?>%"></i></div>
                <small><?=date('d.m', strtotime($row['day']))?></small>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="chart-legend"><span><i class="legend-dot real"></i>Echte Klicks</span><span><i class="legend-dot bot"></i>Bot / Scanner</span></div>
</section>

<div class="grid-2">
<section class="panel"><div class="panel-head"><div><h2>Bot-/Scanner-Gründe</h2><p class="hint">Warum Anfragen als nicht menschlich erkannt wurden.</p></div></div>
<?php if (!$botReasons): ?><p class="hint">Keine Bot-Anfragen im Zeitraum.</p><?php else: ?><div class="metric-list"><?php foreach ($botReasons as $row): ?><div class="metric-row"><span><?=e($row['reason'])?></span><strong><?=number_format((int)$row['total'],0,',','.')?></strong></div><?php endforeach; ?></div><?php endif; ?>
</section>
<section class="panel"><div class="panel-head"><div><h2>Referrer</h2><p class="hint">Nur gezählte echte Klicks.</p></div></div>
<?php if (!$referrers): ?><p class="hint">Keine Referrer-Daten vorhanden.</p><?php else: ?><div class="metric-list"><?php foreach ($referrers as $row): ?><div class="metric-row"><span class="truncate-wide"><?=e($row['referrer'])?></span><strong><?=number_format((int)$row['total'],0,',','.')?></strong></div><?php endforeach; ?></div><?php endif; ?>
</section>
</div>

<section class="panel"><div class="panel-head"><div><h2>Letzte Anfragen</h2><p class="hint">Die letzten 30 gespeicherten Zugriffe innerhalb des Zeitraums.</p></div></div>
<table><thead><tr><th>Zeit</th><th>Status</th><th>Referrer</th><th>User-Agent</th></tr></thead><tbody>
<?php foreach ($recent as $row): ?>
<tr>
<td><?=e($row['created_at'])?></td>
<td><?php if ($row['is_bot']): ?><span class="badge muted">Bot · <?=e($row['bot_reason'])?></span><?php elseif (!$row['counted']): ?><span class="badge muted">Wiederholung</span><?php else: ?><span class="badge good">Gezählt</span><?php endif; ?></td>
<td class="truncate-wide"><?=e($row['referrer'] ?: 'Direkt / unbekannt')?></td>
<td class="truncate-wide" title="<?=e($row['user_agent'])?>"><?=e($row['user_agent'] ?: '—')?></td>
</tr>
<?php endforeach; ?>
</tbody></table></section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
