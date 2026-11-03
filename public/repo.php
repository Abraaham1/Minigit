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
$path = clean_path(get_str('path'));
if ($path === false) {
    stop_with_message(404, 'Not found', 'That path is not valid.');
}

$empty = (count($refs) == 0);
$res = null;
$commit = null;
$entries = array();
$readme = null;

if (!$empty) {
    $res = resolve_ref($conn, $id, $ref);
    if (!$res) {
        stop_with_message(404, 'Not found', 'There is no branch or commit called "' . $ref . '".');
    }
    $commit = read_commit($conn, $id, $res['hash']);
    $node = tree_at_path($conn, $id, $commit['tree_hash'], $path);
    if (!$node) {
        stop_with_message(404, 'Not found', 'That folder or file does not exist in this version.');
    }
    if ($node['type'] === 'blob') {
        redirect(url_to('file.php', array('repo' => $id, 'ref' => $ref, 'path' => $path)));
    }
    $entries = array_values(read_tree($conn, $id, $node['hash']));
    usort($entries, function ($x, $y) {
        if ($x['type'] !== $y['type']) {
            return $x['type'] === 'tree' ? -1 : 1;   // folders first
        }
        return strcasecmp($x['name'], $y['name']);
    });
    foreach ($entries as $en) {
        if ($en['type'] === 'blob' && preg_match('/^readme(\.md|\.txt)?$/i', $en['name'])) {
            if (blob_size($conn, $id, $en['object_hash']) < 200000) {
                $text = read_blob($conn, $id, $en['object_hash']);
                if (!is_binary($text)) {
                    $readme = array('name' => $en['name'], 'text' => $text);
                }
            }
            break;
        }
    }
}

$branch_for_edit = $empty ? $repo['default_branch'] : $ref;
$can_edit = $empty || ($res && $res['is_branch']);

$pageTitle = $repo['name'];
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'code', $ref); ?>

  <?php if ($empty): ?>
    <div class="empty big">
      <p class="empty-title">This repository is empty</p>
      <p>Add files from your computer, or write a file here in the browser. Your first commit creates the <strong><?= e($repo['default_branch']) ?></strong> branch.</p>
      <p class="actions">
        <a class="btn btn-primary" href="<?= e(url_to('upload.php', array('repo' => $id, 'branch' => $branch_for_edit))) ?>">Upload files or a folder</a>
        <a class="btn btn-quiet" href="<?= e(url_to('new_file.php', array('repo' => $id, 'branch' => $branch_for_edit))) ?>">Create a new file</a>
      </p>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <?php branch_select('repo.php', $repo, $refs, $ref, $path !== '' ? array('path' => $path) : array()); ?>
      <?php breadcrumbs($repo, $ref, $path); ?>
      <?php if ($can_edit): ?>
        <span class="toolbar-actions">
          <a class="btn btn-quiet" href="<?= e(url_to('new_file.php', array('repo' => $id, 'branch' => $ref, 'dir' => $path))) ?>">New file</a>
          <a class="btn btn-primary" href="<?= e(url_to('upload.php', array('repo' => $id, 'branch' => $ref, 'dir' => $path))) ?>">Add files</a>
        </span>
      <?php endif; ?>
    </div>

    <?php if (!$res['is_branch']): ?>
      <p class="notice notice-info">You are viewing commit <strong><?= e(short_hash($commit['hash'])) ?></strong>. Switch to a branch to make changes.</p>
    <?php endif; ?>

    <?php $stage = stage_get($id, $ref); ?>
    <?php if ($stage && count($stage['items']) > 0): ?>
      <p class="notice notice-info">You have changes waiting for review.
        <a href="<?= e(url_to('changes.php', array('repo' => $id, 'branch' => $ref))) ?>">Review and commit</a></p>
    <?php endif; ?>

    <div class="latest-commit">
      <span class="who-dot"><?= e($commit['author_name']) ?></span>
      <a class="commit-msg" href="<?= e(url_to('commit.php', array('repo' => $id, 'hash' => $commit['hash']))) ?>"><?= e($commit['message']) ?></a>
      <span class="muted"><?= e(fmt_time($commit['ts'])) ?></span>
      <a class="hash" href="<?= e(url_to('commit.php', array('repo' => $id, 'hash' => $commit['hash']))) ?>"><?= e(short_hash($commit['hash'])) ?></a>
    </div>

    <table class="files">
      <tbody>
        <?php if ($path !== ''): ?>
          <?php $up = (strpos($path, '/') === false) ? '' : substr($path, 0, strrpos($path, '/')); ?>
          <tr><td class="icon">..</td>
              <td colspan="2"><a href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $ref, 'path' => $up))) ?>">Up one folder</a></td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $en): ?>
          <?php $full = ($path === '' ? '' : $path . '/') . $en['name']; ?>
          <tr>
            <?php if ($en['type'] === 'tree'): ?>
              <td class="icon">DIR</td>
              <td><a href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $ref, 'path' => $full))) ?>"><?= e($en['name']) ?></a></td>
              <td class="muted right"></td>
            <?php else: ?>
              <td class="icon">FILE</td>
              <td><a href="<?= e(url_to('file.php', array('repo' => $id, 'ref' => $ref, 'path' => $full))) ?>"><?= e($en['name']) ?></a></td>
              <td class="muted right"><?= e(human_size(blob_size($conn, $id, $en['object_hash']))) ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (count($entries) == 0): ?>
          <tr><td colspan="3" class="muted">This folder is empty.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if ($readme): ?>
      <section class="readme">
        <h2><?= e($readme['name']) ?></h2>
        <pre><?= e($readme['text']) ?></pre>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
