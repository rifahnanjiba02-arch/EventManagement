<?php
session_start();
require('db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer' || $_SESSION['is_admin'] != 1) {
    header('Location: unauthorized.php');
    exit;
}

// Handle delete user request
if (isset($_GET['delete_user_id'])) {
    $delete_user_id = (int)$_GET['delete_user_id'];
    if ($delete_user_id !== $_SESSION['user_id']) { // prevent deleting yourself
        $stmt = $pdo->prepare("DELETE FROM Users WHERE user_id = ?");
        $stmt->execute([$delete_user_id]);
        header('Location: manage_users.php?deleted=1');
        exit;
    } else {
        $error = "You can't delete yourself!";
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT user_id, first_name, last_name, email, role FROM Users ORDER BY first_name");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Manage Users</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<div class="container mt-4">
  <h1>Manage Users</h1>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">User deleted successfully.</div>
  <?php endif; ?>

  <table class="table table-striped">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $user): ?>
      <tr>
        <td><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></td>
        <td><?= htmlspecialchars($user['email']) ?></td>
        <td><?= htmlspecialchars($user['role']) ?></td>
        <td>
          <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
            <a href="manage_users.php?delete_user_id=<?= $user['user_id'] ?>" onclick="return confirm('Delete this user?');" class="btn btn-sm btn-danger">Delete</a>
          <?php else: ?>
            <em>Current User</em>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <a href="organizer_profile.php" class="btn btn-primary mt-3">Back to Profile</a>
</div>
</body>
</html>
