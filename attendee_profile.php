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

        // After update, reload the page (POST-Redirect-GET pattern)
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Attendee Profile</title>
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
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-dark px-4">
    <a class="navbar-brand">Attendee Dashboard</a>
    <div>
      <a href="events.html" class="btn btn-light me-2">Check New Events</a>
      <a href="my_bookings.html?attendee_id=<?= urlencode($profile['attendee_id']) ?>" class="btn btn-light me-2">My Bookings</a>
      <a href="logout.php" class="btn btn-outline-light">Log Out</a>
    </div>
  </nav>

  <div class="container mt-4">
    <h2>My Profile</h2>

    <div class="text-center mb-4">
      <img id="pfp-display" src="<?= htmlspecialchars($profile['profile_picture'] ?? 'https://via.placeholder.com/150') ?>" alt="Profile Picture" />
      <form action="upload_profile_pic.php" method="POST" enctype="multipart/form-data" class="mt-2 d-inline-block">
        <input type="file" name="profile_pic" accept="image/*" class="form-control" required style="display:inline-block; width:auto;" />
        <button type="submit" class="btn btn-primary mt-2">Upload</button>
      </form>
    </div>

    <div class="mb-3">
      <label class="form-label"><strong>Name:</strong></label>
      <p><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></p>
    </div>

    <div class="mb-3">
      <label class="form-label"><strong>Email:</strong></label>
      <p><?= htmlspecialchars($profile['email']) ?></p>
    </div>

    <div class="mb-3">
      <label class="form-label"><strong>Bio:</strong></label>
      <p><?= nl2br(htmlspecialchars($profile['bio'])) ?: '<em>No bio set.</em>' ?></p>
    </div>

    <div class="mb-3">
      <label class="form-label"><strong>Website / Social Links:</strong></label>
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
          <li><em>No social links set.</em></li>
        <?php endif; ?>
      </ul>
    </div>

    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
      Edit Profile
    </button>

    <div class="mt-4">
      <p><strong>Total Bookings:</strong> <?= $profile['total_bookings'] ?></p>
    </div>
  </div>

  <!-- Edit Profile Modal -->
  <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <form method="POST" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="bio" class="form-label">Bio</label>
            <textarea name="bio" id="bio" class="form-control" rows="3"><?= htmlspecialchars($profile['bio']) ?></textarea>
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
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_profile" class="btn btn-success">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
