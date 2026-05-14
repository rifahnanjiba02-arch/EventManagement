<?php
require('db.php');
require_once 'session_bootstrap.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Handle bio and links update
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

        // After update, redirect to avoid form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Fetch profile info
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

    // Fetch organizer's events with booking counts
    $stmt = $pdo->prepare("
        SELECT e.event_id, e.title, e.event_date, e.location,
               COUNT(b.booking_id) AS booking_count
        FROM EventDetails e
        LEFT JOIN Booking b ON e.event_id = b.event_id
        JOIN create_event ce ON e.event_id = ce.event_id
        WHERE ce.organizer_id = ?
        GROUP BY e.event_id
        ORDER BY e.event_date DESC
    ");
    $stmt->execute([$profile['organizer_id']]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Organizer Profile</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    #pfp-display {
      width: 150px;
      height: 150px;
      object-fit: cover;
      border-radius: 50%;
      border: 2px solid #ddd;
      margin-bottom: 1rem;
    }
    .admin-badge {
      background-color: #dc3545;
      color: white;
      font-size: 0.75rem;
      padding: 0.2em 0.5em;
      border-radius: 0.25rem;
      margin-left: 0.5rem;
    }
    .profile-info label {
      font-weight: 600;
    }
    .profile-info p {
      background: #f8f9fa;
      padding: 0.5rem;
      border-radius: 0.25rem;
      white-space: pre-wrap;
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-dark px-4 d-flex justify-content-between align-items-center">
    <a class="navbar-brand">Organizer Dashboard</a>
    <div>
      <a href="create_event.html" class="btn btn-light me-2">Create Event</a>
      <?php if ($profile['is_admin'] == 1): ?>
        <a href="manage_users.php" class="btn btn-warning me-2">Manage Users</a>
      <?php endif; ?>
      <a href="logout.php" class="btn btn-outline-light">Log Out</a>
    </div>
  </nav>

  <div class="container mt-4">
    <h2>
      My Profile
      <?php if ($profile['is_admin'] == 1): ?>
        <span class="admin-badge">Admin</span>
      <?php endif; ?>
    </h2>

    <div class="row mt-4">
      <!-- Profile Picture -->
      <div class="col-md-4 text-center">
        <img id="pfp-display" src="<?= htmlspecialchars($profile['profile_picture'] ?? 'https://via.placeholder.com/150') ?>" alt="Profile Picture" />
        <form action="upload_profile_pic.php" method="POST" enctype="multipart/form-data" class="mt-2">
          <input type="file" name="profile_pic" accept="image/*" class="form-control" required />
          <button type="submit" class="btn btn-primary mt-2 w-100">Upload</button>
        </form>
      </div>

      <!-- Display Bio + Links with Edit Button -->
      <div class="col-md-8 profile-info">
        <div class="mb-3">
          <label>Name:</label>
          <p><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></p>
        </div>
        <div class="mb-3">
          <label>Email:</label>
          <p><?= htmlspecialchars($profile['email']) ?></p>
        </div>
        <div class="mb-3">
          <label>Bio:</label>
          <p id="bio-display"><?= nl2br(htmlspecialchars($profile['bio'] ?: 'No bio provided.')) ?></p>
        </div>
        <div class="mb-3">
          <label>Website / Social Links:</label>
          <ul>
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

        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
          Edit Profile
        </button>
      </div>
    </div>

    <!-- Events Section -->
    <h3 class="mt-5">My Events & Booking Counts</h3>
    <table class="table table-striped mt-3">
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
          <tr><td colspan="4">No events found.</td></tr>
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

  <!-- Edit Profile Modal -->
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
