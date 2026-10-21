<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
admin_required();
$title = 'Benutzer';

$errors = [];
$success = null;
$currentId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
            $errors[] = 'Der Benutzername darf 3–80 Zeichen enthalten und nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich verwenden.';
        }
        if (strlen($password) < 12) {
            $errors[] = 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Die beiden Passwörter stimmen nicht überein.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), now()]);
                $success = 'Benutzer wurde angelegt.';
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'Dieser Benutzername existiert bereits.';
                } else {
                    throw $e;
                }
            }
        }
    }

    if ($action === 'change_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if ($userId < 1) {
            $errors[] = 'Ungültiger Benutzer.';
        }
        if (strlen($password) < 12) {
            $errors[] = 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Die beiden Passwörter stimmen nicht überein.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            $success = 'Passwort wurde geändert.';
        }
    }

    if ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $currentId) {
            $errors[] = 'Du kannst deinen aktuell verwendeten Benutzer nicht löschen.';
        } else {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            if ($count <= 1) {
                $errors[] = 'Der letzte Benutzer kann nicht gelöscht werden.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM admin_users WHERE id = ?');
                $stmt->execute([$userId]);
                $success = $stmt->rowCount() ? 'Benutzer wurde gelöscht.' : 'Benutzer wurde nicht gefunden.';
            }
        }
    }
}

$users = $pdo->query('SELECT id, username, created_at, last_login FROM admin_users ORDER BY username ASC')->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="topbar">
    <div><div class="eyebrow">Administration</div><h1>Benutzer</h1></div>
</div>

<?php if ($success): ?><div class="alert success"><?=e($success)?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?=e($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head"><h2>Neuen Benutzer anlegen</h2></div>
        <p class="hint">Passwörter werden mit PHPs sicherem Passwort-Hashing gespeichert. Mindestlänge: 12 Zeichen.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="create">
            <label>Benutzername<input name="username" maxlength="80" autocomplete="username" required></label>
            <label>Passwort<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
            <label>Passwort wiederholen<input type="password" name="password_confirm" minlength="12" autocomplete="new-password" required></label>
            <button class="button primary">Benutzer anlegen</button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Vorhandene Benutzer</h2></div>
        <table>
            <thead><tr><th>Benutzer</th><th>Erstellt</th><th>Letzter Login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?=e($user['username'])?></strong><?php if ((int)$user['id'] === $currentId): ?><small>Aktueller Benutzer</small><?php endif; ?></td>
                    <td><?=e($user['created_at'])?></td>
                    <td><?=e($user['last_login'] ?: 'Noch nie')?></td>
                    <td><details><summary class="button small-button">Passwort</summary>
                        <form method="post" class="inline-form password-form">
                            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                            <input type="hidden" name="action" value="change_password">
                            <input type="hidden" name="user_id" value="<?=e((string)$user['id'])?>">
                            <input type="password" name="password" minlength="12" placeholder="Neues Passwort" autocomplete="new-password" required>
                            <input type="password" name="password_confirm" minlength="12" placeholder="Wiederholen" autocomplete="new-password" required>
                            <button class="button primary small-button">Speichern</button>
                        </form>
                    </details>
                    <?php if ((int)$user['id'] !== $currentId && count($users) > 1): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Diesen Benutzer wirklich löschen?');">
                            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?=e((string)$user['id'])?>">
                            <button class="button danger small-button" type="submit">Löschen</button>
                        </form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
