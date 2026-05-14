<?php
require 'db.php';
require_once 'session_bootstrap.php';
require_once 'collaboration_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header('Location: login.php');
    exit;
}

ensureCollaborationSchema($pdo);

$user_id = (int)$_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.email, up.bio, up.profile_picture,
               up.social_link1, up.social_link2, up.social_link3,
               o.organizer_id, o.is_admin
        FROM Users u
        JOIN User_Profile up ON u.user_id = up.user_id
        JOIN Organizer o ON u.user_id = o.user_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        die("Profile not found.");
    }

    $organizerId = (int)$profile['organizer_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $bio = $_POST['bio'] ?? null;
        $link1 = $_POST['social_link1'] ?? null;
        $link2 = $_POST['social_link2'] ?? null;
        $link3 = $_POST['social_link3'] ?? null;

        $stmt = $pdo->prepare("
            UPDATE User_Profile
            SET bio = ?, social_link1 = ?, social_link2 = ?, social_link3 = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$bio, $link1, $link2, $link3, $user_id]);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_request'], $_POST['request_id'], $_POST['decision'])) {
        $requestId = (int)$_POST['request_id'];
        $decision = $_POST['decision'] === 'accept' ? 'accepted' : 'declined';

        $pdo->beginTransaction();

        $requestStmt = $pdo->prepare("
            SELECT ecr.request_id, ecr.event_id, ecr.invited_organizer_id, ecr.invited_by_organizer_id, ecr.status,
                   e.title,
                   inviter.user_id AS inviter_user_id,
                   inviter_user.first_name AS inviter_first_name,
                   inviter_user.last_name AS inviter_last_name
            FROM event_collaboration_requests ecr
            JOIN EventDetails e ON e.event_id = ecr.event_id
            JOIN Organizer inviter ON inviter.organizer_id = ecr.invited_by_organizer_id
            JOIN Users inviter_user ON inviter_user.user_id = inviter.user_id
            WHERE ecr.request_id = ? AND ecr.invited_organizer_id = ?
            FOR UPDATE
        ");
        $requestStmt->execute([$requestId, $organizerId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

        if ($request && $request['status'] === 'pending') {
            if ($decision === 'accepted') {
                $linkStmt = $pdo->prepare("
                    INSERT IGNORE INTO create_event (organizer_id, event_id)
                    VALUES (?, ?)
                ");
                $linkStmt->execute([$organizerId, (int)$request['event_id']]);
            }

            $updateRequestStmt = $pdo->prepare("
                UPDATE event_collaboration_requests
                SET status = ?, responded_at = NOW()
                WHERE request_id = ?
            ");
            $updateRequestStmt->execute([$decision, $requestId]);

            $markReadStmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = ? AND related_request_id = ?
            ");
            $markReadStmt->execute([$user_id, $requestId]);

            $actorName = trim($profile['first_name'] . ' ' . $profile['last_name']);
            $decisionWord = $decision === 'accepted' ? 'accepted' : 'declined';

            createNotification(
                $pdo,
                (int)$request['inviter_user_id'],
                'collaboration_response',
                sprintf('%s %s your collaboration request for "%s".', $actorName, $decisionWord, $request['title']),
                (int)$request['event_id'],
                $requestId
            );
        }

        $pdo->commit();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $eventsStmt = $pdo->prepare("
        SELECT e.event_id, e.title, e.event_date, e.location,
               COUNT(b.booking_id) AS booking_count
        FROM EventDetails e
        LEFT JOIN Booking b ON e.event_id = b.event_id
        JOIN create_event ce ON e.event_id = ce.event_id
        WHERE ce.organizer_id = ?
        GROUP BY e.event_id
        ORDER BY e.event_date DESC
    ");
    $eventsStmt->execute([$organizerId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $pendingRequestsStmt = $pdo->prepare("
        SELECT ecr.request_id, ecr.created_at, e.title, e.type, e.event_date, e.location,
               inviter_user.first_name, inviter_user.last_name,
               n.is_read
        FROM event_collaboration_requests ecr
        JOIN EventDetails e ON e.event_id = ecr.event_id
        JOIN Organizer inviter ON inviter.organizer_id = ecr.invited_by_organizer_id
        JOIN Users inviter_user ON inviter_user.user_id = inviter.user_id
        LEFT JOIN notifications n ON n.related_request_id = ecr.request_id AND n.user_id = ?
        WHERE ecr.invited_organizer_id = ? AND ecr.status = 'pending'
        ORDER BY ecr.created_at DESC
    ");
    $pendingRequestsStmt->execute([$user_id, $organizerId]);
    $pendingRequests = $pendingRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $sentRequestsStmt = $pdo->prepare("
        SELECT ecr.request_id, ecr.status, ecr.created_at, ecr.responded_at,
               e.title, invited_user.first_name, invited_user.last_name
        FROM event_collaboration_requests ecr
        JOIN EventDetails e ON e.event_id = ecr.event_id
        JOIN Organizer invited_org ON invited_org.organizer_id = ecr.invited_organizer_id
        JOIN Users invited_user ON invited_user.user_id = invited_org.user_id
        WHERE ecr.invited_by_organizer_id = ?
        ORDER BY ecr.created_at DESC
    ");
    $sentRequestsStmt->execute([$organizerId]);
    $sentRequests = $sentRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $notificationsStmt = $pdo->prepare("
        SELECT notification_id, type, message, is_read, created_at, related_event_id
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $notificationsStmt->execute([$user_id]);
    $notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadCountStmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0
    ");
    $unreadCountStmt->execute([$user_id]);
    $unreadCount = (int)$unreadCountStmt->fetchColumn();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Organizer Profile</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body {
      background: linear-gradient(180deg, #f7f3ee 0%, #fffdfb 100%);
    }
    html {
      scroll-behavior: smooth;
    }
    .dashboard-shell {
      max-width: 1180px;
    }
    .navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
    }
    .profile-nav-links {
      display: flex;
      flex-wrap: wrap;
      gap: 0.55rem;
      margin-top: 0.85rem;
    }
    .profile-nav-links a {
      padding: 0.45rem 0.85rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.12);
      color: #fff;
      text-decoration: none;
      font-size: 0.92rem;
    }
    .profile-nav-links a:hover {
      background: rgba(255, 255, 255, 0.22);
    }
    #pfp-display {
      width: 150px;
      height: 150px;
      object-fit: cover;
      border-radius: 50%;
      border: 3px solid #f0ddd4;
      margin-bottom: 1rem;
      box-shadow: 0 14px 28px rgba(84, 52, 37, 0.12);
    }
    .admin-badge {
      background-color: #dc3545;
      color: white;
      font-size: 0.75rem;
      padding: 0.2em 0.5em;
      border-radius: 0.25rem;
      margin-left: 0.5rem;
    }
    .section-card,
    .profile-card,
    .stat-card {
      border: 1px solid rgba(33, 29, 25, 0.08);
      border-radius: 1.25rem;
      background: rgba(255, 255, 255, 0.92);
      box-shadow: 0 16px 34px rgba(84, 52, 37, 0.08);
    }
    .profile-card {
      padding: 1.5rem;
      height: 100%;
    }
    .section-card {
      padding: 1.35rem;
    }
    .stat-card {
      padding: 1rem 1.1rem;
    }
    .stat-card strong {
      display: block;
      font-size: 1.6rem;
      line-height: 1;
      color: #c45b33;
    }
    .profile-info label {
      font-weight: 700;
      display: block;
      margin-bottom: 0.35rem;
    }
    .profile-info p,
    .profile-info .links-box {
      background: #f8f5f1;
      padding: 0.75rem 0.9rem;
      border-radius: 0.85rem;
      white-space: pre-wrap;
      margin-bottom: 0;
    }
    .request-card,
    .notification-card,
    .invite-card {
      border: 1px solid rgba(33, 29, 25, 0.08);
      border-radius: 1rem;
      background: #fffdfa;
      padding: 1rem;
    }
    .request-card.unread {
      border-color: rgba(217, 104, 65, 0.32);
      background: #fff6f1;
    }
    .notification-card.unread {
      background: #fff8f3;
      border-color: rgba(217, 104, 65, 0.26);
    }
    .mini-muted {
      color: #6f665e;
      font-size: 0.94rem;
    }
    .section-title {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1rem;
    }
    .list-stack {
      display: grid;
      gap: 0.9rem;
    }
    .table-wrap {
      overflow-x: auto;
    }
    .table thead th {
      white-space: nowrap;
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-dark px-4 py-3">
    <div class="container-fluid px-0">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 w-100">
        <div>
          <a class="navbar-brand mb-0">Organizer Dashboard</a>
          <div class="profile-nav-links">
            <a href="#profile-overview">Profile</a>
            <a href="#collaboration-requests">Requests</a>
            <a href="#notifications">Notifications</a>
            <a href="#sent-invitations">Sent Invites</a>
            <a href="#my-events">My Events</a>
          </div>
        </div>
        <div>
          <a href="create_event.html" class="btn btn-light me-2">Create Event</a>
      <?php if ((int)$profile['is_admin'] === 1): ?>
          <a href="manage_users.php" class="btn btn-warning me-2">Manage Users</a>
      <?php endif; ?>
          <a href="logout.php" class="btn btn-outline-light">Log Out</a>
        </div>
      </div>
    </div>
  </nav>

  <div class="container dashboard-shell my-4" id="profile-overview">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
      <div>
        <h2 class="mb-1">
          My Profile
          <?php if ((int)$profile['is_admin'] === 1): ?>
            <span class="admin-badge">Admin</span>
          <?php endif; ?>
        </h2>
        <p class="text-muted mb-0">Accepted collaborations appear in your events table only after you accept the request.</p>
      </div>
      <div class="d-flex gap-3 flex-wrap">
        <div class="stat-card">
          <span class="mini-muted">My Events</span>
          <strong><?= count($events) ?></strong>
        </div>
        <div class="stat-card">
          <span class="mini-muted">Pending Requests</span>
          <strong><?= count($pendingRequests) ?></strong>
        </div>
        <div class="stat-card">
          <span class="mini-muted">Unread Notifications</span>
          <strong><?= $unreadCount ?></strong>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="profile-card text-center">
          <img id="pfp-display" src="<?= htmlspecialchars($profile['profile_picture'] ?: 'https://via.placeholder.com/150') ?>" alt="Profile Picture" />
          <form action="upload_profile_pic.php" method="POST" enctype="multipart/form-data" class="mt-2">
            <input type="file" name="profile_pic" accept="image/*" class="form-control" required />
            <button type="submit" class="btn btn-primary mt-2 w-100">Upload</button>
          </form>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="profile-card profile-info">
          <div class="mb-3">
            <label>Name</label>
            <p><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></p>
          </div>
          <div class="mb-3">
            <label>Email</label>
            <p><?= htmlspecialchars($profile['email']) ?></p>
          </div>
          <div class="mb-3">
            <label>Bio</label>
            <p id="bio-display"><?= nl2br(htmlspecialchars($profile['bio'] ?: 'No bio provided.')) ?></p>
          </div>
          <div class="mb-3">
            <label>Website / Social Links</label>
            <div class="links-box">
              <ul class="mb-0 ps-3">
                <?php if ($profile['social_link1']): ?>
                  <li><a href="<?= htmlspecialchars($profile['social_link1']) ?>" target="_blank"><?= htmlspecialchars($profile['social_link1']) ?></a></li>
                <?php endif; ?>
                <?php if ($profile['social_link2']): ?>
                  <li><a href="<?= htmlspecialchars($profile['social_link2']) ?>" target="_blank"><?= htmlspecialchars($profile['social_link2']) ?></a></li>
                <?php endif; ?>
                <?php if ($profile['social_link3']): ?>
                  <li><a href="<?= htmlspecialchars($profile['social_link3']) ?>" target="_blank"><?= htmlspecialchars($profile['social_link3']) ?></a></li>
                <?php endif; ?>
                <?php if (!$profile['social_link1'] && !$profile['social_link2'] && !$profile['social_link3']): ?>
                  <li>No links provided.</li>
                <?php endif; ?>
              </ul>
            </div>
          </div>

          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
            Edit Profile
          </button>
        </div>
      </div>
    </div>

    <div class="row g-4 mt-1">
      <div class="col-lg-7">
        <div class="section-card h-100" id="collaboration-requests">
          <div class="section-title">
            <div>
              <h4 class="mb-1">Collaboration Requests</h4>
              <p class="mini-muted mb-0">Accept a request to make that event appear in your organizer events list.</p>
            </div>
            <span class="badge text-bg-dark rounded-pill"><?= count($pendingRequests) ?> pending</span>
          </div>

          <div class="list-stack">
            <?php if (count($pendingRequests) === 0): ?>
              <div class="request-card">
                <div class="mini-muted">No pending collaboration requests right now.</div>
              </div>
            <?php else: ?>
              <?php foreach ($pendingRequests as $request): ?>
                <div class="request-card <?= ((int)($request['is_read'] ?? 0) === 0) ? 'unread' : '' ?>">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                      <h5 class="mb-1"><?= htmlspecialchars($request['title']) ?></h5>
                      <div class="mini-muted mb-2">
                        Invited by <?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>
                        - <?= htmlspecialchars($request['type']) ?>
                        - <?= htmlspecialchars($request['event_date']) ?>
                        - <?= htmlspecialchars($request['location']) ?>
                      </div>
                    </div>
                    <span class="badge text-bg-warning">Pending</span>
                  </div>
                  <form method="POST" class="d-flex gap-2 flex-wrap mt-2">
                    <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>" />
                    <input type="hidden" name="respond_request" value="1" />
                    <button type="submit" name="decision" value="accept" class="btn btn-success btn-sm">Accept</button>
                    <button type="submit" name="decision" value="decline" class="btn btn-outline-secondary btn-sm">Decline</button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="section-card h-100" id="notifications">
          <div class="section-title">
            <div>
              <h4 class="mb-1">Notifications</h4>
              <p class="mini-muted mb-0">Recent activity for collaboration requests and responses.</p>
            </div>
            <span class="badge text-bg-primary rounded-pill"><?= $unreadCount ?> unread</span>
          </div>

          <div class="list-stack">
            <?php if (count($notifications) === 0): ?>
              <div class="notification-card">
                <div class="mini-muted">No notifications yet.</div>
              </div>
            <?php else: ?>
              <?php foreach ($notifications as $notification): ?>
                <div class="notification-card <?= ((int)$notification['is_read'] === 0) ? 'unread' : '' ?>">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($notification['message']) ?></div>
                      <div class="mini-muted mt-1"><?= htmlspecialchars($notification['created_at']) ?></div>
                    </div>
                    <?php if ((int)$notification['is_read'] === 0): ?>
                      <span class="badge text-bg-danger">New</span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4 mt-1">
      <div class="col-lg-12">
        <div class="section-card" id="sent-invitations">
          <div class="section-title">
            <div>
              <h4 class="mb-1">Invitations You Sent</h4>
              <p class="mini-muted mb-0">Track who has accepted, declined, or not answered yet.</p>
            </div>
          </div>

          <div class="list-stack">
            <?php if (count($sentRequests) === 0): ?>
              <div class="invite-card">
                <div class="mini-muted">You have not invited any collaborators yet.</div>
              </div>
            <?php else: ?>
              <?php foreach ($sentRequests as $invite): ?>
                <div class="invite-card d-flex justify-content-between align-items-start gap-3 flex-wrap">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($invite['title']) ?></div>
                    <div class="mini-muted">
                      Sent to <?= htmlspecialchars($invite['first_name'] . ' ' . $invite['last_name']) ?>
                      - <?= htmlspecialchars($invite['created_at']) ?>
                    </div>
                  </div>
                  <span class="badge <?= $invite['status'] === 'accepted' ? 'text-bg-success' : ($invite['status'] === 'declined' ? 'text-bg-secondary' : 'text-bg-warning') ?>">
                    <?= htmlspecialchars(ucfirst($invite['status'])) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="section-card mt-4" id="my-events">
      <div class="section-title">
        <div>
          <h4 class="mb-1">My Events & Booking Counts</h4>
          <p class="mini-muted mb-0">This list contains your own events plus collaborations you already accepted.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table table-striped mt-2 mb-0">
          <thead>
            <tr>
              <th>Event Name</th>
              <th>Date</th>
              <th>Location</th>
              <th>Total Bookings</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($events) === 0): ?>
              <tr><td colspan="4">No accepted events found.</td></tr>
            <?php else: ?>
              <?php foreach ($events as $event): ?>
                <tr>
                  <td><?= htmlspecialchars($event['title']) ?></td>
                  <td><?= htmlspecialchars($event['event_date']) ?></td>
                  <td><?= htmlspecialchars($event['location']) ?></td>
                  <td><?= htmlspecialchars($event['booking_count']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST" novalidate>
          <div class="modal-header">
            <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="bio" class="form-label">Bio</label>
              <textarea id="bio" name="bio" class="form-control" rows="4"><?= htmlspecialchars($profile['bio']) ?></textarea>
            </div>
            <div class="mb-3">
              <label for="social_link1" class="form-label">Link 1</label>
              <input type="url" id="social_link1" name="social_link1" class="form-control" value="<?= htmlspecialchars($profile['social_link1']) ?>" />
            </div>
            <div class="mb-3">
              <label for="social_link2" class="form-label">Link 2</label>
              <input type="url" id="social_link2" name="social_link2" class="form-control" value="<?= htmlspecialchars($profile['social_link2']) ?>" />
            </div>
            <div class="mb-3">
              <label for="social_link3" class="form-label">Link 3</label>
              <input type="url" id="social_link3" name="social_link3" class="form-control" value="<?= htmlspecialchars($profile['social_link3']) ?>" />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="update_profile" class="btn btn-success">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
