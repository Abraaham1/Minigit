<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];
$refs = list_refs($conn, $id);

$branch = get_str('branch');
if (count($refs) == 0) {
    $branch = $repo['default_branch'];
} elseif (!isset($refs[$branch])) {
    stop_with_message(404, 'Not found', 'Choose a branch before adding files.');
}
$dir = clean_path(get_str('dir'));
if ($dir === false) {
    $dir = '';
}

$pageTitle = 'New file';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'code', $branch); ?>
  <h2>Create a new file on <span class="branch-pill"><?= e($branch) ?></span></h2>

  <form method="post" action="stage.php" class="form edit-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="put">
    <input type="hidden" name="repo" value="<?= $id ?>">
    <input type="hidden" name="branch" value="<?= e($branch) ?>">

    <label for="path">File name <span class="hint">use / to put it in a folder, for example src/app.js</span></label>
    <input type="text" id="path" name="path" value="<?= e($dir === '' ? '' : $dir . '/') ?>" maxlength="1000" required autofocus>

    <label for="content">Content</label>
    <textarea id="content" name="content" class="editor" spellcheck="false" wrap="off" rows="20"></textarea>

    <p class="actions">
      <button type="submit" class="btn btn-primary">Review changes</button>
      <a class="btn btn-quiet" href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $branch, 'path' => $dir))) ?>">Cancel</a>
    </p>
  </form>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
