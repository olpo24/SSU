<?php
require __DIR__ . '/../includes/bootstrap.php';
admin_required();
$title = 'Kurzlinks';
$message = null;
$error = null;

function slug_base(string $value): string {
    $value = trim($value);
    $value = strtr($value, [
        'Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ẞ'=>'ss','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss',
        'À'=>'A','Á'=>'A','Â'=>'A','È'=>'E','É'=>'E','Ê'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I',
        'Ò'=>'O','Ó'=>'O','Ô'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','à'=>'a','á'=>'a','â'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ì'=>'i','í'=>'i','î'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ù'=>'u','ú'=>'u','û'=>'u'
    ]);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    $value = trim($value, '-_');
    return substr($value, 0, 80);
}

function unique_slug(PDO $pdo, int $projectId, string $base, int $excludeId = 0): string {
    if ($base === '') $base = 'link';
    $candidate = $base;
    $n = 2;
    while (true) {
        $sql = 'SELECT id FROM links WHERE project_id = ? AND slug = ?';
        $args = [$projectId, $candidate];
        if ($excludeId > 0) { $sql .= ' AND id <> ?'; $args[] = $excludeId; }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);
        if (!$stmt->fetchColumn()) return $candidate;
        $suffix = '-' . $n++;
        $candidate = substr($base, 0, 100 - strlen($suffix)) . $suffix;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $project = (int)($_POST['project_id'] ?? 0);
        $slug = trim((string)($_POST['slug'] ?? ''));
        $target = trim((string)($_POST['target_url'] ?? ''));
        $titleVal = trim((string)($_POST['title'] ?? ''));

        if (!$project || !valid_target($target)) {
            $error = 'Bitte ein Projekt auswählen und eine gültige HTTP(S)-Ziel-URL eingeben.';
        } else {
            $projectStmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? LIMIT 1');
            $projectStmt->execute([$project]);
            if (!$projectStmt->fetchColumn()) {
                $error = 'Das ausgewählte Projekt wurde nicht gefunden.';
            } else {
                if ($slug === '') {
                    $path = (string)(parse_url($target, PHP_URL_PATH) ?? '');
                    $source = $titleVal !== '' ? $titleVal : basename(rtrim($path, '/'));
                    $slug = slug_base($source);
                    if ($slug === '') $slug = 'link-' . substr(bin2hex(random_bytes(4)), 0, 8);
                    $slug = unique_slug($pdo, $project, $slug, $id);
                }
                if (!valid_slug($slug)) {
                    $error = 'Der Kurzcode darf nur Buchstaben, Zahlen, Bindestriche und Unterstriche enthalten.';
                } else {
                    try {
                        if ($id) {
                            $stmt = $pdo->prepare('UPDATE links SET project_id=?, slug=?, title=?, target_url=?, active=?, updated_at=NOW() WHERE id=?');
                            $stmt->execute([$project, $slug, $titleVal ?: null, $target, isset($_POST['active']) ? 1 : 0, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO links(project_id,slug,title,target_url,active,created_at,updated_at) VALUES(?,?,?,?,1,NOW(),NOW())');
                            $stmt->execute([$project, $slug, $titleVal ?: null, $target]);
                        }
                        $message = 'Kurzlink gespeichert: ' . $slug;
                    } catch (PDOException $e) {
                        if ((string)$e->getCode() === '23000') $error = 'Dieser Kurzcode existiert in diesem Projekt bereits. Bitte wähle einen anderen.';
                        else throw $e;
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM links WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'Kurzlink gelöscht.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE links SET active = IF(active = 1, 0, 1), updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        $message = 'Status aktualisiert.';
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM links WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$projects = $pdo->query('SELECT id,name,domain,active FROM projects ORDER BY name')->fetchAll();

$q = trim((string)($_GET['q'] ?? ''));
$filterProject = (int)($_GET['project_id'] ?? 0);
$filterStatus = (string)($_GET['status'] ?? 'all');
$sql = "SELECT l.*,p.name AS project_name,p.domain,
        (SELECT COUNT(*) FROM clicks c WHERE c.link_id=l.id AND c.counted=1 AND c.is_bot=0) AS counted_clicks,
        (SELECT COUNT(*) FROM clicks c WHERE c.link_id=l.id AND c.is_bot=1) AS bot_clicks
        FROM links l JOIN projects p ON p.id=l.project_id WHERE 1=1";
$args = [];
if ($q !== '') {
    $sql .= ' AND (l.slug LIKE ? OR l.title LIKE ? OR l.target_url LIKE ? OR p.name LIKE ? OR p.domain LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
if ($filterProject > 0) { $sql .= ' AND l.project_id = ?'; $args[] = $filterProject; }
if ($filterStatus === 'active') $sql .= ' AND l.active = 1';
elseif ($filterStatus === 'inactive') $sql .= ' AND l.active = 0';
$sql .= ' ORDER BY l.id DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$links = $stmt->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><div><div class="eyebrow">Verwaltung</div><h1>Kurzlinks</h1><p class="hint">Links verwalten, kopieren und ihre Ziel-URLs prüfen.</p></div></div>
<?php if ($message): ?><div class="alert success"><?=e($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>

<section class="panel">
<h2><?=$edit ? 'Kurzlink bearbeiten' : 'Neuen Kurzlink erstellen'?></h2>
<p class="hint">Du kannst den Kurzcode leer lassen – er wird aus Titel oder Ziel-URL erzeugt. Die Ziel-URL wird inklusive aller Affiliate- und Tracking-Parameter unverändert gespeichert.</p>
<form method="post" class="form-grid cols-2" id="link-form">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e((string)($edit['id'] ?? 0))?>">
<label>Projekt<select name="project_id" id="project-select" required><option value="">Bitte wählen</option><?php foreach ($projects as $p): ?><option value="<?=$p['id']?>" data-domain="<?=e($p['domain'])?>" <?=((int)($edit['project_id'] ?? 0) === (int)$p['id']) ? 'selected' : ''?>><?=e($p['name'])?> · <?=e($p['domain'])?><?=$p['active'] ? '' : ' (inaktiv)'?></option><?php endforeach; ?></select></label>
<label>Kurzcode <span class="hint">optional – automatisch erzeugen</span><input name="slug" id="slug-input" value="<?=e($edit['slug'] ?? '')?>" placeholder="z. B. sommeraktion"></label>
<label class="full">Ziel-URL<input type="url" name="target_url" id="target-input" value="<?=e($edit['target_url'] ?? '')?>" placeholder="https://example.com/produkt?affiliate_id=123&utm_source=..." required></label>
<label class="full">Titel (optional)<input name="title" id="title-input" value="<?=e($edit['title'] ?? '')?>" placeholder="Sommeraktion oder Produktname"></label>
<div class="url-preview full"><span class="hint">Vorschau Kurz-URL</span><div class="preview-line"><code id="short-preview">Projekt auswählen</code><button type="button" class="button small-button" id="copy-preview">Kurz-URL kopieren</button></div><div class="preview-line target-preview"><span class="hint">Vollständige Ziel-URL</span><button type="button" class="button small-button" id="copy-target">Ziel-URL kopieren</button><a class="button small-button" id="test-target" href="#" target="_blank" rel="noopener noreferrer">Ziel-URL testen ↗</a></div><div class="hint">„Testen“ öffnet die Ziel-URL direkt in einem neuen Tab, ohne den Kurzlink aufzurufen oder dessen Klickstatistik zu verändern.</div></div>
<?php if ($edit): ?><label class="check"><input type="checkbox" name="active" <?=$edit['active'] ? 'checked' : ''?>> Kurzlink aktiv</label><?php endif; ?>
<button class="button primary">Speichern</button>
<?php if ($edit): ?><a class="button" href="links.php">Abbrechen</a><?php endif; ?>
</form>
</section>

<section class="panel">
<div class="panel-head"><div><h2>Alle Kurzlinks</h2><p class="hint"><?=count($links)?> Treffer<?=(count($links) === 500 ? ' · Anzeige auf 500 begrenzt' : '')?></p></div><a class="button" href="links.php">Filter zurücksetzen</a></div>
<form method="get" class="filter-bar">
<label>Suche<input type="search" name="q" value="<?=e($q)?>" placeholder="Slug, Titel, URL oder Projekt …"></label>
<label>Projekt<select name="project_id"><option value="0">Alle Projekte</option><?php foreach ($projects as $p): ?><option value="<?=$p['id']?>" <?=($filterProject === (int)$p['id']) ? 'selected' : ''?>><?=e($p['name'])?></option><?php endforeach; ?></select></label>
<label>Status<select name="status"><option value="all" <?=$filterStatus === 'all' ? 'selected' : ''?>>Alle</option><option value="active" <?=$filterStatus === 'active' ? 'selected' : ''?>>Aktiv</option><option value="inactive" <?=$filterStatus === 'inactive' ? 'selected' : ''?>>Inaktiv</option></select></label>
<button class="button primary">Filtern</button>
</form>
<div class="table-wrap"><table><thead><tr><th>Kurzlink</th><th>Ziel-URL</th><th>Status</th><th>Echte Klicks</th><th>Bots</th><th>Aktionen</th></tr></thead><tbody>
<?php if (!$links): ?><tr><td colspan="6">Keine Kurzlinks für diese Filter gefunden.</td></tr><?php endif; ?>
<?php foreach ($links as $l): $shortUrl = 'https://' . $l['domain'] . '/' . $l['slug']; ?>
<tr>
<td><strong><a href="<?=e($shortUrl)?>" target="_blank" rel="noopener noreferrer"><?=e($l['domain'])?>/<?=e($l['slug'])?></a></strong><small><?=e($l['title'] ?: $l['project_name'])?></small><button type="button" class="text-action copy-button" data-copy="<?=e($shortUrl)?>">Kurz-URL kopieren</button></td>
<td class="target-cell"><span class="truncate" title="<?=e($l['target_url'])?>"><?=e($l['target_url'])?></span><div class="row-actions"><button type="button" class="text-action copy-button" data-copy="<?=e($l['target_url'])?>">Ziel kopieren</button><a class="text-action" href="<?=e($l['target_url'])?>" target="_blank" rel="noopener noreferrer">Testen ↗</a></div></td>
<td><?php if ($l['active']): ?><span class="badge good">Aktiv</span><?php else: ?><span class="badge muted">Inaktiv</span><?php endif; ?></td>
<td><span class="badge good"><?=number_format((int)$l['counted_clicks'], 0, ',', '.')?></span></td><td><span class="badge muted"><?=number_format((int)$l['bot_clicks'], 0, ',', '.')?></span></td>
<td><div class="action-stack"><a href="link.php?id=<?=$l['id']?>">Statistik</a><a href="?edit=<?=$l['id']?>">Bearbeiten</a><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$l['id']?>"><button class="text-action" type="submit"><?=$l['active'] ? 'Deaktivieren' : 'Aktivieren'?></button></form><form method="post" onsubmit="return confirm('Diesen Kurzlink und seine gespeicherten Statistiken wirklich löschen?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$l['id']?>"><button class="text-action danger-text" type="submit">Löschen</button></form></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
<script>
(() => {
  const project = document.getElementById('project-select');
  const slug = document.getElementById('slug-input');
  const title = document.getElementById('title-input');
  const target = document.getElementById('target-input');
  const preview = document.getElementById('short-preview');
  const copyPreview = document.getElementById('copy-preview');
  const copyTarget = document.getElementById('copy-target');
  const testTarget = document.getElementById('test-target');
  const existingSlug = <?=json_encode((string)($edit['slug'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
  const toSlug = value => value.toLocaleLowerCase('de-DE').replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss').replace(/[^a-z0-9_-]+/g,'-').replace(/^-+|-+$/g,'').slice(0,80);
  function currentSlug() {
    if (slug.value.trim()) return slug.value.trim();
    if (existingSlug && !title.value.trim() && !target.value.trim()) return existingSlug;
    const fromTitle = toSlug(title.value);
    if (fromTitle) return fromTitle;
    try { const path = new URL(target.value).pathname.split('/').filter(Boolean).pop() || 'link'; return toSlug(decodeURIComponent(path)) || 'link'; } catch (_) { return 'link'; }
  }
  function update() {
    const option = project.options[project.selectedIndex];
    const domain = option ? option.dataset.domain : '';
    const code = currentSlug();
    preview.textContent = domain ? 'https://' + domain + '/' + code : 'Projekt auswählen';
    const targetUrl = target.value.trim();
    testTarget.href = targetUrl && /^https?:\/\//i.test(targetUrl) ? targetUrl : '#';
    testTarget.style.pointerEvents = testTarget.href.endsWith('#') ? 'none' : 'auto';
    testTarget.style.opacity = testTarget.href.endsWith('#') ? '.45' : '1';
  }
  async function copyText(text, button) {
    if (!text) return;
    const original = button.textContent;
    try { await navigator.clipboard.writeText(text); button.textContent = 'Kopiert ✓'; }
    catch (_) { window.prompt('Bitte kopieren:', text); return; }
    window.setTimeout(() => button.textContent = original, 1400);
  }
  [project, slug, title, target].forEach(el => el.addEventListener('input', update));
  copyPreview.addEventListener('click', () => copyText(preview.textContent, copyPreview));
  copyTarget.addEventListener('click', () => copyText(target.value.trim(), copyTarget));
  document.querySelectorAll('.copy-button').forEach(button => button.addEventListener('click', () => copyText(button.dataset.copy || '', button)));
  update();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
