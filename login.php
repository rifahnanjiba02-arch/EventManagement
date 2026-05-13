<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
require_once 'db.php';

$errors = [];

// Handle POST (login submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    // Basic validation
    if (!$email || !$password || !$role) {
        $errors[] = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    } else {
        $allowedRoles = ['attendee', 'organizer'];
        if (!in_array($role, $allowedRoles, true)) {
            $errors[] = "Invalid role selected.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT user_id, password_hash FROM Users WHERE email = :email AND role = :role");
                $stmt->execute(['email' => $email, 'role' => $role]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password_hash'])) {
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $role;

                    if ($role === 'organizer') {
                        $stmt = $pdo->prepare("SELECT is_admin FROM Organizer WHERE user_id = ?");
                        $stmt->execute([$user['user_id']]);
                        $organizer = $stmt->fetch(PDO::FETCH_ASSOC);
                        $_SESSION['is_admin'] = $organizer['is_admin'] ?? 0;

                        header('Location: organizer_profile.php');
                    } else {
                        $_SESSION['is_admin'] = 0;

                        // Fetch attendee_id and store it in session
                        $stmt = $pdo->prepare("SELECT attendee_id FROM Attendee WHERE user_id = ?");
                        $stmt->execute([$user['user_id']]);
                        $attendee = $stmt->fetch(PDO::FETCH_ASSOC);
                        $_SESSION['attendee_id'] = $attendee['attendee_id'] ?? null;

                        header('Location: attendee_profile.php');
                    }
                    exit;
                } else {
                    $errors[] = "Invalid credentials.";
                }
            } catch (PDOException $e) {
                $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
  <h2>Login</h2>

  <?php if (isset($_GET['logged_out'])): ?>
    <div class="alert alert-success">
      You have been logged out successfully.
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $error): ?>
        <div><?= htmlspecialchars($error) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="login.php">
    <div class="mb-3">
      <label for="email" class="form-label">Email</label>
      <input type="email" name="email" id="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>

    <div class="mb-3">
      <label for="password" class="form-label">Password</label>
      <input type="password" name="password" id="password" class="form-control" required>
    </div>

    <div class="mb-3">
      <label for="role" class="form-label">Role</label>
      <select name="role" id="role" class="form-control" required>
        <option value="">-- Select Role --</option>
        <option value="attendee" <?= (($_POST['role'] ?? '') === 'attendee') ? 'selected' : '' ?>>Attendee</option>
        <option value="organizer" <?= (($_POST['role'] ?? '') === 'organizer') ? 'selected' : '' ?>>Organizer</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Login</button>
  </form>
</div>
</body>
</html>
