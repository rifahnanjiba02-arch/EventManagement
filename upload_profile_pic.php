<?php
// upload_profile_pic.php
require_once 'session_bootstrap.php';
require 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if file was uploaded correctly
if (!isset($_FILES['profile_pic']) || $_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
    // Optional: Redirect back with error message (via session or query param)
    header('Location: error.php?msg=Upload failed');
    exit;
}

// Prepare upload directory
$uploadDir = 'uploads/profile_pics/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Validate file extension
$fileTmp = $_FILES['profile_pic']['tmp_name'];
$fileName = basename($_FILES['profile_pic']['name']);
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif'];

if (!in_array($ext, $allowed)) {
    header('Location: error.php?msg=Invalid file type');
    exit;
}

// Create unique filename
$newFileName = $user_id . '_' . time() . '.' . $ext;
$target = $uploadDir . $newFileName;

// Move uploaded file and update database
if (move_uploaded_file($fileTmp, $target)) {
    $stmt = $pdo->prepare("UPDATE user_profile SET profile_picture = ? WHERE user_id = ?");
    $stmt->execute([$target, $user_id]);

    // Redirect to appropriate profile page based on role
    $role = $_SESSION['role'] ?? '';
    if ($role === 'organizer') {
        header('Location: organizer_profile.php');
    } elseif ($role === 'attendee') {
        header('Location: attendee_profile.php');
    } else {
        header('Location: login.php');
    }
    exit;
} else {
    header('Location: error.php?msg=Failed to move file');
    exit;
}
?>
