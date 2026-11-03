<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];

$ref = get_str('ref');
if ($ref === '') {
    $ref = $repo['default_branch'];
}
$path = clean_path(get_str('path'));
if ($path === false || $path === '') {
    stop_with_message(404, 'Not found', 'That path is not valid.');
}
$res = resolve_ref($conn, $id, $ref);
if (!$res) {
    stop_with_message(404, 'Not found', 'There is no branch or commit called "' . $ref . '".');
}
$commit = read_commit($conn, $id, $res['hash']);
$node = tree_at_path($conn, $id, $commit['tree_hash'], $path);
if (!$node || $node['type'] !== 'blob') {
    stop_with_message(404, 'Not found', 'That file does not exist in this version.');
}

$content = read_blob($conn, $id, $node['hash']);
$size = strlen($content);
$binary = is_binary($content);
$edit = (get_str('edit') === '1') && $res['is_branch'] && !$binary;
$filename = basename($path);

// File extension -> highlight.js language
$langs = array('js' => 'javascript', 'json' => 'json', 'php' => 'php', 'html' => 'xml', 'htm' => 'xml', 'xml' => 'xml',
    'css' => 'css', 'py' => 'python', 'java' => 'java', 'c' => 'c', 'h' => 'c', 'cpp' => 'cpp', 'sql' => 'sql',
    'sh' => 'bash', 'md' => 'markdown', 'ts' => 'typescript', 'rb' => 'ruby', 'go' => 'go', 'yml' => 'yaml', 'yaml' => 'yaml');
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$lang = isset($langs[$ext]) ? $langs[$ext] : 'plaintext';

$lines = $binary ? 0 : count(split_lines($content));
$highlight = !$binary && !$edit && $size < 300000;
if ($highlight) {
    $extraHead = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">';
    $extraFoot = '<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>'
        . '<script>if (window.hljs) { hljs.highlightAll(); }</script>';
}

$pageTitle = $filename;
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'code', $ref); ?>
  <?php breadcrumbs($repo, $ref, $path); ?>

  <div class="filebar">
    <span class="filename"><?= e($filename) ?></span>
    <span class="muted"><?= $binary ? 'Binary file, ' : e($lines) . ' lines, ' ?><?= e(human_size($size)) ?></span>
    <span class="toolbar-actions">
      <a class="btn btn-quiet" href="<?= e(url_to('raw.php', array('repo' => $id, 'ref' => $ref, 'path' => $path))) ?>">Raw</a>
      <?php if ($res['is_branch'] && !$binary && !$edit): ?>
        <a class="btn btn-quiet" href="<?= e(url_to('file.php', array('repo' => $id, 'ref' => $ref, 'path' => $path, 'edit' => 1))) ?>">Edit</a>
      <?php endif; ?>
      <?php if ($res['is_branch']): ?>
        <form method="post" action="stage.php" class="inline" data-confirm="Stage this file for deletion?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="repo" value="<?= $id ?>">
          <input type="hidden" name="branch" value="<?= e($ref) ?>">
          <input type="hidden" name="path" value="<?= e($path) ?>">
          <button type="submit" class="btn btn-danger-quiet">Delete</button>
        </form>
      <?php endif; ?>
    </span>
  </div>

  <?php if (!$res['is_branch']): ?>
    <p class="notice notice-info">You are viewing commit <strong><?= e(short_hash($commit['hash'])) ?></strong>. Switch to a branch to edit.</p>
  <?php endif; ?>

  <?php if ($binary): ?>
    <p class="diff-note box">This is a binary file, so it cannot be shown here. Use Raw to download it.</p>
  <?php elseif ($edit): ?>
    <form method="post" action="stage.php" class="form edit-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="put">
      <input type="hidden" name="repo" value="<?= $id ?>">
      <input type="hidden" name="branch" value="<?= e($ref) ?>">
      <input type="hidden" name="path" value="<?= e($path) ?>">
      <label for="content" class="sr">File content</label>
      <textarea id="content" name="content" class="editor" spellcheck="false" wrap="off" rows="24"><?= "\n" . e($content) ?></textarea>
      <p class="actions">
        <button type="submit" class="btn btn-primary">Review changes</button>
        <a class="btn btn-quiet" href="<?= e(url_to('file.php', array('repo' => $id, 'ref' => $ref, 'path' => $path))) ?>">Cancel</a>
      </p>
    </form>
  <?php else: ?>
    <div class="codeview">
      <pre class="lineno" aria-hidden="true"><?php for ($i = 1; $i <= max($lines, 1); $i++) { echo $i . "\n"; } ?></pre>
      <pre class="codetext"><code class="language-<?= e($lang) ?><?= $highlight ? '' : ' nohighlight' ?>"><?= e($content) ?></code></pre>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
