<?php
require('db.php');
require_once 'session_bootstrap.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'attendee') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

try {
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

    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.email,
               up.bio, up.profile_picture, up.social_link1, up.social_link2, up.social_link3,
               a.attendee_id,
               COALESCE(COUNT(b.booking_id), 0) AS total_bookings
        FROM Users u
        LEFT JOIN User_Profile up ON u.user_id = up.user_id
        JOIN Attendee a ON u.user_id = a.user_id
        LEFT JOIN Booking b ON a.attendee_id = b.attendee_id
        WHERE u.user_id = ?
        GROUP BY u.user_id, a.attendee_id
    ");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        die("Profile not found.");
    }
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$profileImage = $profile['profile_picture'] ?: 'https://via.placeholder.com/150';
$socialLinks = array_values(array_filter([
    $profile['social_link1'] ?? '',
    $profile['social_link2'] ?? '',
    $profile['social_link3'] ?? '',
]));
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Attendee Profile</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    :root {
      --shell-max: 1180px;
      --ink: #1f2933;
      --muted: #6c757d;
      --line: rgba(31, 41, 51, 0.1);
      --surface: rgba(255, 255, 255, 0.94);
      --surface-soft: #f8f5ef;
      --accent: #c45b33;
      --accent-deep: #9f4524;
      --hero-dark: #17212b;
    }
    html {
      scroll-behavior: smooth;
    }
    body {
      min-height: 100vh;
      color: var(--ink);
      background:
        radial-gradient(circle at top right, rgba(196, 91, 51, 0.12), transparent 24%),
        linear-gradient(180deg, #f7f2ea 0%, #fffdf9 100%);
    }
    .navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
      background: linear-gradient(90deg, #24313f 0%, #2f4052 100%) !important;
      box-shadow: 0 10px 24px rgba(31, 41, 51, 0.14);
    }
    .nav-panel {
      padding: 0.85rem 0;
    }
    .navbar-brand {
      color: #fff !important;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    .dashboard-shell {
      max-width: var(--shell-max);
    }
    .dashboard-links {
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem;
      margin-top: 0.85rem;
    }
    .dashboard-links a {
      padding: 0.4rem 0.8rem;
      border-radius: 999px;
      text-decoration: none;
      background: rgba(255, 255, 255, 0.08);
      color: #f6dfd2;
      font-size: 0.88rem;
      font-weight: 600;
      transition: background-color 0.2s ease, transform 0.2s ease;
    }
    .dashboard-links a:hover {
      background: rgba(255, 255, 255, 0.16);
      transform: translateY(-1px);
    }
    .hero-card,
    .profile-card,
    .section-card,
    .stat-card,
    .upload-card {
      border: 1px solid var(--line);
      border-radius: 1.3rem;
      background: var(--surface);
      box-shadow: 0 18px 36px rgba(91, 67, 52, 0.08);
    }
    .hero-card,
    .profile-card,
    .upload-card,
    .section-card {
      padding: 1.4rem;
    }
    .profile-card,
    .upload-card,
    .section-card {
      overflow: hidden;
    }
    .hero-card {
      background:
        linear-gradient(135deg, rgba(196, 91, 51, 0.12), rgba(255, 255, 255, 0.96)),
        rgba(255, 255, 255, 0.95);
      overflow: hidden;
    }
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem;
    }
    .stat-card {
      padding: 1rem 1.1rem;
    }
    .stat-card strong {
      display: block;
      font-size: 1.7rem;
      line-height: 1;
      color: var(--accent);
    }
    .eyebrow,
    .mini-muted {
      color: var(--muted);
      font-size: 0.94rem;
    }
    .avatar {
      width: 158px;
      height: 158px;
      object-fit: cover;
      border-radius: 50%;
      border: 3px solid #f0ddd4;
      box-shadow: 0 14px 28px rgba(84, 52, 37, 0.12);
      background: #fffdfa;
    }
    .avatar-fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 3rem;
      font-weight: 700;
      color: var(--accent);
      background: #fffdfa;
    }
    .profile-section-label {
      display: block;
      margin-bottom: 0.45rem;
      font-weight: 700;
    }
    .info-box,
    .links-box {
      background: var(--surface-soft);
      border-radius: 1rem;
      padding: 0.7rem 0.85rem;
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.45;
    }
    .links-list {
      margin: 0;
      padding-left: 1.1rem;
    }
    .links-list li + li {
      margin-top: 0.45rem;
    }
    .quick-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }
    .section-title {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: 0.8rem;
    }
    .profile-fields {
      display: grid;
      grid-template-columns: 1fr;
      gap: 0.7rem;
    }
    .badge-soft {
      display: inline-flex;
      align-items: center;
      padding: 0.4rem 0.8rem;
      border-radius: 999px;
      background: rgba(196, 91, 51, 0.12);
      color: var(--accent-deep);
      font-weight: 600;
    }
    .form-control,
    .form-control:focus {
      border-radius: 0.9rem;
    }
    .navbar .btn {
      border-radius: 999px;
      font-weight: 600;
      padding-inline: 0.95rem;
    }
    .navbar .btn-light {
      color: #fff;
      background-color: var(--accent);
      border-color: var(--accent);
    }
    .navbar .btn-light:hover,
    .navbar .btn-light:focus {
      color: #fff;
      background-color: var(--accent-deep);
      border-color: var(--accent-deep);
    }
    .navbar .btn-outline-light {
      color: #fff7f2;
      border-color: rgba(255, 255, 255, 0.2);
      background: rgba(255, 255, 255, 0.06);
    }
    .navbar .btn-outline-light:hover,
    .navbar .btn-outline-light:focus {
      color: #1f2933;
      background: #fff;
      border-color: #fff;
    }
    .navbar .btn-outline-warning {
      color: #ffd9c8;
      border-color: rgba(255, 217, 200, 0.28);
      background: rgba(255, 217, 200, 0.06);
    }
    .navbar .btn-outline-warning:hover,
    .navbar .btn-outline-warning:focus {
      color: #1f2933;
      background: #ffd9c8;
      border-color: #ffd9c8;
    }
    .detail-item {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .detail-item .profile-section-label {
      margin-bottom: 0.3rem;
    }
    .info-box.compact-box {
      padding: 0.8rem 0.9rem;
    }
    .btn-primary {
      background-color: var(--accent);
      border-color: var(--accent);
    }
    .btn-primary:hover,
    .btn-primary:focus {
      background-color: var(--accent-deep);
      border-color: var(--accent-deep);
    }
    @media (max-width: 991.98px) {
      .nav-panel > .d-flex {
        align-items: flex-start !important;
      }
    }
    @media (max-width: 767.98px) {
      .hero-card,
      .profile-card,
      .upload-card,
      .section-card {
        padding: 1.15rem;
      }
      .nav-panel {
        padding: 0.75rem 0;
      }
      .avatar {
        width: 132px;
        height: 132px;
      }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark px-4 py-3">
    <div class="container-fluid px-0 nav-panel">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 w-100">
        <div>
          <a class="navbar-brand mb-0">Attendee Dashboard</a>
          <div class="dashboard-links">
            <a href="#profile-overview">Profile</a>
            <a href="#activity-overview">Activity</a>
            <a href="#profile-actions">Actions</a>
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="index.html" class="btn btn-outline-light">Home</a>
          <a href="events.html" class="btn btn-light">Browse Events</a>
          <a href="my_bookings.html" class="btn btn-outline-light">My Bookings</a>
          <a href="logout.php" class="btn btn-outline-warning">Log Out</a>
        </div>
      </div>
    </div>
  </nav>

  <div class="container dashboard-shell my-4" id="profile-overview">
    <div class="hero-card mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-7">
          <div class="eyebrow mb-2">Your event space</div>
          <h1 class="h2 mb-2">Welcome back, <?= htmlspecialchars($profile['first_name']) ?></h1>
          <p class="text-muted mb-0">Keep your attendee details polished, review your booking activity, and jump back into upcoming events from one clean workspace.</p>
        </div>
        <div class="col-lg-5">
          <div class="stat-grid">
            <div class="stat-card">
              <span class="mini-muted">Total Bookings</span>
              <strong><?= (int)$profile['total_bookings'] ?></strong>
            </div>
            <div class="stat-card">
              <span class="mini-muted">Profile Links</span>
              <strong><?= count($socialLinks) ?></strong>
            </div>
            <div class="stat-card">
              <span class="mini-muted">Role</span>
              <strong>1</strong>
              <span class="mini-muted">Attendee account</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="upload-card text-center h-100">
          <?php if (!empty($profile['profile_picture'])): ?>
            <img class="avatar mb-3" src="<?= htmlspecialchars($profileImage) ?>" alt="Profile Picture" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
            <div class="avatar avatar-fallback mb-3 mx-auto" style="display:none;"><?= htmlspecialchars(strtoupper(substr($profile['first_name'], 0, 1))) ?></div>
          <?php else: ?>
            <div class="avatar avatar-fallback mb-3 mx-auto"><?= htmlspecialchars(strtoupper(substr($profile['first_name'], 0, 1))) ?></div>
          <?php endif; ?>
          <h2 class="h5 mb-1"><?= htmlspecialchars($fullName) ?></h2>
          <p class="mini-muted mb-3">Profile photo and identity</p>
          <form action="upload_profile_pic.php" method="POST" enctype="multipart/form-data" class="d-grid gap-2">
            <input type="file" name="profile_pic" accept="image/*" class="form-control" required />
            <button type="submit" class="btn btn-primary">Upload New Photo</button>
          </form>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="profile-card h-100">
          <div class="section-title">
            <div>
              <h2 class="h4 mb-1">Profile Details</h2>
              <p class="mini-muted mb-0">Everything other attendees and organizers need to recognize your account at a glance.</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
              Edit Profile
            </button>
          </div>

          <div class="profile-fields">
            <div class="detail-item">
              <label class="profile-section-label">Full Name</label>
              <div class="info-box"><?= htmlspecialchars($fullName) ?></div>
            </div>
            <div class="detail-item">
              <label class="profile-section-label">Email</label>
              <div class="info-box"><?= htmlspecialchars($profile['email']) ?></div>
            </div>
            <div class="detail-item">
              <label class="profile-section-label">Bio</label>
              <div class="info-box"><?= $profile['bio'] ? nl2br(htmlspecialchars($profile['bio'])) : '<em>No bio set yet.</em>' ?></div>
            </div>
            <div class="detail-item">
              <label class="profile-section-label">Website and Social Links</label>
              <div class="links-box">
                <?php if ($socialLinks): ?>
                  <ul class="links-list">
                    <?php foreach ($socialLinks as $link): ?>
                      <li><a href="<?= htmlspecialchars($link) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($link) ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <em>No social links set.</em>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4 mt-1" id="activity-overview">
      <div class="col-lg-6">
        <div class="section-card h-100">
          <div class="section-title">
            <div>
              <h3 class="h5 mb-1">Booking Snapshot</h3>
              <p class="mini-muted mb-0">A quick read on your current account activity.</p>
            </div>
            <span class="badge-soft"><?= (int)$profile['total_bookings'] ?> bookings</span>
          </div>
          <div class="info-box compact-box">
            <?php if ((int)$profile['total_bookings'] > 0): ?>
              You have booked <?= (int)$profile['total_bookings'] ?> event<?= (int)$profile['total_bookings'] === 1 ? '' : 's' ?> so far. Use the bookings page to review attendance updates or manage any upcoming plans.
            <?php else: ?>
              You have not booked an event yet. Browse the event list to discover new experiences and reserve your first spot.
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-6" id="profile-actions">
        <div class="section-card h-100">
          <div class="section-title">
            <div>
              <h3 class="h5 mb-1">Quick Actions</h3>
              <p class="mini-muted mb-0">Common next steps without digging through navigation.</p>
            </div>
          </div>
          <div class="quick-actions">
            <a href="events.html" class="btn btn-primary">Explore Events</a>
            <a href="my_bookings.html" class="btn btn-outline-secondary">Review Bookings</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="POST" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="bio" class="form-label">Bio</label>
            <textarea name="bio" id="bio" class="form-control" rows="4"><?= htmlspecialchars($profile['bio']) ?></textarea>
          </div>
          <div class="mb-3">
            <label for="social_link1" class="form-label">Social Link 1</label>
            <input type="url" name="social_link1" id="social_link1" class="form-control" value="<?= htmlspecialchars($profile['social_link1']) ?>" />
          </div>
          <div class="mb-3">
            <label for="social_link2" class="form-label">Social Link 2</label>
            <input type="url" name="social_link2" id="social_link2" class="form-control" value="<?= htmlspecialchars($profile['social_link2']) ?>" />
          </div>
          <div class="mb-3">
            <label for="social_link3" class="form-label">Social Link 3</label>
            <input type="url" name="social_link3" id="social_link3" class="form-control" value="<?= htmlspecialchars($profile['social_link3']) ?>" />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
