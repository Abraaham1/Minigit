<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

require_guest();

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = post_str('email');
    $password = post_str('password', false);   // never trim passwords

    if (!csrf_valid()) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } else {
        // Prepared statement: user input never becomes part of the SQL text
        $stmt = $conn->prepare(
            'SELECT id, name, email, password_hash FROM users WHERE email = ?'
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);   // new session ID after login (stops session fixation)
            unset($_SESSION['csrf']);      // fresh CSRF token for the new session

            $_SESSION['user_id']    = (int)$user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];

            redirect('home.php');
        }

        // Same message whether the email or the password was wrong
        $error = 'Invalid email or password.';
    }
}

$flash     = flash_get();
$pageTitle = 'Log in';
$bodyClass = 'auth';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell">
  <?php require __DIR__ . '/../includes/auth_side.php'; ?>

  <section class="auth-main">
    <div class="auth-box">
      <h1>Log in</h1>
      <p class="sub">Pick up where you left off.</p>

      <?php if ($flash): ?>
        <p class="notice notice-ok" role="status"><?= e($flash['message']) ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="notice notice-error" role="alert"><?= e($error) ?></p>
      <?php endif; ?>

      <form method="post" action="login.php" class="form">
        <?= csrf_field() ?>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>"
               autocomplete="email" required autofocus>

        <label for="password">Password</label>
        <div class="field-reveal">
          <input type="password" id="password" name="password"
                 autocomplete="current-password" required>
          <button type="button" class="reveal" data-reveal="password" aria-pressed="false">Show</button>
        </div>

        <button type="submit" class="btn btn-primary">Log in</button>
      </form>

      <p class="switch">New here? <a href="signup.php">Create an account</a></p>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
