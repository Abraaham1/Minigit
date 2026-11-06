<?php
// Opens the HTML page. Set $pageTitle and optionally $bodyClass before including.
require_once __DIR__ . '/auth.php';
$pageTitle = isset($pageTitle) ? $pageTitle : APP_NAME;
$bodyClass = isset($bodyClass) ? $bodyClass : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
  <script>
    // Apply the saved theme before the page paints, so there is no light flash. Dark is the default.
    (function () {
      var t = 'dark';
      try { t = localStorage.getItem('minigit-theme') || 'dark'; } catch (e) {}
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
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
      <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch between dark and light mode" title="Switch theme">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>
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
<?php else: ?>
  <button type="button" class="theme-toggle floating" data-theme-toggle aria-label="Switch between dark and light mode" title="Switch theme">
    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
  </button>
<?php endif; ?>
