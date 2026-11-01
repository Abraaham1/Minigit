<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

require_guest();

$error = '';
$name  = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name            = post_str('name');
    $email           = post_str('email');
    $password        = post_str('password', false);
    $confirmPassword = post_str('confirm_password', false);

    if (!csrf_valid()) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($name === '' || text_length($name) > 100) {
        $error = 'Enter your name (up to 100 characters).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 72) {
        // bcrypt only uses the first 72 bytes, so longer passwords would be silently cut
        $error = 'Password must be 72 characters or fewer.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // 1) Is the email already registered?
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = 'That email is already registered.';
        } else {
            // 2) Hash the password and store the user
            $hash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $stmt = $conn->prepare(
                    'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)'
                );
                $stmt->bind_param('sss', $name, $email, $hash);
                $stmt->execute();
                $stmt->close();

                flash_set('ok', 'Account created. Log in to continue.');
                redirect('login.php');
            } catch (mysqli_sql_exception $ex) {
                // 1062 = duplicate key: someone registered this email a moment ago
                if ((int)$ex->getCode() === 1062) {
                    $error = 'That email is already registered.';
                } else {
                    error_log('Signup failed: ' . $ex->getMessage());
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Create account';
$bodyClass = 'auth';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell">
  <?php require __DIR__ . '/../includes/auth_side.php'; ?>

  <section class="auth-main">
    <div class="auth-box">
      <h1>Create your account</h1>
      <p class="sub">Free while you build. It takes under a minute.</p>

      <?php if ($error !== ''): ?>
        <p class="notice notice-error" role="alert"><?= e($error) ?></p>
      <?php endif; ?>

      <form method="post" action="signup.php" class="form" id="signup-form">
        <?= csrf_field() ?>

        <label for="name">Full name</label>
        <input type="text" id="name" name="name" value="<?= e($name) ?>"
               maxlength="100" autocomplete="name" required autofocus>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>"
               maxlength="255" autocomplete="email" required>

        <label for="password">Password <span class="hint">at least 8 characters</span></label>
        <div class="field-reveal">
          <input type="password" id="password" name="password"
                 minlength="8" maxlength="72" autocomplete="new-password" required>
          <button type="button" class="reveal" data-reveal="password" aria-pressed="false">Show</button>
        </div>

        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               minlength="8" maxlength="72" autocomplete="new-password" required>

        <button type="submit" class="btn btn-primary">Create account</button>
      </form>

      <p class="switch">Already have an account? <a href="login.php">Log in</a></p>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
