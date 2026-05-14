<?php
require_once 'session_bootstrap.php';
require('db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer' || $_SESSION['is_admin'] != 1) {
    header('Location: organizer_profile.php');
    exit;
}

if (isset($_GET['delete_user_id'])) {
    $delete_user_id = (int)$_GET['delete_user_id'];
    if ($delete_user_id !== $_SESSION['user_id']) {
        $stmt = $pdo->prepare("DELETE FROM Users WHERE user_id = ?");
        $stmt->execute([$delete_user_id]);
        header('Location: manage_users.php?deleted=1');
        exit;
    } else {
        $error = "You can't delete yourself!";
    }
}

$stmt = $pdo->prepare("
    SELECT
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.role,
        o.is_admin,
        up.profile_picture
    FROM Users u
    LEFT JOIN Organizer o ON o.user_id = u.user_id
    LEFT JOIN User_Profile up ON up.user_id = u.user_id
    ORDER BY u.first_name, u.last_name
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = count($users);
$attendeeCount = 0;
$organizerCount = 0;
$adminCount = 0;

foreach ($users as $user) {
    if ($user['role'] === 'attendee') {
        $attendeeCount++;
    }
    if ($user['role'] === 'organizer') {
        $organizerCount++;
    }
    if ((int)($user['is_admin'] ?? 0) === 1) {
        $adminCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Workspace</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    :root {
      --shell-max: 1240px;
      --ink: #1f2933;
      --muted: #6b7280;
      --line: rgba(31, 41, 51, 0.1);
      --surface: rgba(255, 255, 255, 0.95);
      --surface-soft: #f8f5f1;
      --accent: #b9502a;
      --accent-dark: #933c1d;
      --nav-dark: #16202a;
    }
    body {
      min-height: 100vh;
      color: var(--ink);
      background:
        radial-gradient(circle at top left, rgba(185, 80, 42, 0.12), transparent 24%),
        linear-gradient(180deg, #f6f2eb 0%, #fffdfa 100%);
    }
    .navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
      padding-top: 1rem;
      padding-bottom: 0.35rem;
      background: transparent !important;
      box-shadow: none;
    }
    .nav-panel {
      padding: 1rem 1.2rem;
      border: 1px solid rgba(31, 41, 51, 0.08);
      border-radius: 1.5rem;
      background:
        linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(247, 239, 231, 0.96));
      box-shadow: 0 18px 38px rgba(84, 52, 37, 0.1);
      backdrop-filter: blur(10px);
    }
    .navbar-brand {
      color: #1f2933 !important;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    .workspace-shell {
      max-width: var(--shell-max);
    }
    .workspace-links {
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem;
      margin-top: 0.8rem;
    }
    .workspace-links a {
      padding: 0.45rem 0.85rem;
      border-radius: 999px;
      text-decoration: none;
      background: rgba(185, 80, 42, 0.1);
      color: #7f3e24;
      font-size: 0.92rem;
      font-weight: 600;
      transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
    }
    .workspace-links a:hover {
      background: rgba(185, 80, 42, 0.18);
      color: #5d2d1c;
      transform: translateY(-1px);
    }
    .hero-card,
    .stat-card,
    .table-card {
      border: 1px solid var(--line);
      border-radius: 1.3rem;
      background: var(--surface);
      box-shadow: 0 18px 36px rgba(91, 67, 52, 0.08);
    }
    .hero-card,
    .table-card {
      padding: 1.4rem;
    }
    .hero-card {
      background:
        linear-gradient(135deg, rgba(185, 80, 42, 0.12), rgba(255, 255, 255, 0.96)),
        rgba(255, 255, 255, 0.96);
    }
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 1rem;
    }
    .stat-card {
      padding: 1rem 1.1rem;
    }
    .stat-card strong {
      display: block;
      font-size: 1.75rem;
      line-height: 1;
      color: var(--accent);
    }
    .mini-muted {
      color: var(--muted);
      font-size: 0.94rem;
    }
    .toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1rem;
    }
    .table-wrap {
      overflow-x: auto;
    }
    .table {
      margin-bottom: 0;
    }
    .table > :not(caption) > * > * {
      padding-top: 0.9rem;
      padding-bottom: 0.9rem;
      vertical-align: middle;
    }
    .table thead th {
      white-space: nowrap;
      color: #4b5563;
    }
    .user-cell {
      display: flex;
      align-items: center;
      gap: 0.9rem;
      min-width: 220px;
    }
    .avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      object-fit: cover;
      background: #ece7e1;
      border: 2px solid #f1ddd2;
      flex-shrink: 0;
    }
    .role-pill,
    .admin-pill {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 0.32rem 0.72rem;
      font-size: 0.85rem;
      font-weight: 600;
    }
    .role-pill.attendee {
      background: rgba(13, 110, 253, 0.12);
      color: #0b5ed7;
    }
    .role-pill.organizer {
      background: rgba(25, 135, 84, 0.12);
      color: #146c43;
    }
    .admin-pill {
      background: rgba(220, 53, 69, 0.12);
      color: #b02a37;
    }
    .btn-danger-soft {
      color: #b02a37;
      border-color: rgba(176, 42, 55, 0.3);
      background: rgba(220, 53, 69, 0.08);
    }
    .btn-danger-soft:hover {
      color: #fff;
      background: #b02a37;
      border-color: #b02a37;
    }
    .navbar .btn {
      border-radius: 999px;
      font-weight: 600;
      padding-inline: 1rem;
    }
    .navbar .btn-light {
      color: #fff;
      background-color: var(--accent);
      border-color: var(--accent);
    }
    .navbar .btn-light:hover,
    .navbar .btn-light:focus {
      color: #fff;
      background-color: var(--accent-dark);
      border-color: var(--accent-dark);
    }
    .navbar .btn-outline-light {
      color: #364152;
      border-color: rgba(54, 65, 82, 0.18);
      background: rgba(255, 255, 255, 0.7);
    }
    .navbar .btn-outline-light:hover,
    .navbar .btn-outline-light:focus {
      color: #1f2933;
      background: rgba(255, 255, 255, 0.95);
      border-color: rgba(54, 65, 82, 0.28);
    }
    .navbar .btn-outline-warning {
      color: var(--accent-dark);
      border-color: rgba(147, 60, 29, 0.25);
      background: rgba(185, 80, 42, 0.08);
    }
    .navbar .btn-outline-warning:hover,
    .navbar .btn-outline-warning:focus {
      color: #fff;
      background: var(--accent-dark);
      border-color: var(--accent-dark);
    }
    @media (max-width: 991.98px) {
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .toolbar {
        align-items: flex-start;
        flex-direction: column;
      }
    }
    @media (max-width: 575.98px) {
      .stat-grid {
        grid-template-columns: 1fr;
      }
      .hero-card,
      .table-card {
        padding: 1.15rem;
      }
      .nav-panel {
        padding: 1rem;
        border-radius: 1.2rem;
      }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark px-4 py-3">
    <div class="container-fluid px-0 nav-panel">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 w-100">
        <div>
          <a class="navbar-brand mb-0">Admin Workspace</a>
          <div class="workspace-links">
            <a href="#overview">Overview</a>
            <a href="#directory">User Directory</a>
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="organizer_profile.php" class="btn btn-light">Back to Dashboard</a>
          <a href="create_event.html" class="btn btn-outline-light">Create Event</a>
          <a href="logout.php" class="btn btn-outline-warning">Log Out</a>
        </div>
      </div>
    </div>
  </nav>

  <div class="container workspace-shell my-4" id="overview">
    <div class="hero-card mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-7">
          <div class="mini-muted mb-2">Admin control center</div>
          <h1 class="h2 mb-2">Manage platform users with less friction</h1>
          <p class="text-muted mb-0">Review who is on the platform, distinguish organizers from attendees, and take action from a workspace that feels consistent with the rest of the product.</p>
        </div>
        <div class="col-lg-5">
          <div class="stat-grid">
            <div class="stat-card">
              <span class="mini-muted">Total Users</span>
              <strong><?= $totalUsers ?></strong>
            </div>
            <div class="stat-card">
              <span class="mini-muted">Attendees</span>
              <strong><?= $attendeeCount ?></strong>
            </div>
            <div class="stat-card">
              <span class="mini-muted">Organizers</span>
              <strong><?= $organizerCount ?></strong>
            </div>
            <div class="stat-card">
              <span class="mini-muted">Admins</span>
              <strong><?= $adminCount ?></strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="table-card" id="directory">
      <div class="toolbar">
        <div>
          <h2 class="h4 mb-1">User Directory</h2>
          <p class="mini-muted mb-0">Current user records across attendee, organizer, and admin accounts.</p>
        </div>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">User deleted successfully.</div>
      <?php endif; ?>

      <div class="table-wrap">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>User</th>
              <th>Email</th>
              <th>Role</th>
              <th>Access</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <?php
                $avatar = $user['profile_picture'] ?: 'https://via.placeholder.com/88';
                $isCurrentUser = (int)$user['user_id'] === (int)$_SESSION['user_id'];
                $roleClass = $user['role'] === 'organizer' ? 'organizer' : 'attendee';
              ?>
              <tr>
                <td>
                  <div class="user-cell">
                    <img class="avatar" src="<?= htmlspecialchars($avatar) ?>" alt="Profile Picture" />
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
                      <div class="mini-muted">User ID #<?= (int)$user['user_id'] ?></div>
                    </div>
                  </div>
                </td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td>
                  <span class="role-pill <?= $roleClass ?>"><?= htmlspecialchars(ucfirst($user['role'])) ?></span>
                </td>
                <td>
                  <?php if ((int)($user['is_admin'] ?? 0) === 1): ?>
                    <span class="admin-pill">Admin</span>
                  <?php else: ?>
                    <span class="mini-muted">Standard access</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?php if (!$isCurrentUser): ?>
                    <a
                      href="manage_users.php?delete_user_id=<?= (int)$user['user_id'] ?>"
                      onclick="return confirm('Delete this user?');"
                      class="btn btn-sm btn-danger-soft"
                    >Delete</a>
                  <?php else: ?>
                    <span class="mini-muted">Current user</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
