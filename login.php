<?php
require_once 'session_bootstrap.php';
require_once 'db.php';

$allowedRoles = ['attendee', 'organizer'];
$errors = [];
$formData = [
    'email' => '',
    'role' => ''
];

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'organizer') {
        header('Location: organizer_profile.php');
    } else {
        header('Location: attendee_profile.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['role'] = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($formData['email'] === '' || $password === '' || $formData['role'] === '') {
        $errors[] = 'Please complete email, password, and account type.';
    }

    if ($formData['email'] !== '' && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($formData['role'] !== '' && !in_array($formData['role'], $allowedRoles, true)) {
        $errors[] = 'Select a valid account type.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT user_id, password_hash, role FROM Users WHERE email = :email LIMIT 1'
            );
            $stmt->execute(['email' => $formData['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Incorrect email or password.';
            } elseif ($user['role'] !== $formData['role']) {
                $errors[] = 'This account is registered as a different account type.';
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] === 'organizer') {
                    $stmt = $pdo->prepare('SELECT organizer_id, is_admin FROM Organizer WHERE user_id = ? LIMIT 1');
                    $stmt->execute([$user['user_id']]);
                    $organizer = $stmt->fetch(PDO::FETCH_ASSOC);

                    $_SESSION['organizer_id'] = isset($organizer['organizer_id']) ? (int) $organizer['organizer_id'] : null;
                    $_SESSION['is_admin'] = isset($organizer['is_admin']) ? (int) $organizer['is_admin'] : 0;

                    header('Location: organizer_profile.php');
                } else {
                    $stmt = $pdo->prepare('SELECT attendee_id FROM Attendee WHERE user_id = ? LIMIT 1');
                    $stmt->execute([$user['user_id']]);
                    $attendee = $stmt->fetch(PDO::FETCH_ASSOC);

                    $_SESSION['attendee_id'] = isset($attendee['attendee_id']) ? (int) $attendee['attendee_id'] : null;
                    $_SESSION['organizer_id'] = null;
                    $_SESSION['is_admin'] = 0;

                    header('Location: attendee_profile.php');
                }
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'We could not sign you in right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login | Gathero</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="global_nav.js" defer></script>
  <style>
    :root {
      --bg: #f3f6fb;
      --surface: rgba(255, 255, 255, 0.92);
      --surface-strong: #ffffff;
      --text: #162033;
      --muted: #64748b;
      --line: rgba(22, 32, 51, 0.1);
      --brand: #0f766e;
      --brand-deep: #115e59;
      --accent: #f59e0b;
      --shadow: 0 24px 60px rgba(15, 23, 42, 0.14);
      --radius: 28px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Manrope", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(15, 118, 110, 0.18), transparent 28%),
        radial-gradient(circle at bottom right, rgba(245, 158, 11, 0.22), transparent 24%),
        linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
    }

    .auth-topbar {
      width: min(1040px, calc(100% - 2rem));
      margin: 0 auto;
      padding-top: 1.25rem;
    }

    .auth-topbar .nav-shell {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.9rem 1rem;
      border: 1px solid rgba(255, 255, 255, 0.7);
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.78);
      backdrop-filter: blur(16px);
      box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
    }

    .auth-brand {
      display: inline-flex;
      align-items: center;
      line-height: 0;
      text-decoration: none;
    }

    .auth-brand-logo {
      display: block;
      height: 3rem;
      width: auto;
    }

    .auth-nav-actions {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .auth-nav-actions .btn {
      border-radius: 999px;
      font-weight: 700;
    }

    .auth-shell {
      min-height: calc(100vh - 96px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .auth-card {
      width: min(1040px, 100%);
      border: 1px solid rgba(255, 255, 255, 0.7);
      border-radius: var(--radius);
      overflow: hidden;
      background: var(--surface);
      backdrop-filter: blur(18px);
      box-shadow: var(--shadow);
    }

    .auth-hero {
      background:
        linear-gradient(160deg, rgba(17, 94, 89, 0.96), rgba(15, 118, 110, 0.88)),
        linear-gradient(135deg, rgba(245, 158, 11, 0.4), transparent);
      color: #effcf7;
      padding: 40px;
      height: 100%;
      position: relative;
    }

    .auth-hero::after {
      content: "";
      position: absolute;
      inset: auto -60px -70px auto;
      width: 180px;
      height: 180px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.08);
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      background: rgba(255, 255, 255, 0.14);
    }

    .auth-hero h1 {
      margin: 22px 0 14px;
      font-size: clamp(2rem, 4vw, 3rem);
      line-height: 1.05;
      font-weight: 800;
    }

    .auth-hero p,
    .hero-list {
      color: rgba(239, 252, 247, 0.84);
    }

    .hero-list {
      margin: 28px 0 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 14px;
    }

    .hero-list li {
      display: flex;
      gap: 12px;
      align-items: flex-start;
    }

    .hero-bullet {
      width: 12px;
      height: 12px;
      margin-top: 6px;
      border-radius: 50%;
      background: linear-gradient(135deg, #fcd34d, #f59e0b);
      flex-shrink: 0;
    }

    .auth-form-wrap {
      padding: 40px;
      background: rgba(255, 255, 255, 0.78);
    }

    .auth-form-wrap h2 {
      margin: 0 0 10px;
      font-size: 2rem;
      font-weight: 800;
    }

    .auth-form-wrap p {
      color: var(--muted);
      margin-bottom: 24px;
    }

    .form-label {
      font-weight: 700;
      margin-bottom: 8px;
    }

    .form-control,
    .form-select {
      border-radius: 16px;
      border: 1px solid var(--line);
      padding: 0.9rem 1rem;
      min-height: 54px;
      background: var(--surface-strong);
      color: var(--text);
      box-shadow: none;
    }

    .form-control:focus,
    .form-select:focus {
      border-color: rgba(15, 118, 110, 0.45);
      box-shadow: 0 0 0 0.2rem rgba(15, 118, 110, 0.12);
    }

    .role-picker {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .role-option input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .role-card {
      display: block;
      height: 100%;
      padding: 18px 18px 16px;
      border: 1px solid var(--line);
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.92);
      cursor: pointer;
      transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
    }

    .role-card strong {
      display: block;
      margin-bottom: 6px;
      font-size: 1rem;
    }

    .role-card span {
      display: block;
      color: var(--muted);
      font-size: 0.94rem;
      line-height: 1.45;
    }

    .role-option input:checked + .role-card {
      border-color: rgba(15, 118, 110, 0.65);
      box-shadow: 0 0 0 0.2rem rgba(15, 118, 110, 0.12);
      transform: translateY(-1px);
      background: linear-gradient(180deg, rgba(240, 253, 250, 0.98), rgba(255, 255, 255, 0.98));
    }

    .role-option input:focus-visible + .role-card {
      box-shadow: 0 0 0 0.24rem rgba(15, 118, 110, 0.16);
    }

    .btn-auth {
      width: 100%;
      min-height: 54px;
      border: 0;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--brand), var(--brand-deep));
      color: #fff;
      font-weight: 800;
      letter-spacing: 0.01em;
      transition: transform 0.18s ease, box-shadow 0.18s ease;
      box-shadow: 0 18px 30px rgba(17, 94, 89, 0.2);
    }

    .btn-auth:hover {
      transform: translateY(-1px);
      box-shadow: 0 22px 34px rgba(17, 94, 89, 0.24);
      color: #fff;
    }

    .inline-link {
      color: var(--brand-deep);
      font-weight: 700;
      text-decoration: none;
    }

    .inline-link:hover {
      text-decoration: underline;
    }

    .auth-footer {
      margin-top: 20px;
      color: var(--muted);
      text-align: center;
    }

    .alert {
      border: 0;
      border-radius: 18px;
    }

    @media (max-width: 991.98px) {
      .auth-topbar .nav-shell {
        border-radius: 1.5rem;
      }

      .auth-hero,
      .auth-form-wrap {
        padding: 32px 24px;
      }
    }

    @media (max-width: 575.98px) {
      .auth-topbar .nav-shell {
        align-items: flex-start;
        flex-direction: column;
      }

      .auth-nav-actions {
        width: 100%;
      }

      .auth-nav-actions .btn {
        flex: 1 1 100%;
      }

      .role-picker {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="auth-topbar">
    <div class="nav-shell">
      <a href="index.html" class="auth-brand"><img src="uploads/gathero_logo_no_icon.svg" alt="Gathero" class="auth-brand-logo"></a>
      <nav class="auth-nav-actions" data-global-nav aria-label="Global navigation">
        <a href="index.html" class="btn btn-outline-secondary">Home</a>
        <a href="events.html" class="btn btn-outline-secondary">Events</a>
        <a href="login.php" class="btn btn-outline-secondary">Login</a>
        <a href="register.php" class="btn btn-primary">Create Account</a>
      </nav>
    </div>
  </div>
  <main class="auth-shell">
    <section class="auth-card">
      <div class="row g-0">
        <div class="col-lg-5">
          <div class="auth-hero">
            <span class="eyebrow">Gathero</span>
            <h1>Welcome back to your event dashboard.</h1>
            <p>Sign in to manage bookings, publish events, and keep every attendee touchpoint in one place.</p>
            <ul class="hero-list">
              <li><span class="hero-bullet"></span><span>Attendees can track bookings, profile details, and feedback from one account.</span></li>
              <li><span class="hero-bullet"></span><span>Organizers can move from planning to publishing without bouncing between pages.</span></li>
              <li><span class="hero-bullet"></span><span>Secure session handling keeps the login flow cleaner and more reliable.</span></li>
            </ul>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="auth-form-wrap">
            <h2>Login</h2>
            <p>Use the account you created for Gathero.</p>

            <?php if (isset($_GET['logged_out'])): ?>
              <div class="alert alert-success mb-4" role="alert">You have been logged out successfully.</div>
            <?php endif; ?>

            <?php if (isset($_GET['registered'])): ?>
              <div class="alert alert-success mb-4" role="alert">Your account has been created. You can sign in now.</div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
              <div class="alert alert-danger mb-4" role="alert">
                <?php foreach ($errors as $error): ?>
                  <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
              <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input
                  type="email"
                  name="email"
                  id="email"
                  class="form-control"
                  autocomplete="email"
                  required
                  value="<?= htmlspecialchars($formData['email']) ?>"
                >
              </div>

              <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input
                  type="password"
                  name="password"
                  id="password"
                  class="form-control"
                  autocomplete="current-password"
                  required
                >
              </div>

              <div class="mb-4">
                <label class="form-label d-block">Account Type</label>
                <div class="role-picker" role="radiogroup" aria-label="Account Type">
                  <label class="role-option">
                    <input type="radio" name="role" value="attendee" <?= $formData['role'] === 'attendee' ? 'checked' : '' ?> required>
                    <span class="role-card">
                      <strong>Attendee</strong>
                      <span>Track bookings, manage your profile, and leave feedback after events.</span>
                    </span>
                  </label>
                  <label class="role-option">
                    <input type="radio" name="role" value="organizer" <?= $formData['role'] === 'organizer' ? 'checked' : '' ?> required>
                    <span class="role-card">
                      <strong>Organizer</strong>
                      <span>Create events, manage your workspace, and oversee attendee activity.</span>
                    </span>
                  </label>
                </div>
              </div>

              <button type="submit" class="btn btn-auth">Sign In</button>
            </form>

            <div class="auth-footer">
              Need an account? <a class="inline-link" href="register.php">Create one here</a>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
