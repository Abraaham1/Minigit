<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];
$refs = list_refs($conn, $id);

$from = get_str('from');
$to = get_str('to');
$error = '';
$diff = null;

if ($from !== '' && $to !== '') {
    $a = resolve_ref($conn, $id, $from);
    $b = resolve_ref($conn, $id, $to);
    if (!$a || !$b) {
        $error = 'Both sides must be a branch name or a commit hash from this repository.';
    } else {
        $diff = diff_commits($conn, $id, $a['hash'], $b['hash']);
    }
}

$pageTitle = 'Compare';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'compare', $repo['default_branch']); ?>
  <h2>Compare two versions</h2>
  <p class="sub">Choose a branch name or paste a commit hash on each side. The result shows what changed to get from the first to the second.</p>

  <form method="get" action="compare.php" class="compare-form">
    <input type="hidden" name="repo" value="<?= $id ?>">
    <label for="from">From</label>
    <input type="text" id="from" name="from" list="ref-options" value="<?= e($from) ?>" required>
    <label for="to">To</label>
    <input type="text" id="to" name="to" list="ref-options" value="<?= e($to) ?>" required>
    <datalist id="ref-options">
      <?php foreach ($refs as $name => $h): ?><option value="<?= e($name) ?>"><?php endforeach; ?>
    </datalist>
    <button type="submit" class="btn btn-primary">Compare</button>
  </form>

  <?php if ($error !== ''): ?>
    <p class="notice notice-error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <?php if ($diff !== null): ?>
    <p class="summary">
      <strong><?= count($diff['files']) ?></strong> <?= count($diff['files']) == 1 ? 'file' : 'files' ?> changed,
      <span class="c-add">+<?= (int)$diff['added'] ?></span> <span class="c-del">-<?= (int)$diff['removed'] ?></span>
    </p>
    <?php render_file_diffs($diff['files'], false); ?>
    <?php if (count($diff['files']) == 0): ?>
      <p class="diff-note box">These two versions have identical files.</p>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
