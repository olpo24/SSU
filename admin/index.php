<?php
require __DIR__ . '/../includes/bootstrap.php';
admin_required();
$title = 'Dashboard';

$stats = [
    'projects' => (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE active=1")->fetchColumn(),
    'links' => (int)$pdo->query("SELECT COUNT(*) FROM links WHERE active=1")->fetchColumn(),
    'clicks' => (int)$pdo->query("SELECT COUNT(*) FROM clicks WHERE counted=1")->fetchColumn(),
    'bots' => (int)$pdo->query("SELECT COUNT(*) FROM clicks WHERE is_bot=1")->fetchColumn(),
];
$recent = $pdo->query(
    "SELECT c.created_at, c.is_bot, c.bot_reason, l.slug, p.domain, l.title
     FROM clicks c JOIN links l ON l.id=c.link_id JOIN projects p ON p.id=c.project_id
     ORDER BY c.id DESC LIMIT 12"
)->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><div><div class="eyebrow">Übersicht</div><h1>Dashboard</h1></div><a class="button primary" href="links.php?new=1">+ Kurzlink</a></div>
<div class="stats">
<?php foreach ([['projects','Projekte'],['links','Aktive Kurzlinks'],['clicks','Gezählte Klicks'],['bots','Bot-/Scanner-Anfragen']] as $s): ?>
<div class="stat-card"><span><?=e($s[1])?></span><strong><?=number_format($stats[$s[0]],0,',','.')?></strong></div>
<?php endforeach; ?>
</div>
<section class="panel"><div class="panel-head"><h2>Letzte Zugriffe</h2><a href="links.php">Alle Kurzlinks</a></div>
<table><thead><tr><th>Zeit</th><th>Domain</th><th>Slug</th><th>Status</th></tr></thead><tbody>
<?php foreach ($recent as $row): ?>
<tr><td><?=e($row['created_at'])?></td><td><?=e($row['domain'])?></td><td><code><?=e($row['slug'])?></code></td><td><?php if($row['is_bot']): ?><span class="badge muted">Bot · <?=e($row['bot_reason'])?></span><?php else: ?><span class="badge good">Gezählt</span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
