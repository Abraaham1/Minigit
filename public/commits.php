<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];

$refs = list_refs($conn, $id);
$ref = get_str('ref');
if ($ref === '') {
    $ref = $repo['default_branch'];
}

$rows = array();
$parents = array();
if (count($refs) > 0) {
    $res = resolve_ref($conn, $id, $ref);
    if (!$res) {
        stop_with_message(404, 'Not found', 'There is no branch or commit called "' . $ref . '".');
    }
    $parents = parent_map($conn, $id);
    $set = ancestors($parents, $res['hash']);
    foreach (all_commits($conn, $id) as $hash => $c) {   // newest first
        if (isset($set[$hash])) {
            $rows[] = $c;
        }
    }
    $rows = array_slice($rows, 0, 200);
}

$pageTitle = 'Commits';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'commits', $ref); ?>

  <?php if (count($refs) == 0): ?>
    <div class="empty"><p class="empty-title">No commits yet</p><p>Add files to make the first commit.</p></div>
  <?php else: ?>
    <div class="toolbar">
      <?php branch_select('commits.php', $repo, $refs, $ref); ?>
      <span class="muted"><?= count($rows) ?> <?= count($rows) == 1 ? 'commit' : 'commits' ?> in this history</span>
    </div>

    <ul class="commit-list">
      <?php foreach ($rows as $c): ?>
        <li>
          <div>
            <a class="commit-msg" href="<?= e(url_to('commit.php', array('repo' => $id, 'hash' => $c['hash']))) ?>"><?= e($c['message']) ?></a>
            <?php if (count($parents[$c['hash']]) > 1): ?><span class="badge badge-merge">Merge</span><?php endif; ?>
            <p class="muted small"><?= e($c['author_name']) ?> committed on <?= e(fmt_time($c['ts'])) ?></p>
          </div>
          <div class="commit-side">
            <a class="hash" href="<?= e(url_to('commit.php', array('repo' => $id, 'hash' => $c['hash']))) ?>"><?= e(short_hash($c['hash'])) ?></a>
            <a class="small" href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $c['hash']))) ?>">Browse files</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
