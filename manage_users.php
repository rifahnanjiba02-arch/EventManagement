<?php
require_once 'session_bootstrap.php';
require('db.php');
require_once 'event_status_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer' || $_SESSION['is_admin'] != 1) {
    header('Location: organizer_profile.php');
    exit;
}

ensureEventStatusSchema($pdo);

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

$cancelledEventsStmt = $pdo->query("
    SELECT COUNT(*)
    FROM EventDetails
    WHERE event_status = 'cancelled'
");
$cancelledEventsCount = (int) $cancelledEventsStmt->fetchColumn();

$unattributedCancelledEventsStmt = $pdo->query("
    SELECT COUNT(*)
    FROM EventDetails
    WHERE event_status = 'cancelled'
      AND cancelled_by_organizer_id IS NULL
");
$unattributedCancelledEventsCount = (int) $unattributedCancelledEventsStmt->fetchColumn();

$cancellationLeadersStmt = $pdo->query("
    SELECT
        o.organizer_id,
        u.first_name,
        u.last_name,
        u.email,
        COUNT(e.event_id) AS cancelled_events
    FROM EventDetails e
    JOIN Organizer o ON o.organizer_id = e.cancelled_by_organizer_id
    JOIN Users u ON u.user_id = o.user_id
    WHERE e.event_status = 'cancelled'
    GROUP BY o.organizer_id, u.first_name, u.last_name, u.email
    ORDER BY cancelled_events DESC, u.first_name ASC, u.last_name ASC
");
$cancellationLeaders = $cancellationLeadersStmt->fetchAll(PDO::FETCH_ASSOC);

$cancelledEventsAuditStmt = $pdo->query("
    SELECT
        e.event_id,
        e.title,
        e.event_date,
        e.cancellation_reason,
        e.cancellation_time,
        e.cancelled_by_organizer_id,
        o.organizer_id,
        u.first_name,
        u.last_name,
        u.email
    FROM EventDetails e
    LEFT JOIN Organizer o ON o.organizer_id = e.cancelled_by_organizer_id
    LEFT JOIN Users u ON u.user_id = o.user_id
    WHERE e.event_status = 'cancelled'
    ORDER BY e.cancellation_time DESC, e.event_id DESC
");
$cancelledEventsAudit = $cancelledEventsAuditStmt->fetchAll(PDO::FETCH_ASSOC);
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
    .workspace-header {
      position: sticky;
      top: 0;
      z-index: 1030;
      padding: 1rem 0 0.75rem;
      background: linear-gradient(180deg, rgba(246, 242, 235, 0.96), rgba(246, 242, 235, 0.82));
      backdrop-filter: blur(10px);
    }
    .workspace-header-inner {
      max-width: var(--shell-max);
      margin: 0 auto;
      padding: 0 0.75rem;
    }
    .workspace-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 1rem 1.2rem;
      border: 1px solid rgba(31, 41, 51, 0.08);
      border-radius: 1.25rem;
      background: rgba(255, 255, 255, 0.94);
      box-shadow: 0 14px 30px rgba(91, 67, 52, 0.08);
    }
    .workspace-brand {
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
    }
    .workspace-brand strong {
      font-size: 1.05rem;
      line-height: 1.1;
    }
    .workspace-shell {
      max-width: var(--shell-max);
    }
    .workspace-links {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.6rem;
    }
    .workspace-links a {
      padding: 0.25rem 0;
      text-decoration: none;
      color: #7f3e24;
      font-size: 0.95rem;
      font-weight: 600;
      transition: color 0.2s ease;
    }
    .workspace-links a:hover {
      color: #5d2d1c;
    }
    .workspace-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.6rem;
      flex-wrap: wrap;
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
    .workspace-actions .btn {
      border-radius: 999px;
      font-weight: 600;
      padding-inline: 1rem;
    }
    .workspace-actions .btn-light {
      color: #fff;
      background-color: var(--accent);
      border-color: var(--accent);
    }
    .workspace-actions .btn-light:hover,
    .workspace-actions .btn-light:focus {
      color: #fff;
      background-color: var(--accent-dark);
      border-color: var(--accent-dark);
    }
    .workspace-actions .btn-outline-light {
      color: #364152;
      border-color: rgba(54, 65, 82, 0.18);
      background: rgba(255, 255, 255, 0.7);
    }
    .workspace-actions .btn-outline-light:hover,
    .workspace-actions .btn-outline-light:focus {
      color: #1f2933;
      background: rgba(255, 255, 255, 0.95);
      border-color: rgba(54, 65, 82, 0.28);
    }
    .workspace-actions .btn-outline-warning {
      color: var(--accent-dark);
      border-color: rgba(147, 60, 29, 0.25);
      background: rgba(185, 80, 42, 0.08);
    }
    .workspace-actions .btn-outline-warning:hover,
    .workspace-actions .btn-outline-warning:focus {
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
      .workspace-bar {
        align-items: flex-start;
        flex-direction: column;
      }
      .workspace-actions {
        justify-content: flex-start;
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
      .workspace-header-inner {
        padding: 0 0.5rem;
      }
      .workspace-bar {
        padding: 1rem;
      }
      .workspace-actions .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <header class="workspace-header">
    <div class="workspace-header-inner">
      <div class="workspace-bar">
        <div class="workspace-brand">
          <strong>Admin Workspace</strong>
          <span class="mini-muted">Simple controls for users and cancellation review</span>
          <div class="workspace-links">
            <a href="#overview">Overview</a>
            <a href="#cancellations">Cancellation Audit</a>
            <a href="#directory">User Directory</a>
          </div>
        </div>
        <div class="workspace-actions">
          <a href="organizer_profile.php" class="btn btn-light">Back to Dashboard</a>
          <a href="events.html" class="btn btn-outline-light">Events</a>
          <a href="logout.php" class="btn btn-outline-warning">Log Out</a>
        </div>
      </div>
    </div>
  </header>

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
            <div class="stat-card">
              <span class="mini-muted">Cancelled Events</span>
              <strong><?= $cancelledEventsCount ?></strong>
            </div>
            <div class="stat-card">
              <span class="mini-muted">Legacy / Unattributed</span>
              <strong><?= $unattributedCancelledEventsCount ?></strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="table-card mb-4" id="cancellations">
      <div class="toolbar">
        <div>
          <h2 class="h4 mb-1">Cancellation Audit</h2>
          <p class="mini-muted mb-0">Track which organizer cancelled which event so unusual activity stands out early.</p>
        </div>
      </div>

      <div class="row g-4 mb-3">
        <div class="col-lg-4">
            <div class="hero-card h-100 p-3">
              <div class="fw-semibold mb-2">Organizers with cancellations</div>
            <?php if (count($cancellationLeaders) === 0): ?>
              <div class="mini-muted">
                <?= $cancelledEventsCount > 0
                  ? 'Cancelled events exist, but none can be confidently attributed to one organizer yet.'
                  : 'No organizer has cancelled an event yet.' ?>
              </div>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach ($cancellationLeaders as $leader): ?>
                  <div class="d-flex justify-content-between align-items-start gap-3 py-2 border-bottom">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']) ?></div>
                      <div class="mini-muted">Organizer #<?= (int) $leader['organizer_id'] ?> - <?= htmlspecialchars($leader['email']) ?></div>
                    </div>
                    <span class="admin-pill"><?= (int) $leader['cancelled_events'] ?> cancelled</span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="table-wrap">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Event</th>
                  <th>Cancelled By</th>
                  <th>When</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($cancelledEventsAudit) === 0): ?>
                  <tr><td colspan="4" class="mini-muted">No cancelled events recorded yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($cancelledEventsAudit as $audit): ?>
                    <tr>
                      <td>
                        <div class="fw-semibold"><?= htmlspecialchars($audit['title']) ?></div>
                        <div class="mini-muted">Event #<?= (int) $audit['event_id'] ?> - <?= htmlspecialchars($audit['event_date']) ?></div>
                      </td>
                      <td>
                        <div class="fw-semibold">
                          <?= htmlspecialchars(trim(($audit['first_name'] ?? '') . ' ' . ($audit['last_name'] ?? '')) ?: 'Legacy cancellation') ?>
                        </div>
                        <div class="mini-muted">
                          <?php if (!empty($audit['organizer_id'])): ?>
                            Organizer #<?= (int) $audit['organizer_id'] ?>
                          <?php elseif (empty($audit['cancelled_by_organizer_id'])): ?>
                            Not attributable from older data
                          <?php endif; ?>
                          <?php if (!empty($audit['email'])): ?>
                            <?= !empty($audit['organizer_id']) ? ' - ' : '' ?><?= htmlspecialchars($audit['email']) ?>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td><?= htmlspecialchars($audit['cancellation_time'] ?? 'Unknown time') ?></td>
                      <td><?= htmlspecialchars($audit['cancellation_reason'] ?? 'No reason saved') ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
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
