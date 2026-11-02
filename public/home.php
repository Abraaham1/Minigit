<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();   // not logged in? -> login.php

// Read the account from the database instead of trusting only the session copy
$user = db_row($conn, 'SELECT name, email, UNIX_TIMESTAMP(created_at) AS ts FROM users WHERE id = ?',
    'i', array($_SESSION['user_id']));
if (!$user) {
    // The account no longer exists: end the session
    $_SESSION = array();
    session_destroy();
    redirect('login.php');
}

$repos = db_rows($conn,
    'SELECT id, name, description, default_branch FROM repos WHERE owner_id = ? ORDER BY created_at DESC, id DESC',
    'i', array($_SESSION['user_id']));

// Latest commit on the default branch of each repository
foreach ($repos as $i => $r) {
    $repos[$i]['latest'] = null;
    $head = get_ref($conn, (int)$r['id'], $r['default_branch']);
    if ($head !== null) {
        $repos[$i]['latest'] = read_commit($conn, (int)$r['id'], $head);
    }
}

$firstName = explode(' ', $user['name']);
$firstName = $firstName[0];

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
        <a class="btn btn-primary" href="repo_new.php">New repository</a>
      </div>

      <?php if (count($repos) == 0): ?>
        <div class="empty">
          <p class="empty-title">No repositories yet</p>
          <p>Create your first repository, then add files and commit them from the browser.</p>
        </div>
      <?php else: ?>
        <ul class="repo-list">
          <?php foreach ($repos as $r): ?>
            <li>
              <a class="repo-name" href="<?= e(url_to('repo.php', array('repo' => $r['id']))) ?>"><?= e($r['name']) ?></a>
              <?php if ($r['description'] !== null && $r['description'] !== ''): ?>
                <p class="repo-sub"><?= e($r['description']) ?></p>
              <?php endif; ?>
              <p class="repo-meta">
                <?php if ($r['latest']): ?>
                  Latest commit: <a href="<?= e(url_to('commit.php', array('repo' => $r['id'], 'hash' => $r['latest']['hash']))) ?>"><?= e($r['latest']['message']) ?></a>
                  on <?= e($r['default_branch']) ?>, <?= e(fmt_time($r['latest']['ts'])) ?>
                <?php else: ?>
                  Empty repository, no commits yet
                <?php endif; ?>
              </p>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <aside class="panel">
      <h2>Your account</h2>
      <dl class="facts">
        <dt>Name</dt>
        <dd><?= e($user['name']) ?></dd>
        <dt>Email</dt>
        <dd><?= e($user['email']) ?></dd>
        <dt>Member since</dt>
        <dd><?= e(date('j F Y', (int)$user['ts'])) ?></dd>
        <dt>Repositories</dt>
        <dd><?= count($repos) ?></dd>
      </dl>
    </aside>
  </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
