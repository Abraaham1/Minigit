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

$pageTitle = 'Add files';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'code', $branch); ?>
  <h2>Add files to <span class="branch-pill"><?= e($branch) ?></span></h2>
  <p class="sub">Pick files or a whole folder. Nothing is saved yet: you review every change before committing.</p>

  <div id="upload-app" class="upload-app"
       data-repo="<?= $id ?>" data-branch="<?= e($branch) ?>" data-token="<?= e(csrf_token()) ?>"
       data-next="<?= e(url_to('changes.php', array('repo' => $id, 'branch' => $branch))) ?>">

    <div id="dropzone" class="dropzone" tabindex="0">
      <p class="dz-title">Drop files or a folder here</p>
      <p class="muted">or choose below. A chosen folder's contents go at the top of the project.</p>
      <p class="actions center">
        <label class="btn btn-quiet file-btn">Choose files
          <input type="file" id="pick-files" multiple hidden>
        </label>
        <label class="btn btn-quiet file-btn">Choose a folder
          <input type="file" id="pick-folder" webkitdirectory hidden>
        </label>
      </p>
    </div>

    <p id="upload-summary" class="upload-summary" aria-live="polite">No files selected yet.</p>
    <ul id="upload-list" class="upload-list"></ul>

    <div class="form">
      <label for="prefix">Put the files inside this folder <span class="hint">optional, for example src</span></label>
      <input type="text" id="prefix" value="<?= e($dir) ?>" maxlength="500">

      <label class="check"><input type="checkbox" id="sync">
        Also delete project files that are not in this upload</label>
      <p class="muted small">Leave this off to only add and update files. Deletions still appear in the review step, where you can untick them.</p>
    </div>

    <p id="upload-error" class="notice notice-error" role="alert" hidden></p>
    <p class="actions">
      <button type="button" id="upload-send" class="btn btn-primary" disabled>Review changes</button>
      <a class="btn btn-quiet" href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $branch, 'path' => $dir))) ?>">Cancel</a>
    </p>
  </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
