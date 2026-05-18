<?php
require 'db.php';
require_once 'session_bootstrap.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$backHref = $role === 'organizer' ? 'organizer_profile.php' : 'attendee_profile.php';
$pageTitle = $role === 'organizer' ? 'Organizer Password' : 'Attendee Password';
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $errors[] = 'Please complete all password fields.';
    }

    if ($newPassword !== '' && strlen($newPassword) < 8) {
        $errors[] = 'Use at least 8 characters for your new password.';
    }

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if ($currentPassword !== '' && $newPassword !== '' && $currentPassword === $newPassword) {
        $errors[] = 'Choose a new password that is different from your current one.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            } else {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
                $updateStmt->execute([$newPasswordHash, $userId]);
                $successMessage = 'Password updated successfully.';
            }
        } catch (PDOException $e) {
            $errors[] = 'We could not update your password right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Gathero | <?= htmlspecialchars($pageTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet" />
  <style>
    :root {
      --bg: #f6efe5;
      --surface: rgba(255, 252, 247, 0.95);
      --text: #1d1b18;
      --muted: #685f58;
      --accent: #d95d39;
      --accent-dark: #a94224;
      --line: rgba(29, 27, 24, 0.1);
      --success: #2f6c67;
      --success-soft: rgba(47, 108, 103, 0.14);
      --error: #a03f39;
      --error-soft: rgba(187, 74, 67, 0.14);
      --shadow: 0 24px 54px rgba(83, 52, 30, 0.14);
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Source Sans 3", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(255, 183, 130, 0.32), transparent 28%),
        radial-gradient(circle at right 12%, rgba(217, 93, 57, 0.16), transparent 18%),
        linear-gradient(180deg, #fff9f2 0%, var(--bg) 100%);
    }

    .page-shell {
      width: min(680px, calc(100% - 1.5rem));
      margin: 0 auto;
      padding: 2rem 0 4rem;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      align-items: center;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }

    .brand {
      font-family: "Space Grotesk", sans-serif;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text);
      text-decoration: none;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 3rem;
      padding: 0.85rem 1.2rem;
      border-radius: 999px;
      border: 1px solid transparent;
      text-decoration: none;
      font-weight: 700;
      transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
      cursor: pointer;
    }

    .btn:hover {
      transform: translateY(-2px);
    }

    .btn-primary {
      color: #fff;
      background: linear-gradient(135deg, var(--accent), #ef8b4f);
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, var(--accent-dark), var(--accent));
    }

    .btn-secondary {
      color: var(--text);
      background: rgba(255, 255, 255, 0.66);
      border-color: var(--line);
    }

    .panel {
      padding: 2rem;
      border-radius: 28px;
      background: var(--surface);
      border: 1px solid var(--line);
      box-shadow: var(--shadow);
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      padding: 0.45rem 0.85rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.75);
      border: 1px solid var(--line);
      color: var(--muted);
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    h1 {
      margin: 1rem 0 0.5rem;
      font-family: "Space Grotesk", sans-serif;
      font-size: clamp(2rem, 6vw, 3.25rem);
      line-height: 1;
      letter-spacing: -0.04em;
    }

    .intro-copy {
      margin: 0 0 1.5rem;
      color: var(--muted);
      line-height: 1.6;
    }

    .message {
      margin-bottom: 1rem;
      padding: 0.95rem 1rem;
      border-radius: 18px;
      border: 1px solid transparent;
    }

    .message.success {
      color: var(--success);
      background: var(--success-soft);
      border-color: rgba(47, 108, 103, 0.2);
    }

    .message.error {
      color: var(--error);
      background: var(--error-soft);
      border-color: rgba(187, 74, 67, 0.2);
    }

    .message ul {
      margin: 0;
      padding-left: 1.2rem;
    }

    .field + .field {
      margin-top: 1rem;
    }

    label {
      display: block;
      margin-bottom: 0.45rem;
      font-weight: 700;
    }

    .field-hint {
      display: block;
      margin-top: 0.4rem;
      color: var(--muted);
      font-size: 0.94rem;
    }

    input {
      width: 100%;
      min-height: 3rem;
      padding: 0.85rem 1rem;
      border-radius: 16px;
      border: 1px solid var(--line);
      background: #fff;
      font: inherit;
    }

    .actions {
      display: flex;
      gap: 0.8rem;
      flex-wrap: wrap;
      margin-top: 1.5rem;
    }

    @media (max-width: 640px) {
      .actions .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <div class="topbar">
      <a href="index.html" class="brand">Gathero</a>
      <a href="<?= htmlspecialchars($backHref) ?>" class="btn btn-secondary">Back to Profile</a>
    </div>

    <section class="panel">
      <span class="eyebrow">Account Security</span>
      <h1>Change your password</h1>
      <p class="intro-copy">Keep your account secure with a fresh password. We will verify your current password before applying any change.</p>

      <?php if ($successMessage !== ''): ?>
        <div class="message success"><?= htmlspecialchars($successMessage) ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="message error">
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="field">
          <label for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password" autocomplete="current-password" required />
        </div>

        <div class="field">
          <label for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" required />
          <span class="field-hint">Use at least 8 characters.</span>
        </div>

        <div class="field">
          <label for="confirm_password">Confirm new password</label>
          <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required />
        </div>

        <div class="actions">
          <button type="submit" class="btn btn-primary">Update Password</button>
          <a href="<?= htmlspecialchars($backHref) ?>" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </section>
  </div>
</body>
</html>
