<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();

$error = '';
$name = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = post_str('name');
    $description = post_str('description');
    if (!csrf_valid()) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        try {
            $id = create_repo($conn, $_SESSION['user_id'], $name, $description);
            flash_set('ok', 'Repository created. Add your first files to start committing.');
            redirect(url_to('repo.php', array('repo' => $id)));
        } catch (UserError $ex) {
            $error = $ex->getMessage();
        }
    }
}

$pageTitle = 'New repository';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page narrow">
  <h1>New repository</h1>
  <p class="sub">A repository holds the files of one project and its full history.</p>

  <?php if ($error !== ''): ?>
    <p class="notice notice-error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="repo_new.php" class="form">
    <?= csrf_field() ?>

    <label for="name">Repository name</label>
    <input type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="100" required autofocus
           pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" title="Letters, numbers, dots, dashes and underscores">

    <label for="description">Description <span class="hint">optional</span></label>
    <input type="text" id="description" name="description" value="<?= e($description) ?>" maxlength="255">

    <button type="submit" class="btn btn-primary">Create repository</button>
  </form>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
