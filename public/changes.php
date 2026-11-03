<?php
// The "Review changes" page: shows what is added, modified and deleted, then commits the selection.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];

$branch = isset($_POST['branch']) ? post_str('branch') : get_str('branch');
if ($branch === '') {
    $branch = $repo['default_branch'];
}
$stage = stage_get($id, $branch);
$error = '';
$message = '';

// ---- commit button ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stage !== null) {
    if (!csrf_valid()) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $message = post_str('message');
        $selected = array();
        if (isset($_POST['selected']) && is_array($_POST['selected'])) {
            foreach ($_POST['selected'] as $s) {
                if (is_string($s)) {
                    $selected[] = $s;
                }
            }
        }
        try {
            $hash = do_commit($conn, $repo, (int)$_SESSION['user_id'], $branch, $stage, $selected, $message);
            stage_clear($id, $branch);
            flash_set('ok', 'Changes committed to ' . $branch . '.');
            redirect(url_to('commit.php', array('repo' => $id, 'hash' => $hash)));
        } catch (UserError $ex) {
            $error = $ex->getMessage();
        }
    }
}

$head = get_ref($conn, $id, $branch);
$stale = ($stage !== null && $head !== $stage['base']);

$files = array();
$unchanged = 0;
$total_add = 0;
$total_del = 0;
if ($stage !== null) {
    $base_flat = files_at_commit($conn, $id, $stage['base']);
    list($changes, $unchanged) = build_changes($base_flat, $stage);
    foreach ($changes as $ch) {
        $old = ($ch['old'] !== null) ? read_blob($conn, $id, $ch['old']) : null;
        $f = make_file_diff($ch['path'], $ch['status'], $old, $ch['content']);
        $total_add += $f['added'];
        $total_del += $f['removed'];
        $files[] = $f;
    }
}

$pageTitle = 'Review changes';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'code', $branch); ?>
  <h2>Review changes on <span class="branch-pill"><?= e($branch) ?></span></h2>

  <?php if ($stage === null || (count($files) == 0 && $unchanged == 0)): ?>
    <div class="empty">
      <p class="empty-title">There are no changes waiting</p>
      <p>Upload files or edit a file to start a change.</p>
      <p class="actions center">
        <a class="btn btn-primary" href="<?= e(url_to('upload.php', array('repo' => $id, 'branch' => $branch))) ?>">Add files</a>
        <a class="btn btn-quiet" href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $branch))) ?>">Back to the code</a>
      </p>
    </div>
  <?php else: ?>

    <?php if ($stale): ?>
      <p class="notice notice-error" role="alert">This branch changed after you started editing, so these changes can no longer be committed. Discard them and make the change again.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <p class="notice notice-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <p class="summary">
      <strong><?= count($files) ?></strong> <?= count($files) == 1 ? 'file' : 'files' ?> changed,
      <span class="c-add">+<?= (int)$total_add ?></span> <span class="c-del">-<?= (int)$total_del ?></span>
      <?php if ($unchanged > 0): ?>
        <span class="muted">(<?= (int)$unchanged ?> uploaded <?= $unchanged == 1 ? 'file is' : 'files are' ?> identical to the project and left out)</span>
      <?php endif; ?>
    </p>

    <?php if (count($files) == 0): ?>
      <p class="diff-note box">Everything you added is already in the project. There is nothing to commit.</p>
    <?php endif; ?>

    <form method="post" action="changes.php" class="review-form">
      <?= csrf_field() ?>
      <input type="hidden" name="repo" value="<?= $id ?>">
      <input type="hidden" name="branch" value="<?= e($branch) ?>">

      <?php render_file_diffs($files, true); ?>

      <?php if (count($files) > 0): ?>
        <div class="commit-box">
          <label for="message">Commit message</label>
          <input type="text" id="message" name="message" value="<?= e($message) ?>" maxlength="2000"
                 placeholder="Describe what changed" required>
          <p class="actions">
            <button type="submit" class="btn btn-primary"<?= $stale ? ' disabled' : '' ?>>Commit changes</button>
            <span class="muted small">Only the ticked files are committed.</span>
          </p>
        </div>
      <?php endif; ?>
    </form>

    <div class="review-links">
      <a href="<?= e(url_to('upload.php', array('repo' => $id, 'branch' => $branch))) ?>">Add more files</a>
      <form method="post" action="stage.php" class="inline" data-confirm="Discard all waiting changes?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="discard">
        <input type="hidden" name="repo" value="<?= $id ?>">
        <input type="hidden" name="branch" value="<?= e($branch) ?>">
        <button type="submit" class="btn btn-danger-quiet">Discard all changes</button>
      </form>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
