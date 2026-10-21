<?php
require __DIR__ . '/../includes/bootstrap.php';
if (!empty($_SESSION['admin_id'])) redirect('index.php');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));

    if (login_is_rate_limited($pdo, $username)) {
        // Keep the response intentionally generic so the limit does not reveal whether
        // a username exists. The visitor can retry after the rolling 15-minute window.
        http_response_code(429);
        $error = 'Zu viele Anmeldeversuche. Bitte warte 15 Minuten und versuche es erneut.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $password = (string) ($_POST['password'] ?? '');

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION = ['admin_id' => (int) $user['id']];
            // Generate a fresh CSRF token after authentication; the anonymous token
            // must not be carried into the authenticated session.
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            login_clear_failures($pdo);
            $pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            redirect('index.php');
        }

        login_record_failure($pdo, $username);
        $error = 'Benutzername oder Passwort ist falsch.';
    }
}
$title = 'Anmelden';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?></title><link rel="stylesheet" href="../assets/admin.css"></head>
<body class="login-page">
<form class="login-card" method="post">
    <div class="brand brand-center"><span class="brand-mark">↗</span><span>ShortURL Manager</span></div>
    <h1>Anmelden</h1>
    <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <label>Benutzername<input name="username" autocomplete="username" required autofocus></label>
    <label>Passwort<input type="password" name="password" autocomplete="current-password" required></label>
    <button class="button primary">Anmelden</button>
</form>
</body></html>
