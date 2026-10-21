<?php
$appName = $config['app']['name'] ?? 'ShortURL Manager';
?><?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? $appName) ?> · <?= e($appName) ?></title>
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
    <div class="brand"><span class="brand-mark">↗</span><span><?= e($appName) ?></span></div>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="projects.php">Projekte</a>
        <a href="links.php">Kurzlinks</a>
        <a href="users.php">Benutzer</a>
        <form method="post" action="logout.php" class="logout-nav-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-nav-button">Abmelden</button></form>
    </nav>
</aside>
<main class="main">
