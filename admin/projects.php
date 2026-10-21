<?php
require __DIR__ . '/../includes/bootstrap.php';
admin_required();
$title='Projekte';
$message=null; $error=null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=$_POST['action']??'';
    if ($action==='save') {
        $id=(int)($_POST['id']??0);
        $name=trim($_POST['name']??'');
        $domain=normalize_domain($_POST['domain']??'');
        if ($name==='' || !preg_match('/^(?=.{1,255}$)([a-z0-9-]+\.)+[a-z]{2,}$/i',$domain)) $error='Bitte Name und gültige Domain angeben.';
        else {
            try {
                if($id) {
                    $pdo->prepare("UPDATE projects SET name=?, domain=?, active=?, updated_at=NOW() WHERE id=?")->execute([$name,$domain,isset($_POST['active'])?1:0,$id]);
                    $message='Projekt gespeichert.';
                } else {
                    $key=bin2hex(random_bytes(24));
                    $pdo->prepare("INSERT INTO projects(name,domain,api_key_hash,api_key_hint,active,created_at,updated_at) VALUES(?,?,?,?,1,NOW(),NOW())")
                        ->execute([$name,$domain,api_hash($key),substr($key,0,8)]);
                    $message='Projekt angelegt. API-Key: '.$key.' — jetzt kopieren, er wird später nicht erneut angezeigt.';
                }
            } catch(PDOException $e) { $error='Die Domain ist bereits registriert.'; }
        }
    }
    if($action==='reset_key') {
        $id=(int)($_POST['id']??0);
        $key=bin2hex(random_bytes(24));
        $st=$pdo->prepare('UPDATE projects SET api_key_hash=?, api_key_hint=?, updated_at=NOW() WHERE id=?');
        $st->execute([api_hash($key),substr($key,0,8),$id]);
        if($st->rowCount()) $message='Neuer API-Key (jetzt kopieren, wird nicht erneut angezeigt): '.$key;
        else $error='Projekt nicht gefunden.';
    }
    if($action==='delete') {
        $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([(int)$_POST['id']]);
        $message='Projekt gelöscht.';
    }
}
$edit=null;
if(isset($_GET['edit'])) {
    $st=$pdo->prepare('SELECT * FROM projects WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit=$st->fetch();
}
$projects=$pdo->query("SELECT p.*, (SELECT COUNT(*) FROM links l WHERE l.project_id=p.id) link_count, (SELECT COUNT(*) FROM clicks c WHERE c.project_id=p.id AND c.counted=1) click_count FROM projects p ORDER BY p.name")->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="topbar"><div><div class="eyebrow">Verwaltung</div><h1>Projekte</h1></div></div>
<?php if($message): ?><div class="alert success"><?=e($message)?></div><?php endif; ?><?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<div class="grid-2">
<section class="panel">
<h2><?= $edit ? 'Projekt bearbeiten' : 'Neues Projekt' ?></h2>
<form method="post" class="form-grid">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e((string)($edit['id']??0))?>">
<label>Name<input name="name" value="<?=e($edit['name']??'')?>" placeholder="Bricks Index" required></label>
<label>Branding-Domain<input name="domain" value="<?=e($edit['domain']??'')?>" placeholder="go.bricks-index.de" required></label>
<?php if($edit): ?><label class="check"><input type="checkbox" name="active" <?=$edit['active']?'checked':''?>> aktiv</label><?php endif; ?>
<button class="button primary">Speichern</button>
</form>
<?php if($edit): ?><p class="hint">Den API-Key und die Helper-Installation verwaltest du über <a href="project.php?id=<?=$edit['id']?>">Einrichtung öffnen</a>.</p><?php endif; ?>
</section>
<section class="panel"><div class="panel-head"><h2>Branding-Domains</h2></div>
<table><thead><tr><th>Projekt</th><th>Domain</th><th>Links</th><th>Klicks</th><th>API-Key</th><th></th></tr></thead><tbody>
<?php foreach($projects as $p): ?><tr><td><strong><?=e($p['name'])?></strong></td><td><code><?=e($p['domain'])?></code></td><td><?=$p['link_count']?></td><td><?=$p['click_count']?></td><td><code><?=e($p['api_key_hint'])?>…</code></td><td><a href="project.php?id=<?=$p['id']?>">Einrichtung</a> · <a href="?edit=<?=$p['id']?>">Bearbeiten</a></td></tr><?php endforeach; ?>
</tbody></table></section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
