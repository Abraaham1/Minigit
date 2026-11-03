<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (post_str('confirm_name') !== $repo['name']) {
        $error = 'Type the repository name exactly to confirm.';
    } else {
        delete_repo($conn, $id, (int)$_SESSION['user_id']);
        unset($_SESSION['stage'][$id]);
        flash_set('ok', 'Repository ' . $repo['name'] . ' was deleted.');
        redirect('home.php');
    }
}

$refs = list_refs($conn, $id);
$count = db_row($conn, 'SELECT COUNT(*) AS n FROM commits WHERE repo_id = ?', 'i', array($id));
$blobs = db_row($conn, 'SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes FROM blobs WHERE repo_id = ?', 'i', array($id));

$pageTitle = 'Settings';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'settings', $repo['default_branch']); ?>

  <section class="panel">
    <h2>Repository details</h2>
    <dl class="facts">
      <dt>Name</dt><dd><?= e($repo['name']) ?></dd>
      <dt>Created</dt><dd><?= e(fmt_time($repo['ts'])) ?></dd>
      <dt>Default branch</dt><dd><?= e($repo['default_branch']) ?></dd>
      <dt>Branches</dt><dd><?= count($refs) ?></dd>
      <dt>Commits</dt><dd><?= (int)$count['n'] ?></dd>
      <dt>Stored file contents</dt><dd><?= (int)$blobs['n'] ?> unique files, <?= e(human_size((int)$blobs['bytes'])) ?></dd>
    </dl>
  </section>

  <section class="panel danger">
    <h2>Delete this repository</h2>
    <p>This removes all files, commits and branches permanently.</p>
    <?php if ($error !== ''): ?>
      <p class="notice notice-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="settings.php?repo=<?= $id ?>" class="form" data-confirm="Delete this repository for good?">
      <?= csrf_field() ?>
      <input type="hidden" name="repo" value="<?= $id ?>">
      <label for="confirm_name">Type <strong><?= e($repo['name']) ?></strong> to confirm</label>
      <input type="text" id="confirm_name" name="confirm_name" autocomplete="off" required>
      <button type="submit" class="btn btn-danger">Delete repository</button>
    </form>
  </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
