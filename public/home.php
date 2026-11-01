<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

require_login();   // not logged in? -> login.php

// Read the account from the database instead of trusting only the session copy
$stmt = $conn->prepare('SELECT name, email, created_at FROM users WHERE id = ?');
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // The account no longer exists: end the session
    $_SESSION = [];
    session_destroy();
    redirect('login.php');
}

$firstName = explode(' ', $user['name'])[0];
$joined    = date('j F Y', strtotime($user['created_at']));

$pageTitle = 'Home';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <h1>Welcome back, <?= e($firstName) ?></h1>

  <div class="home-grid">
    <section class="panel">
      <div class="panel-head">
        <h2>Your repositories</h2>
        <button type="button" class="btn btn-primary" disabled title="Available in the next step">New repository</button>
      </div>
      <div class="empty">
        <p class="empty-title">No repositories yet</p>
        <p>Repositories you create will be listed here, with their latest commit.</p>
      </div>
    </section>

    <aside class="panel">
      <h2>Your account</h2>
      <dl class="facts">
        <dt>Name</dt>
        <dd><?= e($user['name']) ?></dd>
        <dt>Email</dt>
        <dd><?= e($user['email']) ?></dd>
        <dt>Member since</dt>
        <dd><?= e($joined) ?></dd>
      </dl>
    </aside>
  </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
