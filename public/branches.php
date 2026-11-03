<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];

// ---- create or delete a branch ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('error', 'Your session expired. Please try again.');
    } else {
        $action = post_str('action');
        try {
            if ($action === 'create') {
                $from = resolve_ref($conn, $id, post_str('from'));
                create_branch($conn, $id, post_str('name'), $from ? $from['hash'] : null);
                flash_set('ok', 'Branch ' . post_str('name') . ' created.');
            } elseif ($action === 'delete') {
                remove_branch($conn, $repo, post_str('name'));
                flash_set('ok', 'Branch ' . post_str('name') . ' deleted. Its commits are still in the history of other branches that contain them.');
            }
        } catch (UserError $ex) {
            flash_set('error', $ex->getMessage());
        }
    }
    redirect(url_to('branches.php', array('repo' => $id)));
}

$refs = list_refs($conn, $id);
$rows = array();
foreach ($refs as $name => $hash) {
    $rows[] = array('name' => (string)$name, 'hash' => $hash, 'commit' => read_commit($conn, $id, $hash));
}

$pageTitle = 'Branches';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page">
  <?php repo_header($repo, 'branches', $repo['default_branch']); ?>

  <?php if (count($refs) == 0): ?>
    <div class="empty"><p class="empty-title">No branches yet</p><p>Branches appear after the first commit.</p></div>
  <?php else: ?>
    <table class="files branch-table">
      <thead><tr><th>Branch</th><th>Latest commit</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a href="<?= e(url_to('repo.php', array('repo' => $id, 'ref' => $r['name']))) ?>"><strong><?= e($r['name']) ?></strong></a>
              <?php if ($r['name'] === $repo['default_branch']): ?><span class="badge badge-default">default</span><?php endif; ?>
            </td>
            <td>
              <a href="<?= e(url_to('commit.php', array('repo' => $id, 'hash' => $r['hash']))) ?>"><?= e($r['commit']['message']) ?></a>
              <span class="muted small"><?= e(short_hash($r['hash'])) ?>, <?= e(fmt_time($r['commit']['ts'])) ?></span>
            </td>
            <td class="right">
              <?php if ($r['name'] !== $repo['default_branch']): ?>
                <form method="post" action="branches.php" class="inline" data-confirm="Delete branch <?= e($r['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="repo" value="<?= $id ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="name" value="<?= e($r['name']) ?>">
                  <button type="submit" class="btn btn-danger-quiet">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="two-col">
      <section class="panel">
        <h2>New branch</h2>
        <p class="muted small">A branch is a name that points at a commit. Creating one costs nothing.</p>
        <form method="post" action="branches.php" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="repo" value="<?= $id ?>">
          <input type="hidden" name="action" value="create">
          <label for="bname">Branch name</label>
          <input type="text" id="bname" name="name" maxlength="100" required placeholder="feature-navbar">
          <label for="bfrom">Start from</label>
          <select id="bfrom" name="from">
            <?php foreach ($refs as $name => $h): ?>
              <option value="<?= e($name) ?>"<?= (string)$name === $repo['default_branch'] ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary">Create branch</button>
        </form>
      </section>

      <section class="panel">
        <h2>Merge branches</h2>
        <p class="muted small">Bring the changes of one branch into another.</p>
        <?php if (count($refs) < 2): ?>
          <p class="muted">Create a second branch to merge.</p>
        <?php else: ?>
          <form method="post" action="merge.php" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="repo" value="<?= $id ?>">
            <input type="hidden" name="action" value="merge">
            <label for="msource">Merge this branch</label>
            <select id="msource" name="source">
              <?php $first = true; foreach ($refs as $name => $h): if ((string)$name === $repo['default_branch']) { continue; } ?>
                <option value="<?= e($name) ?>"<?= $first ? ' selected' : '' ?>><?= e($name) ?></option>
              <?php $first = false; endforeach; ?>
              <option value="<?= e($repo['default_branch']) ?>"><?= e($repo['default_branch']) ?></option>
            </select>
            <label for="mtarget">into this branch</label>
            <select id="mtarget" name="target">
              <?php foreach ($refs as $name => $h): ?>
                <option value="<?= e($name) ?>"<?= (string)$name === $repo['default_branch'] ? ' selected' : '' ?>><?= e($name) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Merge</button>
          </form>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
