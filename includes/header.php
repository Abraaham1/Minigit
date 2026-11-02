<?php
// Opens the HTML page. Set $pageTitle and optionally $bodyClass before including.
require_once __DIR__ . '/auth.php';
$pageTitle = isset($pageTitle) ? $pageTitle : APP_NAME;
$bodyClass = isset($bodyClass) ? $bodyClass : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
<?php if (!empty($extraHead)) { echo $extraHead; } ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php if (is_logged_in()): ?>
  <header class="topbar">
    <a class="brand" href="home.php"><?= e(APP_NAME) ?></a>
    <nav class="topnav">
      <a href="home.php">Repositories</a>
      <a href="repo_new.php">New repository</a>
      <span class="who"><?= e(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '') ?></span>
      <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-quiet">Log out</button>
      </form>
    </nav>
  </header>
<?php
    $flashMsg = flash_get();
    if ($flashMsg) {
        $cls = ($flashMsg['type'] === 'error') ? 'notice-error' : 'notice-ok';
        echo '<div class="flash"><p class="notice ' . $cls . '" role="status">' . e($flashMsg['message']) . '</p></div>';
    }
?>
<?php endif; ?>
