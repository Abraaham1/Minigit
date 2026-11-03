<?php
// Merges one branch into another. If the same lines changed on both branches,
// shows a page where the user chooses what the final text should be.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];
$back = url_to('branches.php', array('repo' => $id));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}
if (!csrf_valid()) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect($back);
}

$action = post_str('action');
$source = post_str('source');
$target = post_str('target');
$error = '';
$conflicts = null;      // path => text shown in the textareas
$head_t = '';
$head_s = '';
$notices = array();

/** Where to go after a finished merge. */
function finish_merge($id, $r, $source, $target)
{
    if ($r['status'] === 'up_to_date') {
        flash_set('ok', $target . ' already contains everything from ' . $source . '. Nothing to merge.');
        redirect(url_to('branches.php', array('repo' => $id)));
    }
    $text = ($r['status'] === 'fast_forward')
        ? 'Fast forward: ' . $target . ' now points at the latest commit of ' . $source . '.'
        : 'Merged ' . $source . ' into ' . $target . '.';
    if (!empty($r['notices'])) {
        $text .= ' ' . implode(' ', $r['notices']);
    }
    flash_set('ok', $text);
    redirect(url_to('commit.php', array('repo' => $id, 'hash' => $r['hash'])));
}

try {
    if ($action === 'merge') {
        $r = merge_branches($conn, $repo, (int)$_SESSION['user_id'], $source, $target, null, null, null);
        if ($r['status'] === 'conflict') {
            $conflicts = $r['conflicts'];
            $head_t = $r['head_t'];
            $head_s = $r['head_s'];
            $notices = $r['notices'];
        } else {
            finish_merge($id, $r, $source, $target);
        }
    } elseif ($action === 'resolve') {
        $paths = (isset($_POST['paths']) && is_array($_POST['paths'])) ? $_POST['paths'] : array();
        $texts = (isset($_POST['resolved']) && is_array($_POST['resolved'])) ? $_POST['resolved'] : array();
        $head_t = post_str('expect_t');
        $head_s = post_str('expect_s');
        $resolved = array();
        foreach ($paths as $i => $p) {
            if (is_string($p) && isset($texts[$i]) && is_string($texts[$i])) {
                $resolved[$p] = $texts[$i];
            }
        }
        $conflicts = $resolved;   // used to show the page again if something is still wrong
        try {
            $r = merge_branches($conn, $repo, (int)$_SESSION['user_id'], $source, $target, $resolved, $head_t, $head_s);
            finish_merge($id, $r, $source, $target);
        } catch (UserError $ex) {
            $error = $ex->getMessage();
        }
    } else {
        redirect($back);
    }
} catch (UserError $ex) {
    flash_set('error', $ex->getMessage());
    redirect($back);
}

// ---- conflict page ----
$pageTitle = 'Resolve conflicts';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'branches', $repo['default_branch']); ?>
  <h2>Resolve conflicts</h2>
  <p class="sub">Merging <span class="branch-pill"><?= e($source) ?></span> into <span class="branch-pill"><?= e($target) ?></span>.
     The same lines were changed on both branches. Choose what each file should contain, then commit the merge.</p>

  <?php if ($error !== ''): ?>
    <p class="notice notice-error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php foreach ($notices as $n): ?>
    <p class="notice notice-info"><?= e($n) ?></p>
  <?php endforeach; ?>

  <form method="post" action="merge.php" class="resolve-form">
    <?= csrf_field() ?>
    <input type="hidden" name="repo" value="<?= $id ?>">
    <input type="hidden" name="action" value="resolve">
    <input type="hidden" name="source" value="<?= e($source) ?>">
    <input type="hidden" name="target" value="<?= e($target) ?>">
    <input type="hidden" name="expect_t" value="<?= e($head_t) ?>">
    <input type="hidden" name="expect_s" value="<?= e($head_s) ?>">

    <?php $i = 0; foreach ($conflicts as $path => $text): ?>
      <section class="conflict-file">
        <h3 class="filepath"><?= e($path) ?></h3>
        <input type="hidden" name="paths[<?= $i ?>]" value="<?= e($path) ?>">
        <div class="conflict-tools" data-for="resolved-<?= $i ?>"></div>
        <label for="resolved-<?= $i ?>" class="sr">Final content of <?= e($path) ?></label>
        <textarea id="resolved-<?= $i ?>" name="resolved[<?= $i ?>]" class="editor conflict-text" data-conflict
                  spellcheck="false" wrap="off" rows="<?= min(30, max(8, substr_count($text, "\n") + 3)) ?>"><?= "\n" . e($text) ?></textarea>
      </section>
    <?php $i++; endforeach; ?>

    <p class="actions">
      <button type="submit" class="btn btn-primary">Commit the merge</button>
      <a class="btn btn-quiet" href="<?= e($back) ?>">Cancel</a>
    </p>
  </form>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
