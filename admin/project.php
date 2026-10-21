<?php
require __DIR__ . '/../includes/bootstrap.php';
admin_required();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); exit('Projekt nicht gefunden.'); }
$title = 'Projekt-Einrichtung';
$message = null;
$error = null;
$newKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'reset_key') {
        $newKey = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare('UPDATE projects SET api_key_hash=?, api_key_hint=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([api_hash($newKey), substr($newKey, 0, 8), $id]);
        $project['api_key_hint'] = substr($newKey, 0, 8);
        $message = 'API-Key wurde erneuert. Aktualisiere den Helper auf der Branding-Domain sofort.';
    }
}
$baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
$apiUrl = $baseUrl . '/api/resolve.php';
$helperCode = "\$API_URL = " . var_export($apiUrl, true) . ";\n\$API_KEY = " . var_export($newKey ?? 'HIER_DEN_API_KEY_EINTRAGEN', true) . ";";
include __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><div><div class="eyebrow">Projektverwaltung</div><h1>Helper einrichten</h1><p class="hint">Installationsdaten für <?=e($project['domain'])?></p></div><a class="button" href="projects.php">Zurück zu Projekten</a></div>
<?php if ($message): ?><div class="alert success"><?=e($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<?php if ($newKey): ?><section class="panel"><h2>Neuer API-Key – jetzt sichern</h2><p>Dieser Schlüssel wird nur einmal angezeigt. Der bisherige Schlüssel ist ab sofort ungültig.</p><div class="code-copy"><code id="new-key"><?=e($newKey)?></code><button type="button" class="button small-button" data-copy-target="new-key">API-Key kopieren</button></div></section><?php endif; ?>
<div class="grid-2">
<section class="panel">
<h2>Projektinformationen</h2>
<dl class="details-list"><dt>Projekt</dt><dd><?=e($project['name'])?></dd><dt>Branding-Domain</dt><dd><code><?=e($project['domain'])?></code></dd><dt>Status</dt><dd><?=$project['active'] ? '<span class="badge good">Aktiv</span>' : '<span class="badge muted">Inaktiv</span>'?></dd><dt>API-Key-Hinweis</dt><dd><code><?=e($project['api_key_hint'])?>…</code></dd><dt>API-Endpunkt</dt><dd><code><?=e($apiUrl)?></code></dd></dl>
<hr class="soft-rule"><h3>API-Key erneuern</h3><p class="hint">Nur nötig, wenn du den Schlüssel verloren hast oder er kompromittiert sein könnte. Der bisherige Helper funktioniert nach dem Reset nicht mehr.</p>
<form method="post" onsubmit="return confirm('API-Key wirklich erneuern? Der bisherige Helper funktioniert dann nicht mehr, bis du ihn aktualisierst.');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="reset_key"><button class="button primary" type="submit">Neuen API-Key erzeugen</button></form>
</section>
<section class="panel">
<h2>Helper installieren</h2>
<ol class="setup-steps"><li>Lade den Ordner <code>helper/</code> aus dem Manager-Paket herunter bzw. öffne ihn lokal.</li><li>Kopiere <code>helper/index.php</code> in den Dokumentenstamm von <code><?=e($project['domain'])?></code> und benenne sie dort in <code>index.php</code> um.</li><li>Kopiere auch <code>helper/.htaccess</code> in denselben Ordner.</li><li>Öffne die Helper-<code>index.php</code> und trage API-URL und API-Key in den beiden Konfigurationszeilen ein.</li><li>Stelle sicher, dass HTTPS aktiv ist und PHP-cURL auf dem Hosting verfügbar ist.</li></ol>
<p class="hint">Die Branding-Domain muss nicht auf den zentralen Server zeigen. Sie benötigt nur Webhosting/PHP und HTTPS; der Helper kommuniziert ausgehend mit der zentralen API.</p>
</section>
</div>
<section class="panel"><h2>Konfiguration für helper/index.php</h2><p class="hint">Diese Zeilen in der Helper-Datei anpassen. Den vollständigen API-Key findest du nur direkt nach der Erstellung oder nach einem Reset.</p><pre class="code-block" id="helper-config"><?=e($helperCode)?></pre><button type="button" class="button small-button" data-copy-target="helper-config">Konfiguration kopieren</button></section>
<section class="panel"><h2>Routing (.htaccess)</h2><p class="hint">Die mitgelieferte Datei leitet Kurzpfade wie <code>/sommeraktion</code> an den PHP-Helper weiter. Prüfe, dass Apache-Rewrite auf deinem Hosting aktiviert ist.</p><pre class="code-block">RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]</pre></section>
<script>
(() => {
  document.querySelectorAll('[data-copy-target]').forEach(button => button.addEventListener('click', async () => {
    const el = document.getElementById(button.dataset.copyTarget);
    const text = el ? (el.tagName === 'PRE' ? el.textContent : el.textContent.trim()) : '';
    try { await navigator.clipboard.writeText(text); button.textContent = 'Kopiert ✓'; }
    catch (_) { window.prompt('Bitte kopieren:', text); }
  }));
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
