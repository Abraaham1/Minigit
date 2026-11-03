<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];

$hash = find_commit($conn, $id, strtolower(get_str('hash')));
$commit = ($hash !== null) ? read_commit($conn, $id, $hash) : null;
if (!$commit) {
    stop_with_message(404, 'Commit not found', 'There is no commit with that hash in this repository.');
}

$parents = parent_map($conn, $id);
$parent_list = $parents[$hash];
$first_parent = count($parent_list) > 0 ? $parent_list[0] : null;
$diff = diff_commits($conn, $id, $first_parent, $hash);

$on_branches = array();
foreach (list_refs($conn, $id) as $name => $h) {
    if ($h === $hash) {
        $on_branches[] = (string)$name;
    }
}

$pageTitle = 'Commit ' . short_hash($hash);
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'commits', $repo['default_branch']); ?>

  <section class="commit-head">
    <h2><?= e($commit['message']) ?></h2>
    <p class="muted"><?= e($commit['author_name']) ?> committed on <?= e(fmt_time($commit['ts'])) ?></p>
    <p class="commit-meta">
      <span>Commit <code><?= e($hash) ?></code></span>
      <?php foreach ($parent_list as $p): ?>
        <span>Parent <a href="<?= e(url_to('commit.php', array('repo' => $id, 'hash' => $p))) ?>"><code><?= e(short_hash($p)) ?></code></a></span>
      <?php endforeach; ?>
      <?php if (count($parent_list) == 0): ?><span>First commit</span><?php endif; ?>
      <?php foreach ($on_branches as $b): ?><span class="branch-pill"><?= e($b) ?></span><?php endforeach; ?>
      <a href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $hash))) ?>">Browse files at this commit</a>
    </p>
    <?php if (count($parent_list) > 1): ?>
      <p class="notice notice-info">This is a merge commit. The changes below are compared with its first parent.</p>
    <?php endif; ?>
  </section>

  <p class="summary">
    <strong><?= count($diff['files']) ?></strong> <?= count($diff['files']) == 1 ? 'file' : 'files' ?> changed,
    <span class="c-add">+<?= (int)$diff['added'] ?></span> <span class="c-del">-<?= (int)$diff['removed'] ?></span>
  </p>
  <?php render_file_diffs($diff['files'], false); ?>
  <?php if (count($diff['files']) == 0): ?>
    <p class="diff-note box">This commit does not change any file contents.</p>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
